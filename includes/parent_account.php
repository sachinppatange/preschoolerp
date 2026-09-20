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

function parent_normalize_email(string $raw): string
{
    $email = strtolower(trim($raw));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function parent_meta_with_email(?string $existingJson, string $email): ?string
{
    $email = parent_normalize_email($email);
    if ($email === '') {
        return $existingJson;
    }
    if (function_exists('staff_meta_with_email')) {
        return staff_meta_with_email($existingJson, $email);
    }
    $meta = [];
    if (is_string($existingJson) && $existingJson !== '') {
        $decoded = json_decode($existingJson, true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    $meta['email'] = $email;
    return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function parent_email_from_user_row(array $row): string
{
    if (function_exists('staff_user_email_from_meta')) {
        $fromMeta = staff_user_email_from_meta($row['meta'] ?? null);
        if ($fromMeta !== '') {
            return $fromMeta;
        }
    }
    return parent_normalize_email((string) ($row['email'] ?? ''));
}

/**
 * First valid email on linked students (father, then mother, then guardian).
 *
 * @param list<int> $parentIds
 * @return array<int,string>
 */
function parent_emails_from_students(array $parentIds): array
{
    $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds))));
    $out = [];
    if ($parentIds === [] || !function_exists('table_exists') || !table_exists('students')) {
        return $out;
    }
    $in = implode(',', $parentIds);
    $rows = safe_db_get_all(
        "SELECT parent_id, father_email, mother_email, guardian_email
         FROM students
         WHERE parent_id IN ($in)
         ORDER BY id DESC"
    ) ?: [];
    foreach ($rows as $r) {
        $pid = (int) ($r['parent_id'] ?? 0);
        if ($pid <= 0 || isset($out[$pid])) {
            continue;
        }
        foreach (['father_email', 'mother_email', 'guardian_email'] as $col) {
            $email = parent_normalize_email((string) ($r[$col] ?? ''));
            if ($email !== '') {
                $out[$pid] = $email;
                break;
            }
        }
    }
    return $out;
}

function parent_display_email(array $row, array $studentEmails = []): string
{
    $fromUser = parent_email_from_user_row($row);
    if ($fromUser !== '') {
        return $fromUser;
    }
    $id = (int) ($row['id'] ?? 0);
    return $studentEmails[$id] ?? '';
}

/**
 * Find existing parent by mobile, or create one.
 *
 * @param array{name:string,phone:string,school_id?:int|null,whatsapp_id?:string,email?:string} $data
 * @return array{id:int,created:bool,existing:bool}
 */
function parent_find_or_create(\PDO $pdo, array $data): array
{
    $name = trim((string) ($data['name'] ?? ''));
    $last10 = parent_phone_last10((string) ($data['phone'] ?? ''));
    $wa = parent_phone_last10((string) ($data['whatsapp_id'] ?? ''));
    $email = parent_normalize_email((string) ($data['email'] ?? ''));
    if (strlen($last10) !== 10 && strlen($wa) === 10) {
        $last10 = $wa;
    }
    if (strlen($last10) !== 10) {
        throw new InvalidArgumentException('Enter a valid 10-digit parent mobile number.');
    }
    if ($name === '') {
        $name = 'Parent ' . $last10;
    }

    $variants = parent_phone_variants($last10);
    if (strlen($wa) === 10) {
        $variants = array_values(array_unique(array_merge($variants, parent_phone_variants($wa))));
    }
    $ph = implode(',', array_fill(0, count($variants), '?'));
    $stmt = $pdo->prepare("SELECT id, name, role, is_active FROM users WHERE phone IN ($ph) OR whatsapp_id IN ($ph) ORDER BY id ASC LIMIT 1");
    $stmt->execute(array_merge($variants, $variants));
    $any = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($any && strtolower((string) $any['role']) !== 'parent') {
        throw new RuntimeException('This mobile number is already used by a ' . $any['role'] . ' account. Use a different parent login number.');
    }

    $found = parent_find_by_last10($pdo, $last10, 'parent');
    if (!$found && strlen($wa) === 10) {
        $found = parent_find_by_last10($pdo, $wa, 'parent');
    }
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
        if (strlen($wa) === 10) {
            $sets[] = 'whatsapp_id = :wa';
            $params[':wa'] = '91' . $wa;
        }
        if ($email !== '') {
            $sets[] = 'meta = :meta';
            $params[':meta'] = parent_meta_with_email($found['meta'] ?? null, $email);
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
    $waStore = strlen($wa) === 10 ? ('91' . $wa) : ('91' . $last10);
    $metaJson = $email !== '' ? parent_meta_with_email(null, $email) : null;

    $hasLast10 = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone_last10'");
        $hasLast10 = $chk && $chk->rowCount() > 0;
    } catch (Throwable $e) {
        $hasLast10 = false;
    }

    if ($hasLast10) {
        $ins = $pdo->prepare("INSERT INTO users (school_id, name, phone, role, whatsapp_id, is_active, phone_last10, meta, created_at, updated_at)
            VALUES (:school_id, :name, :phone, 'parent', :whatsapp, 1, :p10, :meta, NOW(), NOW())");
        $ins->execute([
            ':school_id' => $schoolId,
            ':name' => $name,
            ':phone' => $storePhone,
            ':whatsapp' => $waStore,
            ':p10' => $last10,
            ':meta' => $metaJson,
        ]);
    } else {
        $ins = $pdo->prepare("INSERT INTO users (school_id, name, phone, role, whatsapp_id, is_active, meta, created_at, updated_at)
            VALUES (:school_id, :name, :phone, 'parent', :whatsapp, 1, :meta, NOW(), NOW())");
        $ins->execute([
            ':school_id' => $schoolId,
            ':name' => $name,
            ':phone' => $storePhone,
            ':whatsapp' => $waStore,
            ':meta' => $metaJson,
        ]);
    }

    $newId = (int) $pdo->lastInsertId();
    if ($newId <= 0) {
        $created = parent_find_by_last10($pdo, $last10, 'parent');
        $newId = (int) ($created['id'] ?? 0);
    }
    return ['id' => $newId, 'created' => $newId > 0, 'existing' => false];
}

function parent_find_by_name(\PDO $pdo, string $name): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'parent' AND LOWER(TRIM(name)) = LOWER(:n) ORDER BY id DESC LIMIT 1");
    $stmt->execute([':n' => $name]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Create or reuse a parent login. Phone/WhatsApp/email are optional.
 * Same 10-digit mobile = same parent (siblings). If only a name is given, that name is used.
 */
function parent_resolve_login(\PDO $pdo, array $data): int
{
    $name = trim((string) ($data['name'] ?? ''));
    $phone = parent_phone_last10((string) ($data['phone'] ?? ''));
    $wa = parent_phone_last10((string) ($data['whatsapp_id'] ?? ''));
    $email = parent_normalize_email((string) ($data['email'] ?? ''));
    $existingId = (int) ($data['existing_id'] ?? 0);
    if (strlen($phone) === 10 || strlen($wa) === 10) {
        $acc = parent_find_or_create($pdo, $data);
        return (int) ($acc['id'] ?? 0);
    }
    if ($existingId > 0) {
        $row = safe_db_get_one("SELECT * FROM users WHERE id = :id AND role = 'parent' LIMIT 1", [':id' => $existingId]);
        if ($row) {
            $sets = [];
            $params = [':id' => $existingId];
            if ($name !== '' && trim((string) ($row['name'] ?? '')) !== $name) {
                $sets[] = 'name = :name';
                $params[':name'] = $name;
            }
            if ($email !== '') {
                $sets[] = 'meta = :meta';
                $params[':meta'] = parent_meta_with_email($row['meta'] ?? null, $email);
            }
            if ($sets) {
                $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id')->execute($params);
            }
            return $existingId;
        }
    }
    if ($name === '') {
        return 0;
    }
    $found = parent_find_by_name($pdo, $name);
    if ($found) {
        if ($email !== '') {
            $pdo->prepare('UPDATE users SET meta = :meta, updated_at = NOW() WHERE id = :id')->execute([
                ':meta' => parent_meta_with_email($found['meta'] ?? null, $email),
                ':id' => (int) $found['id'],
            ]);
        }
        return (int) $found['id'];
    }

    $schoolId = (int) ($data['school_id'] ?? 1);
    if ($schoolId <= 0) {
        $schoolId = 1;
    }
    $metaJson = $email !== '' ? parent_meta_with_email(null, $email) : null;
    $hasLast10 = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone_last10'");
        $hasLast10 = $chk && $chk->rowCount() > 0;
    } catch (Throwable $e) {
        $hasLast10 = false;
    }
    if ($hasLast10) {
        $ins = $pdo->prepare("INSERT INTO users (school_id, name, phone, role, whatsapp_id, is_active, phone_last10, meta, created_at, updated_at)
            VALUES (:school_id, :name, NULL, 'parent', NULL, 1, NULL, :meta, NOW(), NOW())");
        $ins->execute([':school_id' => $schoolId, ':name' => $name, ':meta' => $metaJson]);
    } else {
        $ins = $pdo->prepare("INSERT INTO users (school_id, name, phone, role, whatsapp_id, is_active, meta, created_at, updated_at)
            VALUES (:school_id, :name, NULL, 'parent', NULL, 1, :meta, NOW(), NOW())");
        $ins->execute([':school_id' => $schoolId, ':name' => $name, ':meta' => $metaJson]);
    }
    $newId = (int) $pdo->lastInsertId();
    if ($newId <= 0) {
        $created = parent_find_by_name($pdo, $name);
        $newId = (int) ($created['id'] ?? 0);
    }
    return $newId;
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

/** Academic year used to decide parent portal login (based on today). */
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
    if (!parent_ay_sql_ok($ay)) {
        return [];
    }
    $ids = [];
    $add = static function (array $rows) use (&$ids): void {
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    };
    try {
        $add(parent_query_all(
            'SELECT DISTINCT parent_id AS id FROM students
             WHERE academic_year = :ay AND parent_id IS NOT NULL AND parent_id > 0',
            [':ay' => $ay]
        ));
        $add(parent_query_all(
            'SELECT DISTINCT pc.parent_user_id AS id
             FROM parents_children pc
             INNER JOIN students s ON s.id = pc.child_student_id
             WHERE s.academic_year = :ay AND pc.parent_user_id > 0',
            [':ay' => $ay]
        ));
    } catch (Throwable $e) {
        return array_values($ids);
    }
    return array_values($ids);
}

/**
 * @param array<string,mixed> $params
 * @return list<array<string,mixed>>
 */
function parent_query_all(string $sql, array $params = []): array
{
    if (function_exists('safe_db_get_all')) {
        try {
            return safe_db_get_all($sql, $params) ?: [];
        } catch (Throwable $e) {
            // fall through
        }
    }
    if (function_exists('db_fetch_all')) {
        try {
            return db_fetch_all($sql, $params) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
    return [];
}

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>|null
 */
function parent_query_one(string $sql, array $params = []): ?array
{
    if (function_exists('safe_db_get_one')) {
        try {
            $row = safe_db_get_one($sql, $params);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            // fall through
        }
    }
    if (function_exists('db_fetch_one')) {
        try {
            $row = db_fetch_one($sql, $params);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
    return null;
}

function parent_has_child_in_year(int $parentId, ?string $ay = null): bool
{
    if ($parentId <= 0) {
        return false;
    }
    $ay = $ay ?? parent_login_academic_year();
    if (!parent_ay_sql_ok($ay)) {
        return false;
    }
    try {
        $row = parent_query_one(
            'SELECT id FROM students
             WHERE parent_id = :p AND academic_year = :ay
             LIMIT 1',
            [':p' => $parentId, ':ay' => $ay]
        );
        if ($row) {
            return true;
        }
        $row = parent_query_one(
            'SELECT pc.id
             FROM parents_children pc
             INNER JOIN students s ON s.id = pc.child_student_id
             WHERE pc.parent_user_id = :p AND s.academic_year = :ay
             LIMIT 1',
            [':p' => $parentId, ':ay' => $ay]
        );
        return (bool) $row;
    } catch (Throwable $e) {
        return false;
    }
}

function parent_linked_student_count(int $parentId): int
{
    if ($parentId <= 0 || !function_exists('table_exists') || !table_exists('students')) {
        return 0;
    }
    try {
        $row = safe_db_get_one('SELECT COUNT(*) AS c FROM students WHERE parent_id = :id', [':id' => $parentId]);
        return (int) ($row['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function parent_delete_block_reason(int $id): ?string
{
    $row = $id > 0 ? safe_db_get_one("SELECT id, role FROM users WHERE id = :id LIMIT 1", [':id' => $id]) : null;
    if (!$row || strtolower((string) ($row['role'] ?? '')) !== 'parent') {
        return 'Parent not found.';
    }
    $n = parent_linked_student_count($id);
    if ($n > 0) {
        return 'This parent has ' . $n . ' linked child' . ($n === 1 ? '' : 'ren') . '. Disable login instead of deleting.';
    }
    return null;
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

/**
 * @return array{name:string,phone:string,whatsapp_id:string,email:string}
 */
function parent_contact_from_student_row(array $st): array
{
    $extra = [];
    if (!empty($st['extended_json']) && is_string($st['extended_json'])) {
        $decoded = json_decode($st['extended_json'], true);
        if (is_array($decoded)) {
            $extra = is_array($decoded['parent_login'] ?? null) ? $decoded['parent_login'] : [];
        }
    }
    $phone = parent_phone_last10((string) ($extra['phone'] ?? ''));
    if (strlen($phone) !== 10) {
        foreach (['father_phone', 'mother_phone', 'guardian_phone'] as $col) {
            $phone = parent_phone_last10((string) ($st[$col] ?? ''));
            if (strlen($phone) === 10) {
                break;
            }
        }
    }
    $wa = parent_phone_last10((string) ($extra['whatsapp'] ?? $extra['whatsapp_id'] ?? ''));
    if (strlen($wa) !== 10) {
        $wa = $phone;
    }
    $email = parent_normalize_email((string) ($extra['email'] ?? ''));
    if ($email === '') {
        foreach (['father_email', 'mother_email', 'guardian_email'] as $col) {
            $email = parent_normalize_email((string) ($st[$col] ?? ''));
            if ($email !== '') {
                break;
            }
        }
    }
    $name = trim((string) ($extra['name'] ?? ''));
    if ($name === '') {
        $fn = trim((string) ($st['father_first'] ?? '') . ' ' . (string) ($st['father_last'] ?? ''));
        $mn = trim((string) ($st['mother_first'] ?? '') . ' ' . (string) ($st['mother_last'] ?? ''));
        $name = $fn !== '' ? $fn : ($mn !== '' ? $mn : trim((string) ($st['guardian_name'] ?? '')));
    }
    return [
        'name' => $name,
        'phone' => $phone,
        'whatsapp_id' => $wa,
        'email' => $email,
    ];
}

/**
 * Create/link a parent login for a student that was saved without a parent user.
 */
function parent_ensure_for_student(\PDO $pdo, array $student): int
{
    $studentId = (int) ($student['id'] ?? 0);
    $parentId = (int) ($student['parent_id'] ?? 0);
    if ($parentId > 0) {
        $existing = safe_db_get_one("SELECT id, role FROM users WHERE id = :id LIMIT 1", [':id' => $parentId]);
        if ($existing && strtolower((string) ($existing['role'] ?? '')) === 'parent') {
            if ($studentId > 0) {
                parent_link_student($pdo, $parentId, $studentId, 'parent');
            }
            return $parentId;
        }
        $parentId = 0;
    }

    $contact = parent_contact_from_student_row($student);
    $parentId = parent_resolve_login($pdo, [
        'name' => $contact['name'],
        'phone' => $contact['phone'],
        'whatsapp_id' => $contact['whatsapp_id'],
        'email' => $contact['email'],
        'school_id' => (int) ($student['school_id'] ?? 1),
        'existing_id' => $parentId,
    ]);
    if ($parentId <= 0 || $studentId <= 0) {
        return $parentId;
    }

    $pdo->prepare('UPDATE students SET parent_id = :p WHERE id = :id')->execute([
        ':p' => $parentId,
        ':id' => $studentId,
    ]);
    parent_link_student($pdo, $parentId, $studentId, 'parent');
    return $parentId;
}

/**
 * Repair students that have no parent login user.
 *
 * @return array{fixed:int,skipped:int}
 */
function parent_backfill_missing_logins(?string $ay = null): array
{
    $fixed = 0;
    $skipped = 0;
    if (!function_exists('table_exists') || !table_exists('students') || !table_exists('users')) {
        return ['fixed' => 0, 'skipped' => 0];
    }
    $pdo = function_exists('pdo_connect') ? pdo_connect() : null;
    if (!($pdo instanceof \PDO)) {
        return ['fixed' => 0, 'skipped' => 0];
    }
    $sql = "SELECT s.*
            FROM students s
            LEFT JOIN users u ON u.id = s.parent_id AND u.role = 'parent'
            WHERE (s.parent_id IS NULL OR s.parent_id = 0 OR u.id IS NULL)";
    $params = [];
    $sql .= ' ORDER BY s.id DESC LIMIT 500';
    $rows = safe_db_get_all($sql, $params) ?: [];
    foreach ($rows as $st) {
        try {
            $id = parent_ensure_for_student($pdo, $st);
            if ($id > 0) {
                $fixed++;
            } else {
                $skipped++;
            }
        } catch (Throwable $e) {
            $skipped++;
        }
    }
    return ['fixed' => $fixed, 'skipped' => $skipped];
}

function parent_otp_code(): string
{
    if (function_exists('staff_generate_otp')) {
        return staff_generate_otp();
    }
    $len = defined('OTP_LENGTH') ? max(4, (int) OTP_LENGTH) : 4;
    $max = (int) str_repeat('9', $len);
    return str_pad((string) random_int(0, $max), $len, '0', STR_PAD_LEFT);
}

function parent_otp_e164(string $raw): string
{
    if (function_exists('staff_e164')) {
        return staff_e164($raw);
    }
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($d) === 10) {
        return '+91' . $d;
    }
    if (strlen($d) === 12 && str_starts_with($d, '91')) {
        return '+' . $d;
    }
    return $d !== '' ? '+' . $d : '';
}

function parent_otp_store(array $ctx): void
{
    $_SESSION['parent_adm_otp'] = $ctx;
}

function parent_otp_ctx(): array
{
    if (empty($_SESSION['parent_adm_otp']) || !is_array($_SESSION['parent_adm_otp'])) {
        $_SESSION['parent_adm_otp'] = [];
    }
    return $_SESSION['parent_adm_otp'];
}

/**
 * @return array{ok:bool,error?:string,info?:string}
 */
function parent_otp_send_channel(string $channel, string $to): array
{
    $channel = strtolower(trim($channel));
    $to = trim($to);
    $ctx = parent_otp_ctx();
    if (!isset($ctx['sent_at']) || !is_array($ctx['sent_at'])) {
        $ctx['sent_at'] = [];
    }
    if (!isset($ctx['expires']) || !is_array($ctx['expires'])) {
        $ctx['expires'] = [];
    }
    $now = time();
    $last = (int) ($ctx['sent_at'][$channel] ?? 0);
    if ($last > 0 && ($now - $last) < 45) {
        $wait = 45 - ($now - $last);
        return ['ok' => false, 'error' => "Please wait {$wait} seconds before resending."];
    }

    $otp = parent_otp_code();
    $minutes = function_exists('otp_validity_minutes') ? otp_validity_minutes() : 5;
    $expires = $now + max(60, $minutes * 60);

    if ($channel === 'phone') {
        $phone10 = parent_phone_last10($to);
        if (strlen($phone10) !== 10) {
            return ['ok' => false, 'error' => 'Enter a valid 10-digit SMS mobile.'];
        }
        if (function_exists('staff_find_by_phone10')) {
            $taken = staff_find_by_phone10($phone10, 0);
            if ($taken && strtolower((string) ($taken['role'] ?? '')) !== 'parent') {
                return ['ok' => false, 'error' => 'This mobile is already used by a ' . ($taken['role'] ?? 'staff') . ' account.'];
            }
        }
        if (!function_exists('otp_send_sms')) {
            return ['ok' => false, 'error' => 'SMS OTP is not configured. Open OTP Settings.'];
        }
        $res = otp_send_sms($phone10, $otp);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => 'SMS: ' . (string) ($res['error'] ?? 'failed')];
        }
        $ctx['phone'] = $phone10;
        $ctx['phone_hash'] = password_hash($otp, PASSWORD_DEFAULT);
        $ctx['phone_ok'] = false;
    } elseif ($channel === 'whatsapp') {
        $wa10 = parent_phone_last10($to);
        if (strlen($wa10) !== 10) {
            return ['ok' => false, 'error' => 'Enter a valid 10-digit WhatsApp number.'];
        }
        if (!function_exists('otp_send_whatsapp')) {
            return ['ok' => false, 'error' => 'WhatsApp OTP is not configured. Open OTP Settings.'];
        }
        $res = otp_send_whatsapp(parent_otp_e164($wa10), $otp);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => 'WhatsApp: ' . (string) ($res['error'] ?? 'failed')];
        }
        $ctx['whatsapp'] = $wa10;
        $ctx['wa_hash'] = password_hash($otp, PASSWORD_DEFAULT);
        $ctx['wa_ok'] = false;
    } elseif ($channel === 'email') {
        $email = parent_normalize_email($to);
        if ($email === '') {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }
        if (!function_exists('otp_send_email')) {
            return ['ok' => false, 'error' => 'Email OTP is not configured. Open OTP Settings.'];
        }
        $res = otp_send_email($email, $otp);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => 'Email: ' . (string) ($res['error'] ?? 'failed')];
        }
        $ctx['email'] = $email;
        $ctx['email_hash'] = password_hash($otp, PASSWORD_DEFAULT);
        $ctx['email_ok'] = false;
    } else {
        return ['ok' => false, 'error' => 'Invalid OTP channel.'];
    }

    $ctx['sent_at'][$channel] = $now;
    $ctx['expires'][$channel] = $expires;
    parent_otp_store($ctx);
    return ['ok' => true, 'info' => 'OTP sent. Enter the code and tap Verify.'];
}

/**
 * @return array{ok:bool,error?:string,verified?:bool}
 */
function parent_otp_verify_channel(string $channel, string $otp): array
{
    $channel = strtolower(trim($channel));
    $otp = trim($otp);
    $ctx = parent_otp_ctx();
    $expires = (int) ($ctx['expires'][$channel] ?? 0);
    if ($expires <= 0 || time() > $expires) {
        return ['ok' => false, 'error' => 'OTP expired. Send OTP again.'];
    }
    if ($otp === '') {
        return ['ok' => false, 'error' => 'Enter the OTP.'];
    }
    $hashKey = $channel === 'whatsapp' ? 'wa_hash' : ($channel === 'email' ? 'email_hash' : 'phone_hash');
    $okKey = $channel === 'whatsapp' ? 'wa_ok' : ($channel === 'email' ? 'email_ok' : 'phone_ok');
    $hash = (string) ($ctx[$hashKey] ?? '');
    if ($hash === '' || !password_verify($otp, $hash)) {
        return ['ok' => false, 'error' => 'Incorrect OTP.'];
    }
    $ctx[$okKey] = true;
    parent_otp_store($ctx);
    return ['ok' => true, 'verified' => true];
}

function parent_otp_ajax_handle(): bool
{
    $action = (string) ($_POST['action'] ?? '');
    if (!in_array($action, ['parent_otp_send', 'parent_otp_verify'], true)) {
        return false;
    }
    header('Content-Type: application/json; charset=utf-8');
    $token = (string) ($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');
    $sess = (string) ($_SESSION['csrf_token'] ?? '');
    if ($sess === '' || !hash_equals($sess, $token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid session. Refresh the page.']);
        return true;
    }
    if ($action === 'parent_otp_send') {
        echo json_encode(parent_otp_send_channel((string) ($_POST['channel'] ?? ''), (string) ($_POST['to'] ?? '')));
    } else {
        echo json_encode(parent_otp_verify_channel((string) ($_POST['channel'] ?? ''), (string) ($_POST['otp'] ?? '')));
    }
    return true;
}
