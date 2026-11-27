<?php
/**
 * Demo data: a full year (2025) of bookings, payments and guest accounts, so the
 * admin side (dashboard, bookings, guests, calendar, reports, audit log) has history to show.
 *
 *   C:\xampp\php\php.exe -d extension=gd database\seed_demo_2025.php           add the demo year
 *   C:\xampp\php\php.exe database\seed_demo_2025.php --remove                    take it out again
 *
 * Prices come from calculate_quote(), the same function real bookings use, so every
 * total, downpayment and line item matches what the system would have charged.
 * Running it twice is safe: it removes its own previous run first.
 * Every demo booking has a code starting JM-2025-; demo guest accounts use @demo.jmairefarm.test.
 */
if (PHP_SAPI !== 'cli') exit("Run this from the command line.\n");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../includes/bootstrap.php';

const DEMO_YEAR = 2025;
const DEMO_DOMAIN = '@demo.jmairefarm.test';
const ADMIN_ID = 1;
const STAFF_ID = 2;

function remove_demo(): array
{
    $ids = array_map('intval', array_column(db_all("SELECT id FROM bookings WHERE booking_code LIKE 'JM-" . DEMO_YEAR . "-%'"), 'id'));
    if ($ids) {
        $in = implode(',', $ids);
        db_exec("DELETE FROM audit_logs WHERE target_type = 'booking' AND target_id IN ($in)");
        db_exec("DELETE FROM bookings WHERE id IN ($in)"); // items and payments cascade
    }
    $users = (int) db_value('SELECT COUNT(*) FROM users WHERE email LIKE ?', ['%' . DEMO_DOMAIN]);
    db_exec('DELETE FROM users WHERE email LIKE ?', ['%' . DEMO_DOMAIN]);
    foreach (['bookings', 'booking_items', 'payments', 'users', 'audit_logs'] as $t) {
        db_exec("ALTER TABLE $t AUTO_INCREMENT = 1"); // MySQL moves it to MAX(id)+1, so no gap is left
    }
    return [count($ids), $users];
}

[$oldB, $oldU] = remove_demo();
if (in_array('--remove', $argv, true)) {
    echo "Removed $oldB demo bookings and $oldU demo guest accounts.\n";
    exit;
}

mt_srand(20250101); // same "random" year every run
$pick = fn(array $a) => $a[mt_rand(0, count($a) - 1)];
$chance = fn(float $p) => mt_rand() / mt_getrandmax() < $p;
$at = fn(string $date, int $h1, int $h2) => sprintf('%s %02d:%02d:00', $date, mt_rand($h1, $h2), mt_rand(0, 59));

$first = ['Juan', 'Maria', 'Jose', 'Ana', 'Mark', 'Kristine', 'John Paul', 'Angelica', 'Christian', 'Jasmine', 'Carlo', 'Patricia', 'Miguel', 'Camille',
          'Rafael', 'Nicole', 'Paolo', 'Bea', 'Joshua', 'Trisha', 'Ramon', 'Liza', 'Dennis', 'Grace', 'Arnel', 'Joy', 'Ryan', 'Mae', 'Noel', 'Cheryl',
          'Jerome', 'Leah', 'Allan', 'Rowena', 'Kevin', 'Sheila', 'Vincent', 'Hazel', 'Rodel', 'Lovely'];
$last = ['Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Torres', 'Villanueva', 'Gonzales', 'Ramos', 'Bautista', 'Aquino', 'Castillo', 'Flores',
         'Navarro', 'Salazar', 'Tan', 'Lim', 'Jalandoni', 'Ledesma', 'Javellana', 'Gamboa', 'Sison', 'Tupas', 'Bermejo', 'Palmares', 'Lacson', 'Divinagracia', 'Españo'];
$phone = fn() => '09' . mt_rand(15, 99) . sprintf('%07d', mt_rand(0, 9999999));

// Guest accounts: the online bookers, many of them coming back during the year
$guests = [];
$hash = password_hash('guest123', PASSWORD_DEFAULT);
for ($i = 0; $i < 28; $i++) {
    $name = $pick($first) . ' ' . $pick($last);
    $email = strtolower(preg_replace('/[^a-z]+/i', '.', iconv('UTF-8', 'ASCII//TRANSLIT', $name))) . $i . DEMO_DOMAIN;
    $joined = $at(date('Y-m-d', mktime(0, 0, 0, 7, $i ? mt_rand(1, 500) : 1, DEMO_YEAR - 1)), 7, 22); // accounts made from mid last year onward
    db_exec('INSERT INTO users (name, email, phone, password, role, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$name, $email, $phone(), $hash, 'guest', $joined]);
    $guests[] = ['id' => db_insert_id(), 'name' => $name, 'email' => $email, 'phone' => db_value('SELECT phone FROM users WHERE id = ?', [db_insert_id()]), 'joined' => $joined];
}

$units = db_all('SELECT * FROM accommodations ORDER BY id');
$addons = array_column(db_all('SELECT id, name FROM addons'), 'id', 'name');
$food = array_column(food_menu(), 'variant_id');

// How busy each month is: summer (Mar-May) and the December holidays are peak season, the rainy months are quiet
$season = [1 => .7, 2 => .8, 3 => 1.2, 4 => 1.7, 5 => 1.6, 6 => .9, 7 => .55, 8 => .55, 9 => .6, 10 => .7, 11 => .85, 12 => 1.35];
$busyDays = array_merge(
    array_map(fn($d) => sprintf('%d-04-%02d', DEMO_YEAR, $d), range(13, 20)),   // Holy Week
    array_map(fn($d) => sprintf('%d-12-%02d', DEMO_YEAR, $d), range(20, 31)),   // Christmas to New Year's Eve
    [DEMO_YEAR . '-01-01', DEMO_YEAR . '-05-01', DEMO_YEAR . '-06-12', DEMO_YEAR . '-08-25', DEMO_YEAR . '-11-01']
);
$requests = ['Near the pool if possible', 'Celebrating a birthday', 'Will arrive around 10 AM', 'Need an extension cord for a speaker',
             'Family reunion, we may bring our own food', 'Please prepare the bonfire by 7 PM', 'Late check-in, around 6 PM', 'Senior citizen in the group'];
$rejectReasons = ['Reference number not found in our GCash history', 'Amount on the receipt does not match the downpayment', 'Receipt image is blurry, please upload a clearer copy'];
$cancelReasons = ['Guest cancelled due to bad weather', 'Guest rescheduled to a later date', 'Family emergency', 'Typhoon signal raised in Iloilo'];

$taken = [];      // [unit id][Y-m-d] => true, so no unit is double-booked
$made = 0; $statusTally = [];
$audit = function (?int $user, string $action, int $bookingId, string $details, string $when) {
    db_exec('INSERT INTO audit_logs (user_id, action, target_type, target_id, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$user, $action, 'booking', $bookingId, mb_substr($details, 0, 500), '127.0.0.1', $when]);
};
$pay = function (int $bookingId, float $amount, string $method, string $type, string $status, ?int $recordedBy, string $paidAt, ?string $reason = null) {
    $ref = $method === 'gcash' ? (string) mt_rand(1000000000, 9999999999) : ($method === 'bank' ? 'BDO-' . mt_rand(100000, 999999) : null);
    $verifiedAt = $status === 'pending' ? null : date('Y-m-d H:i:s', strtotime($paidAt) + ($method === 'cash' ? 0 : mt_rand(1800, 6 * 3600)));
    db_exec('INSERT INTO payments (booking_id, amount, method, payment_type, reference_no, status, reject_reason, recorded_by, verified_by, verified_at, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$bookingId, $amount, $method, $type, $ref, $status, $reason, $recordedBy, $status === 'pending' ? null : (mt_rand(0, 4) ? STAFF_ID : ADMIN_ID), $verifiedAt, $paidAt]);
    return $verifiedAt;
};

$day = mktime(0, 0, 0, 1, 1, DEMO_YEAR);
$endOfYear = mktime(0, 0, 0, 12, 31, DEMO_YEAR);
for (; $day <= $endOfYear; $day = strtotime('+1 day', $day)) {
    $date = date('Y-m-d', $day);
    $weekend = in_array(date('N', $day), ['5', '6', '7'], true);
    $demand = $season[(int) date('n', $day)] * (in_array($date, $busyDays, true) ? 2.2 : 1);

    foreach ($units as $u) {
        $isDay = $u['type'] === 'day_cottage';
        $p = ($isDay ? ($weekend ? .2 : .045) : ($weekend ? .17 : .05)) * $demand;
        if (!$chance(min(.92, $p)) || isset($taken[$u['id']][$date])) continue;

        $nights = $isDay ? 1 : $pick([1, 1, 1, 2, 2, 3]);
        $in = $date;
        $out = date('Y-m-d', strtotime("$date +$nights day"));
        for ($n = 1; $n < $nights; $n++) if (isset($taken[$u['id']][date('Y-m-d', strtotime("$date +$n day"))])) { $nights = $n; $out = date('Y-m-d', strtotime("$date +$n day")); break; }
        if ($out > DEMO_YEAR . '-12-31' && !$isDay) { $out = DEMO_YEAR . '-12-31'; if ($out <= $in) continue; }

        // Who is coming
        $cap = (int) $u['capacity'];
        $people = $isDay ? mt_rand(max(2, (int) ceil($cap * .4)), $cap) : mt_rand(max(1, $cap - 2), $cap);
        $kids = $isDay ? mt_rand(0, (int) floor($people / 2)) : mt_rand(0, max(0, $people - 2));
        $adults = max(1, $people - $kids);
        $swim = $isDay && $chance(.65);

        $extras = [];
        if ($isDay) {
            if ($chance(.18)) $extras[$addons['Kayak']] = mt_rand(1, 3);
            if ($chance(.1)) $extras[$addons['Billiards']] = mt_rand(1, 3);
            if ($chance(.08)) $extras[$addons['KTV']] = mt_rand(2, 4);
        } else {
            if ($chance(.2)) $extras[$addons['Extra mattress']] = 1;
            if ($chance(.25)) $extras[$addons['Bonfire setup']] = 1;
            if ($chance(.15)) $extras[$addons['KTV']] = mt_rand(1, 3);
        }
        if ($u['name'] === 'Big Cottage' && $chance(.2)) $extras[$addons['Camping tent space']] = mt_rand(1, 3);
        $order = [];
        if ($chance(.4)) for ($k = mt_rand(1, 4); $k > 0; $k--) $order[$pick($food)] = mt_rand(1, 3);

        $q = calculate_quote($u, $in, $out, $adults, $kids, $swim, $extras, $order);

        // How it was booked and how it ended
        $walkIn = $chance($isDay ? .55 : .25);
        $roll = mt_rand(1, 100);
        $status = $walkIn ? 'completed' : ($roll <= 82 ? 'completed' : ($roll <= 89 ? 'cancelled' : ($roll <= 96 ? 'expired' : 'rejected')));
        if ($walkIn) {
            $guest = ['id' => null, 'name' => $pick($first) . ' ' . $pick($last), 'email' => null, 'phone' => $phone()];
            $createdAt = $at($in, 7, 11);
            $createdBy = mt_rand(0, 5) ? STAFF_ID : ADMIN_ID;
        } else {
            $createdAt = $at(date('Y-m-d', strtotime("$in -" . mt_rand(2, 35) . ' day')), 6, 23);
            $members = array_values(array_filter($guests, fn($g) => $g['joined'] < $createdAt)); // only people who had an account by then
            $guest = $members ? $pick($members) : $guests[0];
            $createdBy = $guest['id'];
        }

        db_exec('INSERT INTO bookings (booking_code, user_id, guest_name, guest_phone, guest_email, accommodation_id, check_in, check_out, num_adults, num_kids,
                    include_swimming, total_amount, downpayment_due, status, source, expires_at, special_requests, status_reason, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['TMP-' . bin2hex(random_bytes(6)), $guest['id'], $guest['name'], $guest['phone'], $guest['email'], $u['id'], $in, $out, $adults, $kids,
             $swim ? 1 : 0, $q['total'], $q['downpayment'], $status, $walkIn ? 'walk_in' : 'online',
             $status === 'expired' ? date('Y-m-d H:i:s', strtotime("$createdAt +24 hours")) : null,
             !$walkIn && $chance(.25) ? $pick($requests) : null,
             $status === 'cancelled' ? $pick($cancelReasons) : ($status === 'rejected' ? 'Payment could not be verified' : null),
             $createdBy, $createdAt, $createdAt]);
        $id = db_insert_id();
        $code = sprintf('JM-%d-%04d', DEMO_YEAR, $id);
        db_exec('UPDATE bookings SET booking_code = ? WHERE id = ?', [$code, $id]);
        foreach ($q['items'] as $it) {
            db_exec('INSERT INTO booking_items (booking_id, item_type, ref_id, description, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $it['item_type'], $it['ref_id'], $it['description'], $it['quantity'], $it['unit_price'], $it['amount']]);
        }
        $audit($createdBy, $walkIn ? 'booking.walk_in' : 'booking.create', $id, "$code - {$u['name']} $in - total {$q['total']}", $createdAt);

        // Money
        $lastAt = $createdAt;
        $balanceAt = $isDay ? $at($in, 14, 16) : $at($out, 10, 11);
        if ($walkIn) {
            $method = mt_rand(1, 10) <= 8 ? 'cash' : 'gcash';
            $lastAt = $pay($id, $q['total'], $method, 'full', 'verified', $createdBy, date('Y-m-d H:i:s', strtotime($createdAt) + mt_rand(120, 1200)));
        } elseif ($status !== 'expired') {
            $method = mt_rand(1, 10) <= 7 ? 'gcash' : 'bank';
            $paidAt = date('Y-m-d H:i:s', strtotime($createdAt) + mt_rand(600, 18 * 3600));
            $full = $status === 'completed' && $chance(.12);
            $amount = $full ? $q['total'] : $q['downpayment'];
            if ($status === 'rejected') {
                $lastAt = $pay($id, $amount, $method, 'downpayment', 'rejected', $guest['id'], $paidAt, $pick($rejectReasons));
                $audit(STAFF_ID, 'payment.reject', $id, "$code - receipt rejected", $lastAt);
            } else {
                $lastAt = $pay($id, $amount, $method, $full ? 'full' : 'downpayment', 'verified', $guest['id'], $paidAt);
                $audit(STAFF_ID, 'payment.verify', $id, "$code - " . money($amount) . " via $method", $lastAt);
                $audit(STAFF_ID, 'booking.confirmed', $id, $code, $lastAt);
                if ($status === 'completed' && !$full) {
                    $lastAt = $pay($id, round($q['total'] - $q['downpayment'], 2), mt_rand(1, 10) <= 8 ? 'cash' : 'gcash', 'balance', 'verified', STAFF_ID, $balanceAt);
                }
            }
        }
        if ($status === 'completed') {
            $checkedIn = $walkIn ? date('Y-m-d H:i:s', strtotime($createdAt) + mt_rand(300, 1800)) : $at($in, $isDay ? 8 : 14, $isDay ? 10 : 17);
            $audit(STAFF_ID, 'booking.checked_in', $id, $code, $checkedIn);
            $lastAt = $isDay ? $at($in, 17, 18) : $at($out, 11, 12);
            $audit(STAFF_ID, 'booking.completed', $id, $code, $lastAt);
        } elseif ($status === 'cancelled') {
            $lastAt = date('Y-m-d H:i:s', min(strtotime("$in 07:00:00"), strtotime($lastAt) + mt_rand(3600, 5 * 86400)));
            $audit(STAFF_ID, 'booking.cancelled', $id, $code, $lastAt);
        } elseif ($status === 'expired') {
            $lastAt = date('Y-m-d H:i:s', strtotime("$createdAt +24 hours"));
            $audit(null, 'booking.expired', $id, "$code - auto-expired, dates released", $lastAt);
        }
        db_exec('UPDATE bookings SET updated_at = ? WHERE id = ?', [$lastAt, $id]);

        if (in_array($status, ['completed'], true)) {
            for ($n = 0; $n < $nights; $n++) $taken[$u['id']][date('Y-m-d', strtotime("$in +$n day"))] = true;
        }
        $made++;
        $statusTally[$status] = ($statusTally[$status] ?? 0) + 1;
    }
}

$paid = (float) db_value("SELECT SUM(p.amount) FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE b.booking_code LIKE 'JM-" . DEMO_YEAR . "-%' AND p.status = 'verified'");
ksort($statusTally);
echo ($oldB ? "Replaced the previous demo run ($oldB bookings).\n" : ''),
     "Added $made bookings for " . DEMO_YEAR . ' and ' . count($guests) . " guest accounts.\n",
     'By status: ' . json_encode($statusTally) . "\n",
     'Verified payments: ' . money($paid) . "\n";
