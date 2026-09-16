<?php
/**
 * includes/auth.php
 *
 * Central authentication helpers for Preschool Management System.
 * Supports unified session keys plus legacy per-role keys for backward compatibility.
 *
 * Unified session (preferred after OTP login):
 *   $_SESSION['user_id'], $_SESSION['role'], $_SESSION['school_id'], $_SESSION['auth_user']
 *
 * Legacy keys (still read for existing sessions):
 *   owner_auth_user, teacher_auth_user, parent_auth_user, reception_auth_user, accounts_auth_user
 */
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/config.php';
}
if (!function_exists('ensure_session_started')) {
    require_once __DIR__ . '/functions.php';
}

/** Roles that may access the reception panel (staff uses reception login). */
const AUTH_RECEPTION_ROLES = ['reception', 'staff'];

/** Map role → login page path (relative to app root). */
function auth_role_login_path(string $role): string
{
    $map = [
        'owner'     => '/owner/login.php',
        'accounts'  => '/accounts/login.php',
        'teacher'   => '/teacher/login.php',
        'reception' => '/reception/login.php',
        'staff'     => '/reception/login.php',
        'parent'    => '/parent/login.php',
    ];
    return $map[$role] ?? '/login.php';
}

function auth_login_url(string $role): string
{
    $path = auth_role_login_path($role);
    if (function_exists('site_url')) {
        return site_url($path);
    }
    if (defined('BASE_URL')) {
        return rtrim((string) BASE_URL, '/') . $path;
    }
    return $path;
}

/**
 * Build legacy role-specific session payload from user row.
 *
 * @param array<string,mixed> $user
 * @return array<string,mixed>
 */
function auth_legacy_user_payload(array $user): array
{
    return [
        'id'          => (int) ($user['id'] ?? 0),
        'name'        => (string) ($user['name'] ?? ''),
        'phone'       => (string) ($user['phone'] ?? ''),
        'whatsapp_id' => (string) ($user['whatsapp_id'] ?? ''),
        'role'        => (string) ($user['role'] ?? ''),
        'school_id'   => (int) ($user['school_id'] ?? 1),
    ];
}

/**
 * Set authenticated session after successful OTP verification.
 *
 * @param array<string,mixed> $user DB user row (id, name, phone, role, school_id, …)
 */
function auth_set_session(array $user): void
{
    ensure_session_started();

    if (empty($user['id']) || empty($user['role'])) {
        throw new InvalidArgumentException('auth_set_session requires user id and role.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $role = strtolower((string) $user['role']);
    $payload = auth_legacy_user_payload($user);

    $_SESSION['user_id']    = (int) $user['id'];
    $_SESSION['role']       = $role;
    $_SESSION['school_id']  = (int) ($user['school_id'] ?? 1);
    $_SESSION['user_name']  = (string) ($user['name'] ?? '');
    $_SESSION['user_phone'] = (string) ($user['phone'] ?? '');
    $_SESSION['auth_user']  = $payload;
    $_SESSION['last_activity'] = time();

    switch ($role) {
        case 'owner':
            $_SESSION['owner_auth_user'] = $payload;
            break;
        case 'teacher':
            $_SESSION['teacher_user_id']   = (int) $user['id'];
            $_SESSION['teacher_auth_user'] = $payload;
            break;
        case 'parent':
            $_SESSION['parent_user_id']   = (int) $user['id'];
            $_SESSION['parent_auth_user'] = $payload;
            break;
        case 'reception':
        case 'staff':
            $_SESSION['reception_user_id']   = (int) $user['id'];
            $_SESSION['reception_auth_user'] = $payload;
            break;
        case 'accounts':
            $_SESSION['accounts_user_id']   = (int) $user['id'];
            $_SESSION['accounts_auth_user'] = $payload;
            break;
    }
}

/**
 * Clear all authentication session data.
 */
function auth_clear_session(): void
{
    ensure_session_started();

    $keys = [
        'user_id', 'role', 'school_id', 'user_name', 'user_phone', 'auth_user', 'last_activity',
        'owner_auth_user',
        'teacher_user_id', 'teacher_auth_user',
        'parent_user_id', 'parent_auth_user',
        'reception_user_id', 'reception_auth_user',
        'accounts_user_id', 'accounts_auth_user',
    ];
    foreach ($keys as $key) {
        unset($_SESSION[$key]);
    }
}

/**
 * Current authenticated user id (unified or legacy).
 */
function auth_user_id(): ?int
{
    ensure_session_started();

    if (!empty($_SESSION['user_id'])) {
        return (int) $_SESSION['user_id'];
    }

    foreach (['owner_auth_user', 'teacher_auth_user', 'parent_auth_user', 'reception_auth_user', 'accounts_auth_user'] as $legacyKey) {
        if (empty($_SESSION[$legacyKey])) {
            continue;
        }
        $v = $_SESSION[$legacyKey];
        if (is_array($v) && !empty($v['id'])) {
            return (int) $v['id'];
        }
        if (is_numeric($v)) {
            return (int) $v;
        }
    }

    foreach (['teacher_user_id', 'parent_user_id', 'reception_user_id', 'accounts_user_id'] as $idKey) {
        if (!empty($_SESSION[$idKey])) {
            return (int) $_SESSION[$idKey];
        }
    }

    return null;
}

/**
 * Current authenticated role (unified or inferred from legacy keys).
 */
function auth_role(): ?string
{
    ensure_session_started();

    if (!empty($_SESSION['role'])) {
        return strtolower((string) $_SESSION['role']);
    }

    if (!empty($_SESSION['owner_auth_user'])) {
        return 'owner';
    }
    if (!empty($_SESSION['teacher_auth_user'])) {
        return 'teacher';
    }
    if (!empty($_SESSION['parent_auth_user'])) {
        return 'parent';
    }
    if (!empty($_SESSION['reception_auth_user'])) {
        $au = $_SESSION['reception_auth_user'];
        if (is_array($au) && !empty($au['role'])) {
            return strtolower((string) $au['role']);
        }
        return 'reception';
    }
    if (!empty($_SESSION['accounts_auth_user'])) {
        return 'accounts';
    }

    return null;
}

/**
 * School id for current session.
 */
function auth_school_id(): int
{
    ensure_session_started();
    if (!empty($_SESSION['school_id'])) {
        return (int) $_SESSION['school_id'];
    }
    $au = $_SESSION['auth_user'] ?? null;
    if (is_array($au) && !empty($au['school_id'])) {
        return (int) $au['school_id'];
    }
    return 1;
}

/**
 * Full auth user array.
 *
 * @return array<string,mixed>|null
 */
function auth_user(): ?array
{
    ensure_session_started();

    if (!empty($_SESSION['auth_user']) && is_array($_SESSION['auth_user'])) {
        return $_SESSION['auth_user'];
    }

    $role = auth_role();
    if ($role === null) {
        return null;
    }

    $legacyMap = [
        'owner'     => 'owner_auth_user',
        'teacher'   => 'teacher_auth_user',
        'parent'    => 'parent_auth_user',
        'reception' => 'reception_auth_user',
        'staff'     => 'reception_auth_user',
        'accounts'  => 'accounts_auth_user',
    ];
    $key = $legacyMap[$role] ?? null;
    if ($key && !empty($_SESSION[$key]) && is_array($_SESSION[$key])) {
        return $_SESSION[$key];
    }

    $uid = auth_user_id();
    if ($uid) {
        return [
            'id'        => $uid,
            'role'      => $role,
            'school_id' => auth_school_id(),
            'name'      => (string) ($_SESSION['user_name'] ?? ''),
            'phone'     => (string) ($_SESSION['user_phone'] ?? ''),
        ];
    }

    return null;
}

/**
 * Check if user is logged in for optional role(s).
 *
 * @param string|string[]|null $roles
 */
function auth_is_logged_in($roles = null): bool
{
    ensure_session_started();

    $loggedIn = auth_user_id() !== null
        || !empty($_SESSION['owner_auth_user'])
        || !empty($_SESSION['teacher_auth_user'])
        || !empty($_SESSION['parent_auth_user'])
        || !empty($_SESSION['reception_auth_user'])
        || !empty($_SESSION['accounts_auth_user']);

    if (!$loggedIn) {
        return false;
    }

    if ($roles === null) {
        return true;
    }

    if (is_string($roles)) {
        $roles = [$roles];
    }

    $current = auth_role();
    if ($current === null) {
        return false;
    }

    // Owner super-access: full preschool management from one login.
    if ($current === 'owner') {
        return true;
    }

    foreach ($roles as $r) {
        $r = strtolower((string) $r);
        if ($r === $current) {
            return true;
        }
        if ($r === 'reception' && in_array($current, AUTH_RECEPTION_ROLES, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Require authentication for one or more roles; redirect to login if not authorized.
 *
 * @param string|string[] $roles
 */
function auth_require_roles($roles, ?string $loginUrl = null): void
{
    ensure_session_started();

    if (is_string($roles)) {
        $roles = [$roles];
    }

    if (auth_is_logged_in($roles)) {
        $_SESSION['last_activity'] = time();
        return;
    }

    $primaryRole = strtolower((string) ($roles[0] ?? 'owner'));
    $target = $loginUrl ?? auth_login_url($primaryRole);

    if (function_exists('redirect')) {
        redirect($target);
    }

    if (!headers_sent()) {
        header('Location: ' . $target);
    }
    exit;
}

function require_owner_auth(): void
{
    auth_require_roles('owner');
}

function require_teacher_auth(): void
{
    auth_require_roles('teacher');
}

function require_parent_auth(): void
{
    auth_require_roles('parent');
}

function require_reception_auth(): void
{
    auth_require_roles(AUTH_RECEPTION_ROLES);
}

function require_accounts_auth(): void
{
    auth_require_roles('accounts');
}

/** Accounts pages that also allow reception staff. */
function require_accounts_or_reception_auth(): void
{
    auth_require_roles(['accounts', 'reception', 'staff']);
}

/**
 * Display name for the current user (unified session).
 */
function auth_user_name(string $default = 'User'): string
{
    $u = auth_user();
    if ($u !== null && ($u['name'] ?? '') !== '') {
        return (string) $u['name'];
    }
    if (!empty($_SESSION['user_name'])) {
        return (string) $_SESSION['user_name'];
    }
    return $default;
}

/**
 * Legacy session key for a role (for rare backward-compat writes).
 */
function auth_legacy_session_key(?string $role = null): ?string
{
    $role = strtolower((string) ($role ?? auth_role() ?? ''));
    $map = [
        'owner'     => 'owner_auth_user',
        'teacher'   => 'teacher_auth_user',
        'parent'    => 'parent_auth_user',
        'reception' => 'reception_auth_user',
        'staff'     => 'reception_auth_user',
        'accounts'  => 'accounts_auth_user',
    ];
    return $map[$role] ?? null;
}

/**
 * Sync session after profile update — does not regenerate session id.
 *
 * @param array<string,mixed> $user users table row (must match current auth_user_id)
 */
function auth_refresh_session_user(array $user): void
{
    ensure_session_started();

    $uid = auth_user_id();
    if ($uid === null || (int) ($user['id'] ?? 0) !== $uid) {
        return;
    }

    $payload = auth_legacy_user_payload($user);

    $_SESSION['user_name']  = $payload['name'];
    $_SESSION['user_phone'] = $payload['phone'];
    $_SESSION['auth_user']  = array_merge(
        is_array($_SESSION['auth_user'] ?? null) ? $_SESSION['auth_user'] : [],
        $payload
    );

    $role = auth_role();
    $legacyKey = auth_legacy_session_key($role);
    if ($legacyKey !== null) {
        $_SESSION[$legacyKey] = array_merge(
            is_array($_SESSION[$legacyKey] ?? null) ? $_SESSION[$legacyKey] : [],
            $payload
        );
    }

    $idKeys = [
        'teacher'   => 'teacher_user_id',
        'parent'    => 'parent_user_id',
        'reception' => 'reception_user_id',
        'staff'     => 'reception_user_id',
        'accounts'  => 'accounts_user_id',
    ];
    if ($role !== null && isset($idKeys[$role])) {
        $_SESSION[$idKeys[$role]] = $uid;
    }
}

/**
 * Pass-through gate: require role login unless $allow is true (e.g. public form POST).
 *
 * @param string|string[] $roles
 */
function auth_gate(bool $allow, $roles): void
{
    if ($allow) {
        return;
    }
    auth_require_roles($roles);
}

/* End of includes/auth.php */
