<?php
/**
 * Owner super-access scope helpers.
 * Owner login can open Reception, Accounts, Teacher and Parent modules.
 */
declare(strict_types=1);

function auth_is_owner_super(): bool
{
    return function_exists('auth_role')
        && auth_role() === 'owner'
        && function_exists('auth_user_id')
        && auth_user_id() !== null;
}

/**
 * Parent user id for parent-panel queries (owner may impersonate via session/GET).
 */
function panel_parent_context_id(): int
{
    if (!auth_is_owner_super()) {
        return auth_user_id() ?? 0;
    }

    if (!empty($_GET['parent_id'])) {
        $pid = (int) $_GET['parent_id'];
        if ($pid > 0) {
            $_SESSION['owner_view_parent_id'] = $pid;
        }
    }

    return (int) ($_SESSION['owner_view_parent_id'] ?? 0);
}

/**
 * All school classes (columns that actually exist).
 *
 * @return list<array<string, mixed>>
 */
function panel_classes_all(): array
{
    if (!function_exists('table_exists') || !table_exists('classes')) {
        return [];
    }
    $cols = ['id', 'name'];
    foreach (['section', 'short_name', 'age_group', 'teacher_id'] as $c) {
        if (function_exists('column_exists') && column_exists('classes', $c)) {
            $cols[] = $c;
        }
    }
    $sql = 'SELECT ' . implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $cols)) . ' FROM classes';
    $params = [];
    if (function_exists('column_exists') && column_exists('classes', 'school_id') && function_exists('auth_school_id')) {
        $sid = (int) auth_school_id();
        if ($sid > 0) {
            $sql .= ' WHERE school_id = :sid';
            $params[':sid'] = $sid;
        }
    }
    $sql .= ' ORDER BY name ASC';
    return safe_db_get_all($sql, $params) ?: [];
}

/**
 * Classes visible to a teacher. Owner (small school) sees every class and can pick one.
 *
 * @return array<int, array<string, mixed>>
 */
function panel_teacher_assigned_classes(int $teacherId): array
{
    if (auth_is_owner_super()) {
        return panel_classes_all();
    }

    $assignedClasses = [];
    $all = panel_classes_all();
    $byId = [];
    foreach ($all as $row) {
        $byId[(int) ($row['id'] ?? 0)] = $row;
    }

    if ($teacherId > 0 && function_exists('column_exists') && column_exists('classes', 'teacher_id')) {
        foreach ($all as $row) {
            if ((int) ($row['teacher_id'] ?? 0) === $teacherId) {
                $assignedClasses[] = $row;
            }
        }
    }

    if ($assignedClasses === [] && $teacherId > 0 && function_exists('table_exists') && table_exists('teacher_classes')) {
        $maps = safe_db_get_all(
            'SELECT class_id FROM teacher_classes WHERE teacher_id = :tid',
            [':tid' => $teacherId]
        ) ?: [];
        $seen = [];
        foreach ($maps as $m) {
            $cid = (int) ($m['class_id'] ?? 0);
            if ($cid <= 0 || isset($seen[$cid])) {
                continue;
            }
            $seen[$cid] = true;
            $assignedClasses[] = $byId[$cid] ?? ['id' => $cid, 'name' => 'Class #' . $cid, 'section' => '', 'short_name' => ''];
        }
    }

    return $assignedClasses;
}

function panel_teacher_empty_classes_html(): string
{
    if (auth_is_owner_super()) {
        $url = function_exists('site_url') ? site_url('/owner/class_setup.php') : '../owner/class_setup.php';
        return '<div class="alert alert-info mb-0">No classes yet. Add Playgroup / Nursery in <a href="' . e($url) . '">Class Setup</a>.</div>';
    }
    return '<div class="alert alert-info mb-0">No class is assigned yet. Ask the owner to assign you a class.</div>';
}

function panel_teacher_can_access_class(int $classId, array $allowedIds): bool
{
    if (auth_is_owner_super()) {
        return true;
    }
    return in_array($classId, $allowedIds, true);
}

/**
 * Shell sidebar role: owner always keeps owner menu.
 */
function panel_shell_role(?string $detectedRole): ?string
{
    if (auth_is_owner_super()) {
        return 'owner';
    }
    return $detectedRole;
}

/**
 * Alert when owner opens parent portal without selecting a parent.
 */
function panel_owner_parent_gate_html(): string
{
    if (!auth_is_owner_super()) {
        return '';
    }
    $url = function_exists('site_url') ? site_url('/owner/parents.php') : '../owner/parents.php';
    return '<div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2">'
        . '<span>This screen is the parent app. Parent logins are set on <strong>Parents</strong>.</span>'
        . '<a class="btn btn-sm btn-outline-primary" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Parents</a>'
        . '</div>';
}
