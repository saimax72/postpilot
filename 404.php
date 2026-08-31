<?php
require_once __DIR__ . '/app/bootstrap.php';
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Not found · <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body>
<div class="wrap" style="min-height:100vh;display:grid;place-items:center;text-align:center">
  <div>
    <div style="font-size:5rem;font-weight:800;letter-spacing:-.04em;color:var(--brand)">404</div>
    <h1>That page is not on the calendar</h1>
    <p class="muted">The link may be old, or the page may have moved.</p>
    <a class="btn" href="/dashboard.php">Back to your calendar</a>
  </div>
</div>
</body>
</html>
