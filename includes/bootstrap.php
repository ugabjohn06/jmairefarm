<?php
// Loaded at the top of every page: config, session, database, helpers.

require_once __DIR__ . '/../config.php';

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

date_default_timezone_set(APP_TIMEZONE);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

define('APP_ROOT', realpath(__DIR__ . '/..'));

// Shared uploads (see SHARED_APP_FOLDER in config.php)
$sharedUploads = SHARED_APP_FOLDER !== '' ? realpath(APP_ROOT . '/../' . SHARED_APP_FOLDER . '/uploads') : false;
define('UPLOADS_DIR', ($sharedUploads && $sharedUploads !== realpath(APP_ROOT . '/uploads')) ? $sharedUploads : APP_ROOT . '/uploads');
define('UPLOADS_SHARED', UPLOADS_DIR !== APP_ROOT . '/uploads');
unset($sharedUploads);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/booking_service.php';

// Free up dates held by unpaid bookings whose deadline has passed.
// (Also available as cron/expire_bookings.php for Windows Task Scheduler.)
expire_overdue_bookings();

// Older databases have no unit photos set; fill them in once from uploads/accommodations.
apply_bundled_unit_photos();
