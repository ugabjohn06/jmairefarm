<?php
// ============================================================
// Booking service: all booking business rules live here so the
// guest booking form and the staff walk-in form share the same logic.
//
//   - availability / double-booking prevention   (is_available, create_booking)
//   - price computation                          (calculate_quote)
//   - status flow                                (change_booking_status)
//   - expiry of unpaid bookings                  (expire_overdue_bookings)
//
// Dates use a half-open range [check_in, check_out):
//   overnight rooms: check_out is the day the guest leaves
//   day cottages:    check_out = check_in + 1 day
// ============================================================

const BLOCKING_STATUSES = ['pending', 'confirmed', 'checked_in'];

// ---------- Reading ----------

function get_booking(int $id): ?array
{
    return db_one(booking_select_sql() . ' WHERE b.id = ?', [$id]);
}

function get_booking_by_code(string $code): ?array
{
    return db_one(booking_select_sql() . ' WHERE b.booking_code = ?', [strtoupper(trim($code))]);
}

function booking_select_sql(): string
{
    return 'SELECT b.*, a.name AS accommodation_name, a.type, a.capacity, a.rate_type, a.inclusions,
                   (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.booking_id = b.id AND p.status = \'verified\') AS amount_paid,
                   (SELECT COUNT(*) FROM payments p WHERE p.booking_id = b.id AND p.status = \'pending\') AS pending_payments,
                   cu.name AS created_by_name
            FROM bookings b
            JOIN accommodations a ON a.id = b.accommodation_id
            LEFT JOIN users cu ON cu.id = b.created_by';
}

function booking_items(int $bookingId): array
{
    return db_all('SELECT * FROM booking_items WHERE booking_id = ? ORDER BY FIELD(item_type,\'accommodation\',\'entrance\',\'swimming\',\'addon\',\'food\',\'other\'), id', [$bookingId]);
}

function booking_payments(int $bookingId): array
{
    return db_all('SELECT p.*, v.name AS verified_by_name, r.name AS recorded_by_name
                   FROM payments p
                   LEFT JOIN users v ON v.id = p.verified_by
                   LEFT JOIN users r ON r.id = p.recorded_by
                   WHERE p.booking_id = ? ORDER BY p.paid_at, p.id', [$bookingId]);
}

function balance_due(array $b): float
{
    return max(0, round((float) $b['total_amount'] - (float) $b['amount_paid'], 2));
}

function active_accommodations(?string $type = null): array
{
    if ($type) {
        return db_all('SELECT * FROM accommodations WHERE is_active = 1 AND type = ? ORDER BY sort_order, name', [$type]);
    }
    return db_all('SELECT * FROM accommodations WHERE is_active = 1 ORDER BY sort_order, name');
}

function active_addons(): array
{
    return db_all('SELECT * FROM addons WHERE is_active = 1 ORDER BY name');
}

/** Food items from the existing menu tables, for optional pre-orders. */
function food_menu(): array
{
    return db_all("SELECT pv.id AS variant_id, o.name, pv.variant_label, pv.price, c.name AS category
                   FROM price_variants pv
                   JOIN offerings o ON o.id = pv.offering_id
                   JOIN categories c ON c.id = o.category_id
                   JOIN offering_types ot ON ot.id = c.offering_type_id
                   WHERE ot.name = 'Food' AND pv.price IS NOT NULL
                   ORDER BY c.id, o.name, pv.variant_label");
}

// ---------- Availability ----------

/** True when the unit is free for [checkIn, checkOut). */
function is_available(int $accId, string $checkIn, string $checkOut, ?int $excludeBookingId = null, bool $lock = false): bool
{
    $sql = "SELECT COUNT(*) FROM bookings
            WHERE accommodation_id = ?
              AND status IN ('pending','confirmed','checked_in')
              AND check_in < ? AND check_out > ?";
    $params = [$accId, $checkOut, $checkIn];
    if ($excludeBookingId) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeBookingId;
    }
    if ($lock) $sql .= ' FOR UPDATE';
    return (int) db_value($sql, $params) === 0;
}

/** Every blocked date (Y-m-d) for a unit, used by the guest calendar. */
function blocked_dates(int $accId, ?int $excludeBookingId = null): array
{
    $rows = db_all("SELECT id, check_in, check_out FROM bookings
                    WHERE accommodation_id = ? AND status IN ('pending','confirmed','checked_in')
                      AND check_out > CURDATE()", [$accId]);
    $dates = [];
    foreach ($rows as $r) {
        if ($excludeBookingId && (int) $r['id'] === $excludeBookingId) continue;
        $d = new DateTime($r['check_in']);
        $end = new DateTime($r['check_out']);
        while ($d < $end) {
            $dates[$d->format('Y-m-d')] = true;
            $d->modify('+1 day');
        }
    }
    $dates = array_keys($dates);
    sort($dates);
    return $dates;
}

// ---------- Add-on stock (per day) ----------

/**
 * How many of each add-on live bookings already use on each day of [checkIn, checkOut).
 * An add-on on a booking is in use on every day of that stay (a day cottage is one day).
 * Returns [addon_id => [Y-m-d => quantity]].
 */
function addon_usage(string $checkIn, string $checkOut, ?int $excludeBookingId = null): array
{
    $sql = "SELECT bi.ref_id, bi.quantity, b.check_in, b.check_out
            FROM booking_items bi JOIN bookings b ON b.id = bi.booking_id
            WHERE bi.item_type = 'addon' AND b.status IN ('pending','confirmed','checked_in')
              AND b.check_in < ? AND b.check_out > ?";
    $params = [$checkOut, $checkIn];
    if ($excludeBookingId) {
        $sql .= ' AND b.id <> ?';
        $params[] = $excludeBookingId;
    }
    $usage = [];
    foreach (db_all($sql, $params) as $r) {
        $d = max($r['check_in'], $checkIn);
        $end = min($r['check_out'], $checkOut);
        while ($d < $end) {
            $usage[(int) $r['ref_id']][$d] = ($usage[(int) $r['ref_id']][$d] ?? 0) + (int) $r['quantity'];
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
    }
    return $usage;
}

/**
 * For each add-on with a daily stock: how many are still free on the busiest day of the stay,
 * and which day that is. Unlimited add-ons are left out. Returns [addon_id => ['left' => n, 'date' => Y-m-d]].
 */
function addon_stock_left(string $checkIn, string $checkOut, ?int $excludeBookingId = null): array
{
    $limited = db_all('SELECT id, daily_stock FROM addons WHERE daily_stock IS NOT NULL');
    if (!$limited) return [];
    $usage = addon_usage($checkIn, $checkOut, $excludeBookingId);
    $left = [];
    foreach ($limited as $a) {
        $busiestDay = $checkIn;
        $most = 0;
        foreach ($usage[(int) $a['id']] ?? [] as $day => $q) {
            if ($q > $most) { $most = $q; $busiestDay = $day; }
        }
        $left[(int) $a['id']] = ['left' => max(0, (int) $a['daily_stock'] - $most), 'date' => $busiestDay];
    }
    return $left;
}

/**
 * Errors for any requested add-on that would go over its daily stock. Call inside the booking
 * transaction: it locks the requested add-on rows so two bookings for the last one wait their turn.
 * $addons is [addon_id => quantity].
 */
function addon_stock_errors(array $addons, string $checkIn, string $checkOut, ?int $excludeBookingId = null): array
{
    $addons = array_filter(array_map('intval', $addons), fn($q) => $q > 0);
    if (!$addons) return [];
    $ids = array_keys($addons);
    db_all('SELECT id FROM addons WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE', $ids);
    $names = array_column(db_all('SELECT id, name FROM addons WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids), 'name', 'id');
    $errors = [];
    foreach (addon_stock_left($checkIn, $checkOut, $excludeBookingId) as $id => $s) {
        $want = min(50, $addons[$id] ?? 0);
        if ($want > $s['left']) {
            $errors[] = $s['left'] === 0
                ? sprintf('%s is fully booked on %s.', $names[$id], fmt_date($s['date'], 'D, M j'))
                : sprintf('Only %d %s left on %s (you asked for %d).', $s['left'], $names[$id], fmt_date($s['date'], 'D, M j'), $want);
        }
    }
    return $errors;
}

/** For a date range, which active units of a type are free? */
function available_units(string $checkIn, string $checkOut, ?string $type = null): array
{
    $sql = "SELECT a.* FROM accommodations a
            WHERE a.is_active = 1 " . ($type ? 'AND a.type = ? ' : '') . "
              AND NOT EXISTS (
                SELECT 1 FROM bookings b
                WHERE b.accommodation_id = a.id
                  AND b.status IN ('pending','confirmed','checked_in')
                  AND b.check_in < ? AND b.check_out > ?)
            ORDER BY a.sort_order, a.name";
    $params = $type ? [$type, $checkOut, $checkIn] : [$checkOut, $checkIn];
    return db_all($sql, $params);
}

// ---------- Validation + pricing ----------

/** Turns form input into a clean stay. Day cottages get check_out = check_in + 1. */
function normalize_stay(array $acc, string $checkIn, string $checkOut): array
{
    if ($acc['type'] === 'day_cottage') {
        $checkOut = $checkIn ? date('Y-m-d', strtotime($checkIn . ' +1 day')) : '';
    }
    return [$checkIn, $checkOut];
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

/**
 * Validate a booking request. $input keys:
 *   accommodation_id, check_in, check_out, num_adults, num_kids,
 *   guest_name, guest_phone, guest_email
 * Returns [errors[], accommodation|null, check_in, check_out]
 */
function validate_booking(array $input, bool $staffMode = false): array
{
    $errors = [];
    $acc = db_one('SELECT * FROM accommodations WHERE id = ?', [(int) ($input['accommodation_id'] ?? 0)]);
    if (!$acc || !$acc['is_active']) {
        return [['Please choose an available accommodation.'], null, '', ''];
    }

    [$in, $out] = normalize_stay($acc, (string) ($input['check_in'] ?? ''), (string) ($input['check_out'] ?? ''));
    $today = date('Y-m-d');

    if (!valid_date($in)) {
        $errors[] = 'Please choose a valid ' . ($acc['type'] === 'day_cottage' ? 'date.' : 'check-in date.');
    } elseif ($in < $today) {
        $errors[] = 'You cannot book a date in the past.';
    } elseif (!$staffMode && $in > date('Y-m-d', strtotime('+' . (int) setting('max_days_ahead', 180) . ' days'))) {
        $errors[] = 'Bookings open only ' . (int) setting('max_days_ahead', 180) . ' days ahead.';
    }

    if ($acc['type'] === 'overnight_room') {
        if (!valid_date($out)) {
            $errors[] = 'Please choose a valid check-out date.';
        } elseif (valid_date($in) && $out <= $in) {
            $errors[] = 'Check-out must be after check-in.';
        } elseif (valid_date($in) && nights_between($in, $out) > (int) setting('max_nights', 7)) {
            $errors[] = 'Online bookings are limited to ' . (int) setting('max_nights', 7) . ' nights. Please message us for longer stays.';
        }
    }

    $adults = (int) ($input['num_adults'] ?? 0);
    $kids = (int) ($input['num_kids'] ?? 0);
    if ($adults < 1) $errors[] = 'At least one adult is required.';
    if ($kids < 0) $errors[] = 'Number of kids cannot be negative.';
    if ($adults + $kids > (int) $acc['capacity']) {
        $errors[] = $acc['name'] . ' fits up to ' . (int) $acc['capacity'] . ' guests. You entered ' . ($adults + $kids) . '.';
    }

    if (mb_strlen(trim($input['guest_name'] ?? '')) < 2) $errors[] = 'Please enter the guest name.';
    if (!preg_match('/^[0-9+\-\s()]{7,20}$/', trim($input['guest_phone'] ?? ''))) $errors[] = 'Please enter a valid contact number.';
    $email = trim($input['guest_email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (!$staffMode && $email === '') $errors[] = 'Please enter your email so we can send your confirmation.';

    return [$errors, $acc, $in, $out];
}

function nights_between(string $in, string $out): int
{
    return (int) round((strtotime($out) - strtotime($in)) / 86400);
}

/**
 * Compute the price on the server. The JavaScript price on the form is only a preview.
 * $addons and $food are [id => quantity].
 */
function calculate_quote(array $acc, string $in, string $out, int $adults, int $kids, bool $swimming, array $addons = [], array $food = []): array
{
    $items = [];
    $units = max(1, nights_between($in, $out)); // nights or days
    $rate = (float) $acc['base_rate'];
    $isDay = $acc['type'] === 'day_cottage';

    // 1. Accommodation
    if ($acc['rate_type'] === 'per_head') {
        $qty = ($adults + $kids) * $units;
        $items[] = item('accommodation', (int) $acc['id'], $acc['name'] . ' (per head)', $qty, $rate);
    } elseif ($isDay) {
        $items[] = item('accommodation', (int) $acc['id'], $acc['name'] . ' (day use)', $units, $rate);
    } else {
        $items[] = item('accommodation', (int) $acc['id'], $acc['name'] . ' x ' . $units . ' night' . ($units > 1 ? 's' : ''), $units, $rate);
    }

    // 2. Entrance + swimming fees (overnight rooms already include them)
    if ($isDay) {
        $ea = (float) setting('entrance_adult', 0);
        $ek = (float) setting('entrance_kid', 0);
        if ($adults > 0 && $ea > 0) $items[] = item('entrance', null, 'Entrance fee - Adult', $adults * $units, $ea);
        if ($kids > 0 && $ek > 0) $items[] = item('entrance', null, 'Entrance fee - Kids', $kids * $units, $ek);
        if ($swimming) {
            $sa = (float) setting('swimming_adult', 0);
            $sk = (float) setting('swimming_kid', 0);
            if ($adults > 0 && $sa > 0) $items[] = item('swimming', null, 'Swimming fee - Adult', $adults * $units, $sa);
            if ($kids > 0 && $sk > 0) $items[] = item('swimming', null, 'Swimming fee - Kids', $kids * $units, $sk);
        }
    }

    // 3. Add-ons (price looked up from the DB, never trusted from the form)
    $addons = array_filter(array_map('intval', $addons), fn($q) => $q > 0);
    if ($addons) {
        foreach (active_addons() as $a) {
            $q = min(50, $addons[$a['id']] ?? 0);
            if ($q > 0) $items[] = item('addon', (int) $a['id'], $a['name'] . ' (' . $a['unit_label'] . ')', $q, (float) $a['price']);
        }
    }

    // 4. Food pre-order from the menu
    $food = array_filter(array_map('intval', $food), fn($q) => $q > 0);
    if ($food) {
        foreach (food_menu() as $f) {
            $q = min(50, $food[$f['variant_id']] ?? 0);
            if ($q > 0) {
                $label = $f['name'] . ($f['variant_label'] !== 'default' ? ' (' . $f['variant_label'] . ')' : '');
                $items[] = item('food', (int) $f['variant_id'], $label, $q, (float) $f['price']);
            }
        }
    }

    $total = round(array_sum(array_column($items, 'amount')), 2);
    $pct = max(0, min(100, (float) setting('downpayment_percent', 50)));
    return [
        'items'       => $items,
        'total'       => $total,
        'downpayment' => round($total * $pct / 100, 2),
        'percent'     => $pct,
        'units'       => $units,
    ];
}

function item(string $type, ?int $refId, string $desc, int $qty, float $unitPrice): array
{
    return [
        'item_type'   => $type,
        'ref_id'      => $refId,
        'description' => $desc,
        'quantity'    => $qty,
        'unit_price'  => $unitPrice,
        'amount'      => round($qty * $unitPrice, 2),
    ];
}

// ---------- Creating a booking (the part that must never double-book) ----------

/**
 * Creates a booking inside a transaction. The accommodation row is locked with
 * SELECT ... FOR UPDATE so two people clicking "Book" at the same moment are
 * processed one after the other; the second one then fails the overlap check.
 *
 * $opts: source ('online'|'walk_in'), status, user_id, created_by
 * Returns ['ok'=>bool, 'id'=>int, 'errors'=>[]]
 */
function create_booking(array $input, array $opts = []): array
{
    $staffMode = ($opts['source'] ?? 'online') === 'walk_in';
    [$errors, $acc, $in, $out] = validate_booking($input, $staffMode);
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $adults = (int) $input['num_adults'];
    $kids = (int) ($input['num_kids'] ?? 0);
    $swim = !empty($input['include_swimming']);
    $quote = calculate_quote($acc, $in, $out, $adults, $kids, $swim, (array) ($input['addons'] ?? []), (array) ($input['food'] ?? []));

    $status = $opts['status'] ?? 'pending';
    $expiresAt = $status === 'pending'
        ? date('Y-m-d H:i:s', strtotime('+' . max(1, (int) setting('payment_deadline_hours', 24)) . ' hours'))
        : null;

    $conn = db();
    $conn->begin_transaction();
    try {
        // Lock this accommodation so concurrent bookings for it wait their turn
        db_one('SELECT id FROM accommodations WHERE id = ? FOR UPDATE', [(int) $acc['id']]);

        if (!is_available((int) $acc['id'], $in, $out, null, true)) {
            $conn->rollback();
            return ['ok' => false, 'errors' => ['Sorry, ' . $acc['name'] . ' was just booked for that date. Please choose another date or unit.']];
        }
        if ($stockErrors = addon_stock_errors((array) ($input['addons'] ?? []), $in, $out)) {
            $conn->rollback();
            return ['ok' => false, 'errors' => $stockErrors];
        }

        db_exec(
            'INSERT INTO bookings (booking_code, user_id, guest_name, guest_phone, guest_email, accommodation_id, check_in, check_out,
                num_adults, num_kids, include_swimming, total_amount, downpayment_due, status, source, expires_at, special_requests, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'TMP-' . bin2hex(random_bytes(6)),
                $opts['user_id'] ?? null,
                trim($input['guest_name']),
                trim($input['guest_phone']),
                trim($input['guest_email'] ?? '') ?: null,
                (int) $acc['id'], $in, $out, $adults, $kids, $swim ? 1 : 0,
                $quote['total'], $quote['downpayment'],
                $status, $opts['source'] ?? 'online', $expiresAt,
                trim($input['special_requests'] ?? '') ?: null,
                $opts['created_by'] ?? null,
            ]
        );
        $id = db_insert_id();
        $code = sprintf('JM-%s-%04d', date('Y'), $id);
        db_exec('UPDATE bookings SET booking_code = ? WHERE id = ?', [$code, $id]);

        foreach ($quote['items'] as $it) {
            db_exec(
                'INSERT INTO booking_items (booking_id, item_type, ref_id, description, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $it['item_type'], $it['ref_id'], $it['description'], $it['quantity'], $it['unit_price'], $it['amount']]
            );
        }
        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        if (APP_DEBUG) throw $ex;
        return ['ok' => false, 'errors' => ['Something went wrong while saving your booking. Please try again.']];
    }

    audit($staffMode ? 'booking.walk_in' : 'booking.create', 'booking', $id, $code . ' - ' . $acc['name'] . ' ' . $in . ' - total ' . $quote['total']);
    return ['ok' => true, 'id' => $id, 'code' => $code];
}

// ---------- Status flow ----------

const STATUS_FLOW = [
    'pending'    => ['confirmed', 'cancelled', 'rejected', 'expired'],
    'confirmed'  => ['checked_in', 'cancelled'],
    'checked_in' => ['completed'],
    'completed'  => [],
    'cancelled'  => [],
    'expired'    => [],
    'rejected'   => [],
];

function can_transition(string $from, string $to): bool
{
    return in_array($to, STATUS_FLOW[$from] ?? [], true);
}

/** Returns null on success, or an error message. */
function change_booking_status(int $id, string $to, string $reason = ''): ?string
{
    $b = get_booking($id);
    if (!$b) return 'Booking not found.';
    if (!can_transition($b['status'], $to)) {
        return 'A ' . str_replace('_', '-', $b['status']) . ' booking cannot be changed to ' . str_replace('_', '-', $to) . '.';
    }
    if ($to === 'completed' && balance_due($b) > 0) {
        return 'Record the remaining balance of ' . money(balance_due($b)) . ' before completing this booking.';
    }
    if (in_array($to, ['cancelled', 'rejected'], true) && trim($reason) === '') {
        return 'Please give a reason.';
    }

    db_exec('UPDATE bookings SET status = ?, status_reason = ?, expires_at = IF(? = \'pending\', expires_at, NULL) WHERE id = ?',
        [$to, $reason !== '' ? mb_substr($reason, 0, 255) : $b['status_reason'], $to, $id]);
    audit('booking.' . $to, 'booking', $id, $b['booking_code'] . ($reason ? ' - ' . $reason : ''));

    if (in_array($to, ['confirmed', 'cancelled', 'rejected', 'completed'], true)) {
        notify_booking($id, $to, $reason);
    }
    return null;
}

/** Pending bookings past their deadline with no receipt waiting to be checked -> expired. */
function expire_overdue_bookings(): int
{
    $rows = db_all("SELECT b.id, b.booking_code FROM bookings b
                    WHERE b.status = 'pending' AND b.expires_at IS NOT NULL AND b.expires_at < NOW()
                      AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status = 'pending')");
    foreach ($rows as $r) {
        $n = db_exec("UPDATE bookings SET status = 'expired', status_reason = 'No payment received before the deadline', expires_at = NULL
                      WHERE id = ? AND status = 'pending'", [(int) $r['id']]);
        if ($n) {
            db_exec('INSERT INTO audit_logs (user_id, action, target_type, target_id, details, ip_address) VALUES (NULL, ?, ?, ?, ?, ?)',
                ['booking.expired', 'booking', (int) $r['id'], $r['booking_code'] . ' - auto-expired, dates released', 'system']);
            notify_booking((int) $r['id'], 'expired');
        }
    }
    return count($rows);
}

// ---------- Payments ----------

/**
 * Validates and stores an uploaded receipt in uploads/receipts with a random name.
 * Returns [filename|null, error|null]
 */
function store_receipt_upload(?array $file): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, 'Please attach a photo or PDF of your receipt.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'The file is too large. Maximum is ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB.'
            : 'Upload failed. Please try again.'];
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return [null, 'The file is too large. Maximum is ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB.'];
    }
    // Check the real file content, not the extension the browser claims
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($allowed[$mime])) {
        return [null, 'Only JPG, PNG or PDF files are accepted.'];
    }
    $name = date('Ymd') . '_' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $dest = UPLOADS_DIR . '/receipts/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return [null, 'Could not save the file. Check that uploads/receipts is writable.'];
    }
    return [$name, null];
}

/** Mark a payment verified; confirms the booking once the downpayment is covered. */
function verify_payment(int $paymentId): ?string
{
    $p = db_one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
    if (!$p || $p['status'] !== 'pending') return 'This payment was already processed.';
    db_exec("UPDATE payments SET status = 'verified', verified_by = ?, verified_at = NOW(), reject_reason = NULL WHERE id = ?",
        [current_user()['id'], $paymentId]);
    $b = get_booking((int) $p['booking_id']);
    audit('payment.verify', 'booking', (int) $b['id'], $b['booking_code'] . ' - ' . money($p['amount']) . ' via ' . $p['method']);

    if ($b['status'] === 'pending') {
        if ((float) $b['amount_paid'] + 0.001 >= (float) $b['downpayment_due']) {
            return change_booking_status((int) $b['id'], 'confirmed');
        }
        return 'Payment verified, but it is less than the downpayment (' . money($b['downpayment_due']) . '), so the booking is still pending.';
    }
    return null;
}

function reject_payment(int $paymentId, string $reason): ?string
{
    if (trim($reason) === '') return 'Please give a reason so the guest knows what to fix.';
    $p = db_one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
    if (!$p || $p['status'] !== 'pending') return 'This payment was already processed.';
    db_exec("UPDATE payments SET status = 'rejected', reject_reason = ?, verified_by = ?, verified_at = NOW() WHERE id = ?",
        [mb_substr($reason, 0, 255), current_user()['id'], $paymentId]);
    $b = get_booking((int) $p['booking_id']);
    // Give the guest a fresh window to re-upload
    if ($b['status'] === 'pending') {
        db_exec('UPDATE bookings SET expires_at = GREATEST(COALESCE(expires_at, NOW()), DATE_ADD(NOW(), INTERVAL ? HOUR)) WHERE id = ?',
            [max(1, (int) setting('payment_deadline_hours', 24)), (int) $b['id']]);
    }
    audit('payment.reject', 'booking', (int) $b['id'], $b['booking_code'] . ' - ' . $reason);
    notify_booking((int) $b['id'], 'payment_rejected', $reason);
    return null;
}
