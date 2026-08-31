
/**
 * Versioned asset URL. The file's modification time becomes the query string,
 * so a deploy invalidates browser caches on its own - no hard refresh, and no
 * remembering to bump a number by hand.
 */
function asset(string $path): string
{
    $rel  = '/' . ltrim($path, '/');
    $file = dirname(__DIR__) . $rel;
    $v    = is_file($file) ? filemtime($file) : 1;
    return $rel . '?v=' . $v;
}
