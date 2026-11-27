<?php
// Releases dates held by unpaid bookings whose deadline has passed.
// Pages already do this automatically on every visit; this script is for running on a schedule.
//
// Windows Task Scheduler (every 15 minutes):
//   Program:   C:\xampp\php\php.exe
//   Arguments: C:\xampp\htdocs\<this-folder>\cron\expire_bookings.php
// Linux cron:  */15 * * * * php /path/to/<this-folder>/cron/expire_bookings.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run from the command line.');
}
require __DIR__ . '/../includes/bootstrap.php'; // bootstrap already calls expire_overdue_bookings()
echo date('Y-m-d H:i:s') . " expiry check done\n";
