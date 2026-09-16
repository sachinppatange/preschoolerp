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
 * Classes visible to a teacher; owner sees all classes.
 *
 * @return array<int, array<string, mixed>>
 */
function panel_teacher_assigned_classes(int $teacherId): array
{
    if (auth_is_owner_super() && function_exists('table_exists') && table_exists('classes')) {
        $rows = safe_db_get_all(
            "SELECT id, name, COALESCE(section, '') AS section, COALESCE(short_name, '') AS short_name
             FROM classes ORDER BY name ASC"
        );
        return $rows ?: [];
    }

    $assignedClasses = [];

    if (function_exists('table_exists') && table_exists('classes')) {
        $col = safe_db_get_one(
            "SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'classes' AND COLUMN_NAME = 'teacher_id' LIMIT 1"
        );
        if (!empty($col['COLUMN_NAME'])) {
            $assignedClasses = safe_db_get_all(
                "SELECT id, name, section, short_name FROM classes WHERE teacher_id = :tid ORDER BY name ASC",
                [':tid' => $teacherId]
            ) ?: [];
        }
    }

    if (empty($assignedClasses) && function_exists('table_exists') && table_exists('teacher_classes')) {
        $maps = safe_db_get_all(
            "SELECT class_id FROM teacher_classes WHERE teacher_id = :tid ORDER BY created_at DESC",
            [':tid' => $teacherId]
        ) ?: [];
        $classIds = array_values(array_unique(array_filter(array_map(
            static fn(array $m): int => (int) ($m['class_id'] ?? 0),
            $maps
        ))));

        if (!empty($classIds) && table_exists('classes')) {
            $placeholders = implode(',', array_fill(0, count($classIds), '?'));
            $rows = safe_db_get_all(
                "SELECT id, name, section, short_name FROM classes WHERE id IN ($placeholders) ORDER BY name ASC",
                $classIds
            ) ?: [];
            $byId = [];
            foreach ($rows as $r) {
                $byId[(int) $r['id']] = $r;
            }
            foreach ($classIds as $cid) {
                $assignedClasses[] = $byId[$cid] ?? ['id' => $cid, 'name' => 'Class #' . $cid, 'section' => '', 'short_name' => ''];
            }
        } else {
            foreach ($classIds as $cid) {
                $assignedClasses[] = ['id' => $cid, 'name' => 'Class #' . $cid, 'section' => '', 'short_name' => ''];
            }
        }
    }

    return $assignedClasses;
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
    if (!auth_is_owner_super() || panel_parent_context_id() > 0) {
        return '';
    }
    $hub = function_exists('site_url') ? site_url('/owner/parent_portal.php') : '/owner/parent_portal.php';
    return '<div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2">'
        . '<span><i class="bi bi-exclamation-triangle me-1"></i>Select a parent first to preview this page.</span>'
        . '<a class="btn btn-sm btn-warning" href="' . htmlspecialchars($hub, ENT_QUOTES, 'UTF-8') . '">Open Parent Hub</a>'
        . '</div>';
}
