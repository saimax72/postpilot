<?php
/**
 * Authentication, registration and access control.
 */

function auth_user(): ?array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached ?: null;
    }
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        $cached = false;
        return null;
    }
    $user = db_one('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$user || $user['status'] !== 'active') {
        session_destroy();
        $cached = false;
        return null;
    }
    $cached = $user;
    return $user;
}

function auth_id(): ?int
{
    $u = auth_user();
    return $u ? (int)$u['id'] : null;
}

function is_admin(): bool
{
    $u = auth_user();
    return $u && $u['role'] === 'admin';
}

function user_tz(): string
{
    $u = auth_user();
    return $u['timezone'] ?? APP_TIMEZONE;
}

function require_login(): array
{
    $u = auth_user();
    if (!$u) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            json_fail('Not authenticated.', 401);
        }
        $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? '/dashboard.php';
        redirect('/login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Forbidden — admin access only.');
    }
    return $u;
}

/* ---------------- Rate limiting ---------------- */

function login_throttled(string $email): bool
{
    $count = (int)db_value(
        'SELECT COUNT(*) FROM login_attempts
         WHERE (email = ? OR ip = ?) AND created_at > (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)',
        [$email, client_ip()]
    );
    return $count >= 8;
}

function login_record_failure(string $email): void
{
    db_run('INSERT INTO login_attempts (email, ip) VALUES (?,?)', [$email, client_ip()]);
    db_run('DELETE FROM login_attempts WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
}

function login_clear(string $email): void
{
    db_run('DELETE FROM login_attempts WHERE email = ? OR ip = ?', [$email, client_ip()]);
}

/* ---------------- Actions ---------------- */

function attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));

    if ($email === '' || $password === '') {
        return [false, 'Enter your email and password.'];
    }
    if (login_throttled($email)) {
        return [false, 'Too many attempts. Try again in 15 minutes.'];
    }

    $user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        login_record_failure($email);
        return [false, 'That email and password combination is not recognised.'];
    }
    if ($user['status'] !== 'active') {
        return [false, 'This account has been suspended. Contact support.'];
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_run('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    db_run('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$user['id']]);
    login_clear($email);
    log_activity((int)$user['id'], 'login', 'Signed in');

    return [true, $user];
}

function register_user(string $name, string $email, string $password, string $timezone = 'UTC', string $role = 'user'): array
{
    $name  = trim($name);
    $email = strtolower(trim($email));

    if (mb_strlen($name) < 2) {
        return [false, 'Please enter your name.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Please enter a valid email address.'];
    }
    if (strlen($password) < 8) {
        return [false, 'Password must be at least 8 characters.'];
    }
    if (!in_array($timezone, timezone_list(), true)) {
        $timezone = 'UTC';
    }
    if (db_value('SELECT id FROM users WHERE email = ?', [$email])) {
        return [false, 'An account with that email already exists.'];
    }

    $colors = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2'];

    $cols = ['name', 'email', 'password_hash', 'role', 'timezone', 'avatar_color', 'plan'];
    $vals = [$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $timezone,
             $colors[array_rand($colors)], 'trial'];

    if (has_trial_column()) {
        $cols[] = 'trial_ends_at';
        $vals[] = gmdate('Y-m-d H:i:s', time() + TRIAL_DAYS * 86400);
    }

    db_run(
        'INSERT INTO users (' . implode(', ', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($vals), '?')) . ')',
        $vals
    );
    $id = (int)db()->lastInsertId();
    log_activity($id, 'register', 'Account created');

    return [true, $id];
}

function logout(): void
{
    if ($id = auth_id()) {
        log_activity($id, 'logout', 'Signed out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
