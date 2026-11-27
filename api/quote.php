<?php
// Live price preview for the booking form. Uses the same calculate_quote() the
// server uses when saving, so the preview always matches the final total.
require __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');

$a = db_one('SELECT * FROM accommodations WHERE id = ? AND is_active = 1', [(int) post('accommodation_id', get('accommodation_id'))]);
if (!$a) {
    echo json_encode(['ok' => false, 'message' => 'Choose an accommodation']);
    exit;
}
$src = is_post() ? $_POST : $_GET;
[$in, $out] = normalize_stay($a, (string) ($src['check_in'] ?? ''), (string) ($src['check_out'] ?? ''));
if (!valid_date($in) || !valid_date($out) || $out <= $in) {
    echo json_encode(['ok' => false, 'message' => $a['type'] === 'overnight_room' ? 'Pick check-in and check-out dates' : 'Pick a date']);
    exit;
}

$quote = calculate_quote(
    $a, $in, $out,
    max(0, (int) ($src['num_adults'] ?? 1)),
    max(0, (int) ($src['num_kids'] ?? 0)),
    !empty($src['include_swimming']),
    (array) ($src['addons'] ?? []),
    (array) ($src['food'] ?? [])
);
$exclude = is_staff() ? ((int) ($src['exclude'] ?? 0) ?: null) : null;

echo json_encode([
    'ok'          => true,
    'available'   => is_available((int) $a['id'], $in, $out, $exclude),
    // [addon_id => how many are still free on the busiest day of this stay]; unlimited add-ons are absent
    'addon_left'  => array_map(fn($s) => $s['left'], addon_stock_left($in, $out, $exclude)),
    'items'       => $quote['items'],
    'total'       => $quote['total'],
    'downpayment' => $quote['downpayment'],
    'percent'     => $quote['percent'],
    'units'       => $quote['units'],
]);
