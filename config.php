<?php
// ------------------------------------------------------------
// J'Maire Farm Reservation System - configuration
//
// These defaults work on a standard XAMPP install. To change a value on your own
// computer only, copy config.local.example.php to config.local.php and set it there.
// config.local.php is not committed to git.
// ------------------------------------------------------------

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

defined('DB_HOST') || define('DB_HOST', getenv('JM_DB_HOST') ?: 'localhost');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
defined('DB_NAME') || define('DB_NAME', 'jmaire_farm');
defined('DB_PORT') || define('DB_PORT', 3306);

defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Manila');

// URL path of this folder, e.g. '/j-maire_farm_rsrv_sys'. Leave as null to auto-detect.
defined('BASE_URL') || define('BASE_URL', null);

// Optional: read and save uploads (unit photos, receipts) in another app folder inside
// htdocs, e.g. 'j-maire', so two copies of the system show the same files.
// Empty = use this folder's own uploads/ (the normal setting).
defined('SHARED_APP_FOLDER') || define('SHARED_APP_FOLDER', '');

// Email: XAMPP can't send real emails out of the box. With MAIL_ENABLED = false every
// email is saved to the Email Log (Admin > Email Log) so you can show it in the demo.
// Set to true only after configuring sendmail/SMTP in php.ini.
defined('MAIL_ENABLED') || define('MAIL_ENABLED', false);
defined('MAIL_FROM') || define('MAIL_FROM', 'no-reply@jmairefarm.test');

// Receipts: max upload size in bytes (5 MB)
defined('MAX_UPLOAD_BYTES') || define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);

// Show PHP errors while developing. Set to false for the final demo/deploy.
defined('APP_DEBUG') || define('APP_DEBUG', true);
