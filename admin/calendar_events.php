<?php
// JSON feed for FullCalendar: bookings between ?start and ?end
require __DIR__ . '/../includes/bootstrap.php';
require_staff();
header('Content-Type: application/json');

$start = substr((string) get('start'), 0, 10);
$end = substr((string) get('end'), 0, 10);
if (!valid_date($start) || !valid_date($end)) {
    echo '[]';
    exit;
}
$colors = ['pending' => '#9A5B00', 'confirmed' => '#3D583C', 'checked_in' => '#1E2B1D', 'completed' => '#6B7468'];
$rows = db_all("SELECT b.id, b.booking_code, b.guest_name, b.check_in, b.check_out, b.status, a.name AS unit
                FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
                WHERE b.status IN ('pending','confirmed','checked_in','completed') AND b.check_in < ? AND b.check_out > ?
                ORDER BY a.sort_order", [$end, $start]);
$events = [];
foreach ($rows as $r) {
    $events[] = [
        'title' => $r['unit'] . ' · ' . $r['guest_name'],
        'start' => $r['check_in'],
        'end'   => $r['check_out'], // FullCalendar treats end as exclusive, same as our check_out
        'url'   => url('admin/booking.php?id=' . $r['id']),
        'color' => $colors[$r['status']] ?? '#777',
    ];
}
echo json_encode($events);
