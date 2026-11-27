<?php
// Serves an uploaded payment receipt ONLY to staff or the booking's owner.
// The uploads/receipts folder itself is blocked by .htaccess.
require __DIR__ . '/includes/bootstrap.php';

$p = db_one('SELECT p.proof_path, b.* FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.id = ?', [(int) get('id')]);
if (!$p || !$p['proof_path'] || !can_view_booking($p)) {
    http_response_code(404);
    die('Not found');
}
$file = UPLOADS_DIR . '/receipts/' . basename($p['proof_path']);
if (!is_file($file)) {
    http_response_code(404);
    die('File missing');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: inline; filename="receipt-' . $p['booking_code'] . '.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0');
readfile($file);
