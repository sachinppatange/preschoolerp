<?php
/**
 * Parent login account helpers.
 * One mobile number (last 10 digits) = one parent user = all siblings on parent portal.
 */
declare(strict_types=1);

function parent_phone_last10(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) >= 10) {
        return substr($digits, -10);
    }
    return $digits;
}

/**
 * @return list<string>
 */
function parent_phone_variants(string $last10): array
{
    if (strlen($last10) !== 10) {
        return array_values(array_filter([$last10]));
    }
    return array_values(array_unique([
        $last10,
        '0' . $last10,
        '91' . $last10,
        '+91' . $last10,
        '+' . $last10,
    ]));
}

function parent_find_by_last10(\PDO $pdo, string $last10, string $role = 'parent'): ?array
{
    if (strlen($last10) !== 10) {
        return null;
    }
    $variants = parent_phone_variants($last10);
    $ph = implode(',', array_fill(0, count($variants), '?'));
    $params = array_merge([$role], $variants, $variants, [$last10]);

    $sql = "SELECT * FROM users WHERE role = ?
              AND (
                phone IN ($ph)
                OR whatsapp_id IN ($ph)
                OR (CHAR_LENGTH(phone) >= 10 AND RIGHT(REPLACE(REPLACE(REPLACE(IFNULL(phone,''), '+', ''), ' ', ''), '-', ''), 10) = ?)
              )
            ORDER BY id ASC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Find existing parent by mobile, or create one.
 *
 * @param array{name:string,phone:string,school_id?:int|null,whatsapp_id?:string} $data
 * @return array{id:int,created:bool,existing:bool}
 */
function parent_find_or_create(\PDO $pdo, array $data): array
{
    $name = trim((string) ($data['name'] ?? ''));
    $last10 = parent_phone_last10((string) ($data['phone'] ?? ''));
    if (strlen($last10) !== 10) {
        throw new InvalidArgumentException('Enter a valid 10-digit parent mobile number.');
    }
    if ($name === '') {
        $name = 'Parent ' . $last10;
    }

    $variants = parent_phone_variants($last10);
    $ph = implode(',', array_fill(0, count($variants), '?'));
    $stmt = $pdo->prepare("SELECT id, name, role, is_active FROM users WHERE phone IN ($ph) OR whatsapp_id IN ($ph) ORDER BY id ASC LIMIT 1");
    $stmt->execute(array_merge($variants, $variants));
    $any = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($any && strtolower((string) $any['role']) !== 'parent') {
        throw new RuntimeException('This mobile number is already used by a ' . $any['role'] . ' account. Use a different parent login number.');
    }

    $found = parent_find_by_last10($pdo, $last10, 'parent');
    if ($found) {
        $id = (int) $found['id'];
        $sets = [];
        $params = [':id' => $id];
        if (trim((string) ($found['name'] ?? '')) === '' && $name !== '') {
            $sets[] = 'name = :name';
            $params[':name'] = $name;
        }
        $hasLast10 = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone_last10'");
            $hasLast10 = $chk && $chk->rowCount() > 0;
        } catch (Throwable $e) {
            $hasLast10 = false;
        }
        if ($hasLast10 && empty($found['phone_last10'])) {
            $sets[] = 'phone_last10 = :p10';
            $params[':p10'] = $last10;
        }
        if ($sets) {
            $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id')->execute($params);
        }
        return ['id' => $id, 'created' => false, 'existing' => true];
    }

    $schoolId = isset($data['school_id']) && $data['school_id'] !== null && $data['school_id'] !== ''
        ? (int) $data['school_id']
        : 1;
    if ($schoolId <= 0) {
        $schoolId = 1;
    }
    $storePhone = $last10;
    $wa = parent_phone_last10((string) ($data['whatsapp_id'] ?? ''));
    $waStore = strlen($wa) === 10 ? ('91' . $wa) : ('91' . $last10);

    $hasLast10 = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone_last10'");
        $hasLast10 = $chk && $chk->rowCount() > 0;
    } catch (Throwable $e) {
        $hasLast10 = false;
    }

    if ($hasLast10) {
        $ins = $pdo->prepare("INSERT INTO users (school_id, name, phone, role, whatsapp_id, is_active, phone_last10, meta, created_at, updated_at)
            VALUES (:school_id, :name, :phone, 'parent', :whatsapp, 1, :p10, NULL, NOW(), NOW())");
        $ins->execute([
            ':school_id' => $schoolId,
            ':name' => $name,
            ':phone' => $storePhone,
            ':whatsapp' => $waStore,
            ':p10' => $last10,
        ]);
    } else {
        $ins = $pdo->prepare("INSERT INTO users (school_id, name, phone, role, whatsapp_id, is_active, meta, created_at, updated_at)
            VALUES (:school_id, :name, :phone, 'parent', :whatsapp, 1, NULL, NOW(), NOW())");
        $ins->execute([
            ':school_id' => $schoolId,
            ':name' => $name,
            ':phone' => $storePhone,
            ':whatsapp' => $waStore,
        ]);
    }

    return ['id' => (int) $pdo->lastInsertId(), 'created' => true, 'existing' => false];
}

function parent_link_student(\PDO $pdo, int $parentId, int $studentId, string $relation = 'parent'): void
{
    $mapStmt = $pdo->prepare('SELECT id FROM parents_children WHERE parent_user_id = :p AND child_student_id = :c LIMIT 1');
    $mapStmt->execute([':p' => $parentId, ':c' => $studentId]);
    if (!$mapStmt->fetch(\PDO::FETCH_ASSOC)) {
        $ins = $pdo->prepare('INSERT INTO parents_children (parent_user_id, child_student_id, relation, created_at) VALUES (:p,:c,:r,NOW())');
        $ins->execute([':p' => $parentId, ':c' => $studentId, ':r' => $relation !== '' ? $relation : 'parent']);
    }
}

/** Academic year used to decide parent portal login (June–May, based on today). */
function parent_login_academic_year(): string
{
    if (function_exists('ay_current')) {
        return ay_current();
    }
    $y = (int) date('Y');
    $m = (int) date('n');
    $start = $m >= 6 ? $y : $y - 1;
    return sprintf('%04d-%02d', $start, ($start + 1) % 100);
}

function parent_ay_sql_ok(string $ay): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}$/', $ay);
}

/**
 * Parent user ids that have at least one child in the given academic year.
 *
 * @return list<int>
 */
function parent_ids_with_child_in_year(string $ay): array
{
    if (!parent_ay_sql_ok($ay) || !function_exists('table_exists') || !table_exists('students')) {
        return [];
    }
    $ids = [];
    try {
        $rows = safe_db_get_all(
            'SELECT DISTINCT parent_id AS id FROM students
             WHERE academic_year = :ay AND parent_id IS NOT NULL AND parent_id > 0',
            [':ay' => $ay]
        ) ?: [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if (table_exists('parents_children')) {
            $rows = safe_db_get_all(
                'SELECT DISTINCT pc.parent_user_id AS id
                 FROM parents_children pc
                 INNER JOIN students s ON s.id = pc.child_student_id
                 WHERE s.academic_year = :ay AND pc.parent_user_id > 0',
                [':ay' => $ay]
            ) ?: [];
            foreach ($rows as $r) {
                $id = (int) ($r['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
    } catch (Throwable $e) {
        return array_values($ids);
    }
    return array_values($ids);
}

function parent_has_child_in_year(int $parentId, ?string $ay = null): bool
{
    if ($parentId <= 0) {
        return false;
    }
    $ay = $ay ?? parent_login_academic_year();
    return in_array($parentId, parent_ids_with_child_in_year($ay), true);
}

function parent_merge_meta(?string $json, array $patch): string
{
    $meta = [];
    if (is_string($json) && $json !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    foreach ($patch as $k => $v) {
        $meta[$k] = $v;
    }
    return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

/**
 * Enable parent login only when they have a child in $ay.
 * Others are auto-disabled. Manual disable (login_locked) is not auto-enabled.
 *
 * @return array{disabled:int,enabled:int,current:int}
 */
function parent_sync_logins_for_year(?string $ay = null): array
{
    $ay = $ay ?? parent_login_academic_year();
    $keep = array_fill_keys(parent_ids_with_child_in_year($ay), true);
    $disabled = 0;
    $enabled = 0;
    $parents = [];
    try {
        $parents = safe_db_get_all("SELECT id, is_active, meta FROM users WHERE role = 'parent'") ?: [];
    } catch (Throwable $e) {
        return ['disabled' => 0, 'enabled' => 0, 'current' => count($keep)];
    }
    foreach ($parents as $p) {
        $id = (int) ($p['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $active = (int) ($p['is_active'] ?? 0) === 1;
        $meta = [];
        if (!empty($p['meta'])) {
            $decoded = json_decode((string) $p['meta'], true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        $locked = !empty($meta['login_locked']);
        $hasChild = isset($keep[$id]);
        if (!$hasChild && $active) {
            safe_db_run('UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = :id AND role = :r', [':id' => $id, ':r' => 'parent']);
            $disabled++;
        } elseif ($hasChild && !$active && !$locked) {
            safe_db_run('UPDATE users SET is_active = 1, updated_at = NOW() WHERE id = :id AND role = :r', [':id' => $id, ':r' => 'parent']);
            $enabled++;
        }
    }
    return ['disabled' => $disabled, 'enabled' => $enabled, 'current' => count($keep)];
}

function parent_portal_login_allowed(?array $user): bool
{
    if (!$user) {
        return false;
    }
    if (strtolower((string) ($user['role'] ?? '')) !== 'parent') {
        return true;
    }
    if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
        return false;
    }
    return parent_has_child_in_year((int) ($user['id'] ?? 0));
}

function parent_portal_blocked_message(): string
{
    $ay = parent_login_academic_year();
    $label = function_exists('ay_display_long') ? ay_display_long($ay) : ('Academic Year ' . $ay);
    return 'Parent portal login is only for families with a child in ' . $label . '. Contact the school if this is a new admission.';
}
