<?php
/**
 * PostPilot - configuration
 *
 * Copy this file to config.php and fill in your own values.
 */

// ---------- Database ----------
// hPanel -> Databases -> MySQL Databases. Create the database and user there,
// then paste the three values Hostinger gives you into the lines below.
define('DB_HOST', 'localhost');
define('DB_NAME', 'u779448677_postpilot');   // <-- PASTE the database name Hostinger created
define('DB_USER', 'u779448677_ppuser');      // <-- PASTE the database user
define('DB_PASS', 'PASTE_YOUR_DB_PASSWORD'); // <-- PASTE the password you set
define('DB_CHARSET', 'utf8mb4');

// ---------- App ----------
define('APP_NAME', 'PostPilot');
define('APP_URL', 'https://postpilot.saiberlab.com');  // no trailing slash
define('APP_TIMEZONE', 'UTC');                         // storage timezone, leave as UTC

// 32+ random chars. Encrypts stored social API tokens.
define('APP_KEY', 'GENERATE_A_LONG_RANDOM_STRING');

// Secret required by cron/publish.php when triggered over HTTP.
define('CRON_SECRET', 'GENERATE_ANOTHER_RANDOM_STRING');

// Allow visitors to create their own account.
define('ALLOW_REGISTRATION', true);

// Upload limits
define('UPLOAD_DIR', __DIR__ . '/../uploads');
define('UPLOAD_URL', '/uploads');
define('MAX_UPLOAD_BYTES', 8 * 1024 * 1024); // 8 MB

// Leave true until the site is confirmed working, then set to false.
define('APP_DEBUG', true);
