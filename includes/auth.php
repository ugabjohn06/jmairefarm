<?php
// Login state and role checks. Roles: guest < staff < admin.

function current_user(bool $refresh = false): ?array
{
    static $user = false;
    if ($user === false || $refresh) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $user = db_one('SELECT id, name, email, username, phone, role, is_active FROM users WHERE id = ?', [(int) $_SESSION['user_id']]);
            // Log out users that were deactivated while signed in
            if (!$user || !$user['is_active']) {
                $user = null;
                unset($_SESSION['user_id']);
            }
        }
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function user_role(): ?string
{
    return current_user()['role'] ?? null;
}

function is_staff(): bool
{
    return in_array(user_role(), ['staff', 'admin'], true);
}

function is_admin(): bool
{
    return user_role() === 'admin';
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    current_user(true);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Any logged-in user. Remembers where they were going. */
function require_login(): array
{
    if (!is_logged_in()) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? url();
        flash('warning', 'Please log in first.');
        redirect('login.php');
    }
    return current_user();
}

function require_staff(): array
{
    $user = require_login();
    if (!is_staff()) {
        http_response_code(403);
        include APP_ROOT . '/includes/403.php';
        exit;
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (!is_admin()) {
        http_response_code(403);
        include APP_ROOT . '/includes/403.php';
        exit;
    }
    return $user;
}

/** Guests may only see their own bookings; staff/admin see all. */
function can_view_booking(array $booking): bool
{
    if (is_staff()) return true;
    $user = current_user();
    if ($user && (int) $booking['user_id'] === (int) $user['id']) return true;
    // Bookings made without an account are unlocked by the code + phone lookup
    return in_array((int) $booking['id'], $_SESSION['unlocked_bookings'] ?? [], true);
}
