<?php
/**
 * Media processing - aspect ratios and server-side cropping.
 *
 * The composer's cropper is only a viewfinder: it records which part of the
 * source image the user framed, as fractions of the natural size. The real
 * crop happens here, because every network pulls the image from a URL and
 * applies no cropping of its own - whatever we write to disk is what posts.
 */

function media_ratios(): array
{
    return [
        'square' => [
            'label' => 'Square',  'hint' => '1:1',
            'w' => 1080, 'h' => 1080,
            'note' => 'The safe default. Works on every network.',
        ],
        'portrait' => [
            'label' => 'Portrait', 'hint' => '4:5',
            'w' => 1080, 'h' => 1350,
            'note' => 'Tallest a feed post can go. Takes the most screen space.',
        ],
        'landscape' => [
            'label' => 'Landscape', 'hint' => '1.91:1',
            'w' => 1080, 'h' => 566,
            'note' => 'Wide. Same shape as a link preview card.',
        ],
        'story' => [
            'label' => 'Story / Reel', 'hint' => '9:16',
            'w' => 1080, 'h' => 1920,
            'note' => 'Full screen vertical. Video only on Instagram - a 9:16 still is rejected by the feed.',
        ],
    ];
}

function media_ratio(string $key): ?array
{
    return media_ratios()[$key] ?? null;
}

/** Width divided by height, for laying out the frame in CSS. */
function ratio_value(string $key): float
{
    $r = media_ratio($key) ?? media_ratios()['square'];
    return $r['w'] / $r['h'];
}

function gd_available(): bool
{
    return extension_loaded('gd') && function_exists('imagecreatetruecolor');
}

/**
 * The centred "cover" crop for an image at a given ratio, expressed the same
 * way the composer's cropper expresses it. Bulk upload uses this so fifty
 * images can be framed sensibly without fifty manual passes.
 */
function cover_box(int $natW, int $natH, string $ratioKey): array
{
    $target = ratio_value($ratioKey);          // width / height
    $source = $natW / max(1, $natH);

    if ($source > $target) {
        // Source is wider than the frame: full height, trim the sides.
        $fw = $target / $source;
        return ['fx' => (1 - $fw) / 2, 'fy' => 0.0, 'fw' => $fw, 'fh' => 1.0];
    }

    // Source is taller: full width, trim top and bottom.
    $fh = $source / $target;
    return ['fx' => 0.0, 'fy' => (1 - $fh) / 2, 'fw' => 1.0, 'fh' => $fh];
}

/** Natural pixel size of an uploaded image, or null if it is not readable. */
function image_size(string $relPath): ?array
{
    $abs  = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($relPath, '/');
    $info = is_file($abs) ? @getimagesize($abs) : false;
    return $info ? ['w' => (int)$info[0], 'h' => (int)$info[1]] : null;
}

/**
 * Produce a cropped derivative of an uploaded image.
 *
 * $box holds fx, fy, fw, fh - the framed region as fractions (0..1) of the
 * source image's natural width and height.
 *
 * Returns [true, relativePathOfCrop] or [false, errorMessage]. The original
 * file is left untouched so a post can be re-cropped later.
 */
function crop_media(string $relPath, int $userId, string $ratioKey, array $box): array
{
    if (!gd_available()) {
        // Without GD we cannot rewrite the pixels - fall back to the original.
        return [true, $relPath];
    }
    if (is_video($relPath)) {
        return [true, $relPath];   // video cropping needs ffmpeg; out of scope
    }

    $ratio = media_ratio($ratioKey);
    if (!$ratio) {
        return [false, 'Unknown image ratio.'];
    }

    $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($relPath, '/');
    if (!is_file($abs)) {
        return [false, 'The uploaded image could not be found on disk.'];
    }

    $info = @getimagesize($abs);
    if (!$info) {
        return [false, 'That file is not a readable image.'];
    }

    [$natW, $natH] = $info;
    $src = load_image($abs, $info['mime']);
    if (!$src) {
        return [false, 'That image format cannot be processed.'];
    }

    $src = fix_orientation($src, $abs, $info['mime']);
    $natW = imagesx($src);
    $natH = imagesy($src);

    // Clamp the framed region so it always sits inside the source.
    $fx = min(max((float)($box['fx'] ?? 0), 0), 1);
    $fy = min(max((float)($box['fy'] ?? 0), 0), 1);
    $fw = min(max((float)($box['fw'] ?? 1), 0.01), 1 - $fx);
    $fh = min(max((float)($box['fh'] ?? 1), 0.01), 1 - $fy);

    $sx = (int)round($fx * $natW);
    $sy = (int)round($fy * $natH);
    $sw = max(1, (int)round($fw * $natW));
    $sh = max(1, (int)round($fh * $natH));

    // Never upscale past the source: shrink the output if the crop is small.
    $outW = $ratio['w'];
    $outH = $ratio['h'];
    if ($sw < $outW) {
        $outW = $sw;
        $outH = (int)round($sw * $ratio['h'] / $ratio['w']);
    }

    $dst = imagecreatetruecolor($outW, $outH);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // flatten alpha
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $outW, $outH, $sw, $sh);

    $dir = rtrim(UPLOAD_DIR, '/\\') . '/' . $userId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        imagedestroy($src);
        imagedestroy($dst);
        return [false, 'Upload directory is not writable.'];
    }

    $name = bin2hex(random_bytes(10)) . '_' . $ratioKey . '.jpg';
    $ok   = imagejpeg($dst, $dir . '/' . $name, 90);

    imagedestroy($src);
    imagedestroy($dst);

    if (!$ok) {
        return [false, 'The cropped image could not be saved.'];
    }
    return [true, $userId . '/' . $name];
}

function load_image(string $path, string $mime)
{
    switch ($mime) {
        case 'image/jpeg': return @imagecreatefromjpeg($path);
        case 'image/png':  return @imagecreatefrompng($path);
        case 'image/gif':  return @imagecreatefromgif($path);
        case 'image/webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
        default:           return null;
    }
}

/** Phone photos carry rotation in EXIF; without this they crop sideways. */
function fix_orientation($img, string $path, string $mime)
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($path);
    $o    = $exif['Orientation'] ?? 1;

    if ($o === 3) {
        return imagerotate($img, 180, 0);
    }
    if ($o === 6) {
        return imagerotate($img, -90, 0);
    }
    if ($o === 8) {
        return imagerotate($img, 90, 0);
    }
    return $img;
}

/**
 * Delete a crop derivative that no post references any more.
 * Originals are kept - they are the only way to re-crop.
 */
function discard_crop(?string $relPath, int $userId): void
{
    if (!$relPath || strpos($relPath, $userId . '/') !== 0 || !preg_match('/_(square|portrait|landscape|story)\.jpg$/', $relPath)) {
        return;
    }
    $stillUsed = (int)db_value('SELECT COUNT(*) FROM posts WHERE media_path = ?', [$relPath]);
    if ($stillUsed === 0) {
        @unlink(rtrim(UPLOAD_DIR, '/\\') . '/' . $relPath);
    }
}

/* ---------------- Caption parsing ---------------- */

/** Hashtags in a caption, without the leading #. */
function extract_hashtags(string $text): array
{
    preg_match_all('/(?<![\w&])#([\p{L}\p{N}_]+)/u', $text, $m);
    return array_values(array_unique($m[1] ?? []));
}

function extract_mentions(string $text): array
{
    preg_match_all('/(?<![\w&])@([\p{L}\p{N}_.]+)/u', $text, $m);
    return array_values(array_unique($m[1] ?? []));
}
