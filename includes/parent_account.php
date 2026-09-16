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
