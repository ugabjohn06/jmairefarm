<?php
// GET ?accommodation_id=1  -> {"blocked":["2026-11-02", ...], "type":"day_cottage", ...}
require __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');

$a = db_one('SELECT id, name, type, capacity, base_rate FROM accommodations WHERE id = ? AND is_active = 1', [(int) get('accommodation_id')]);
if (!$a) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}
$exclude = (int) get('exclude') ?: null;
echo json_encode([
    'id'       => (int) $a['id'],
    'type'     => $a['type'],
    'capacity' => (int) $a['capacity'],
    'blocked'  => blocked_dates((int) $a['id'], is_staff() ? $exclude : null),
]);
