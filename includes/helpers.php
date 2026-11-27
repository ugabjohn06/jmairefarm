<?php
// General helpers: escaping, URLs, flash messages, CSRF, formatting, settings.

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function base_url(): string
{
    static $base = null;
    if ($base !== null) return $base;
    if (BASE_URL !== null) return $base = rtrim(BASE_URL, '/');
    // Auto-detect: the running script is e.g. C:\xampp\htdocs\j-maire\admin\index.php and its URL is
    // /j-maire/admin/index.php, so the part of the URL before "/admin/index.php" is the base.
    $file = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    $root = str_replace('\\', '/', APP_ROOT);
    $file = str_replace('\\', '/', $file);
    $scriptUrl = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($file && str_starts_with($file, $root)) {
        $inApp = substr($file, strlen($root)); // "/admin/index.php"
        if ($inApp !== '' && str_ends_with($scriptUrl, $inApp)) {
            return $base = rtrim(substr($scriptUrl, 0, -strlen($inApp)), '/');
        }
    }
    return $base = '';
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

/** The J'Maire logo lockup: 'green' on light grounds, 'white' on the forest field or photos. */
function logo_img(string $variant = 'green', string $class = 'logo', int $height = 40): string
{
    $file = $variant === 'white' ? 'logo-white.png' : 'logo-green.png';
    $width = (int) round($height * 1421 / 632); // the cropped lockup is 1421 x 632
    return '<img class="' . e($class) . '" src="' . e(url('assets/img/' . $file)) . '" alt="' . e(setting('resort_name', "J'Maire Farm")) .
        '" width="' . $width . '" height="' . $height . '">';
}

/** Public URL of a file in the (possibly shared) uploads folder. */
function uploads_url(string $path): string
{
    if (!UPLOADS_SHARED) return url('uploads/' . $path);
    $parent = rtrim(str_replace('\\', '/', dirname(base_url())), '/');
    return $parent . '/' . SHARED_APP_FOLDER . '/uploads/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function post(string $key, $default = '')
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function get(string $key, $default = '')
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

// ---------- Flash messages ----------
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function render_flashes(): string
{
    if (empty($_SESSION['flash'])) return '';
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $type = $f['type'] === 'error' ? 'danger' : $f['type'];
        $html .= '<div class="alert alert-' . e($type) . ' alert-dismissible fade show" role="alert">' .
            e($f['message']) .
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// ---------- Old form input (re-fill forms after a validation error) ----------
function remember_input(): void
{
    $_SESSION['old'] = $_POST;
    unset($_SESSION['old']['password'], $_SESSION['old']['password_confirm'], $_SESSION['old']['csrf_token']);
}

function old(string $key, $default = '')
{
    return $_SESSION['old'][$key] ?? $default;
}

function clear_old(): void
{
    unset($_SESSION['old']);
}

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Call at the top of every POST handler. */
function csrf_check(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        die('Your session expired. Please go back, refresh the page and try again.');
    }
}

// ---------- Formatting ----------
function money($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

function fmt_date(?string $date, string $format = 'M j, Y'): string
{
    if (!$date) return '';
    return date($format, strtotime($date));
}

function fmt_datetime(?string $dt): string
{
    return $dt ? date('M j, Y g:i A', strtotime($dt)) : '';
}

/** Human friendly stay label: day-use shows one date, overnight shows a range. */
function stay_label(array $b): string
{
    $nights = (int) round((strtotime($b['check_out']) - strtotime($b['check_in'])) / 86400);
    if (($b['type'] ?? '') === 'day_cottage') {
        return fmt_date($b['check_in'], 'D, M j, Y') . ($nights > 1 ? ' (+' . ($nights - 1) . ' day)' : '');
    }
    return fmt_date($b['check_in'], 'M j') . ' – ' . fmt_date($b['check_out'], 'M j, Y') .
        ' (' . $nights . ' night' . ($nights === 1 ? '' : 's') . ')';
}

const BOOKING_STATUSES = [
    'pending'    => ['Pending',    'warning'],
    'confirmed'  => ['Confirmed',  'success'],
    'checked_in' => ['Checked in', 'primary'],
    'completed'  => ['Completed',  'secondary'],
    'cancelled'  => ['Cancelled',  'dark'],
    'expired'    => ['Expired',    'dark'],
    'rejected'   => ['Rejected',   'danger'],
];

function status_badge(string $status): string
{
    [$label, $color] = BOOKING_STATUSES[$status] ?? [ucfirst($status), 'secondary'];
    return '<span class="badge text-bg-' . $color . ' status-badge">' . e($label) . '</span>';
}

function payment_badge(string $status): string
{
    $map = ['pending' => 'warning', 'verified' => 'success', 'rejected' => 'danger'];
    return '<span class="badge text-bg-' . ($map[$status] ?? 'secondary') . '">' . e(ucfirst($status)) . '</span>';
}

function type_label(string $type): string
{
    return $type === 'overnight_room' ? 'Overnight room' : 'Day cottage';
}

/** Display name for a stored payment method (gcash / cash / bank). */
function payment_method_label(string $method): string
{
    return ['gcash' => 'GCash', 'cash' => 'Cash', 'bank' => 'Bank transfer'][$method] ?? ucfirst($method);
}

// ---------- Settings (key/value table) ----------
function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (db_all('SELECT `key`, `value` FROM settings') as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }
    return $cache;
}

function setting(string $key, $default = '')
{
    $all = settings_all();
    return $all[$key] ?? $default;
}

/**
 * Databases imported before the unit photos were bundled have no photo_path set,
 * so every unit shows the placeholder. Once per database, give units that have no
 * photo the bundled one that matches their name. A photo removed later on purpose
 * stays removed, because the 'unit_photos_bundled' flag stops this from running again.
 */
function apply_bundled_unit_photos(): void
{
    if (setting('unit_photos_bundled') !== '') return;
    $bundled = [
        'Open Cottage 1'          => 'unit_open_cottage.jpg',
        'Open Cottage 2'          => 'unit_open_cottage.jpg',
        'Open Cottage (Large)'    => 'unit_open_cottage_large_.jpg',
        'Big Cottage'             => 'unit_big_cottage.jpg',
        'Kalesa Table & Chairs 1' => 'unit_kalesa_table_with_chairs.jpg',
        'Kalesa Table & Chairs 2' => 'unit_kalesa_table_with_chairs.jpg',
        'Cottage with Room'       => 'unit_cottage_with_room.jpg',
        'Deluxe Room A'           => 'unit_deluxe_room.jpg',
        'Deluxe Room B'           => 'unit_deluxe_room.jpg',
        'Deluxe Room (4 pax)'     => 'unit_deluxe_room_4pax.jpg',
        'Family Room'             => 'unit_family_room.jpg',
        'Barkada Room'            => 'unit_barkada_room.jpg',
    ];
    try {
        foreach ($bundled as $name => $file) {
            if (is_file(APP_ROOT . '/uploads/accommodations/' . $file)) {
                // Also repairs names saved with a stray line break by an earlier install script.
                db_exec("UPDATE accommodations SET photo_path = ? WHERE name = ? AND (photo_path IS NULL OR TRIM(REPLACE(REPLACE(photo_path, '\r', ''), '\n', '')) IN ('', ?))", [$file, $name, $file]);
            }
        }
        db_exec("INSERT INTO settings (`key`, `value`) VALUES ('unit_photos_bundled', '1') ON DUPLICATE KEY UPDATE `value` = '1'");
        settings_all(true);
    } catch (Throwable $e) {
        // Never block a page over photos; it will try again on the next request.
    }
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

function accommodation_photo(?string $path, string $type = 'day_cottage'): string
{
    $path = $path === null ? null : trim($path);
    if ($path && is_file(UPLOADS_DIR . '/accommodations/' . $path)) {
        return uploads_url('accommodations/' . rawurlencode($path));
    }
    // The bundled unit photos ship inside this folder, so they still show when the
    // shared folder (SHARED_APP_FOLDER) exists but doesn't have them.
    if ($path && UPLOADS_SHARED && is_file(APP_ROOT . '/uploads/accommodations/' . $path)) {
        return url('uploads/accommodations/' . rawurlencode($path));
    }
    return url('assets/img/no-photo.png'); // same placeholder for every unit until a photo is uploaded
}

/** Simple pagination helper: returns [limit, offset, page]. */
function paginate(int $perPage = 20): array
{
    $page = max(1, (int) get('page', 1));
    return [$perPage, ($page - 1) * $perPage, $page];
}

function pagination_links(int $total, int $perPage, int $page): string
{
    $pages = (int) ceil($total / $perPage);
    if ($pages <= 1) return '';
    $q = $_GET;
    $html = '<nav><ul class="pagination pagination-sm">';
    for ($i = 1; $i <= $pages; $i++) {
        $q['page'] = $i;
        $html .= '<li class="page-item' . ($i === $page ? ' active' : '') . '"><a class="page-link" href="?' .
            e(http_build_query($q)) . '">' . $i . '</a></li>';
    }
    return $html . '</ul></nav>';
}
