<?php
/**
 * Academic Year admin: settings, stats, rollover, lock, role defaults.
 */
declare(strict_types=1);

require_once __DIR__ . '/academic_year.php';

const AY_SETTINGS_KEY = 'academic_year';
const AY_SCHOOL_ID = 1;

function ay_decode_json(mixed $val): array
{
    if ($val === null || $val === '') {
        return [];
    }
    if (is_array($val)) {
        return $val;
    }
    $decoded = json_decode((string) $val, true);

    return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
}

function ay_school_settings(): array
{
    if (array_key_exists('_ay_school_settings', $GLOBALS) && is_array($GLOBALS['_ay_school_settings'])) {
        return $GLOBALS['_ay_school_settings'];
    }
    if (!function_exists('table_exists') || !table_exists('schools')) {
        return $GLOBALS['_ay_school_settings'] = [];
    }
    try {
        $row = safe_db_get_one('SELECT settings FROM schools WHERE id = :id LIMIT 1', [':id' => AY_SCHOOL_ID]);
        $GLOBALS['_ay_school_settings'] = ay_decode_json($row['settings'] ?? null);
    } catch (Throwable $e) {
        $GLOBALS['_ay_school_settings'] = [];
    }

    return $GLOBALS['_ay_school_settings'];
}

function ay_settings_cache_clear(): void
{
    unset($GLOBALS['_ay_school_settings'], $GLOBALS['_ay_start_month']);
}

/**
 * @return array{locked_years: list<string>, role_defaults: array<string,string>, promotion_map: array<string,int|null>, graduate_class_ids: list<int>, rollover_log: list<array<string,mixed>>, start_month: int}
 */
function ay_admin_defaults(): array
{
    return [
        'locked_years' => [],
        'role_defaults' => [
            'owner' => 'current',
            'accounts' => 'current',
            'reception' => 'current',
            'teacher' => 'current',
        ],
        'promotion_map' => [],
        'graduate_class_ids' => [],
        'rollover_log' => [],
        'start_month' => 6,
    ];
}

function ay_admin_config(): array
{
    $school = ay_school_settings();
    $stored = is_array($school[AY_SETTINGS_KEY] ?? null) ? $school[AY_SETTINGS_KEY] : [];
    $cfg = array_merge(ay_admin_defaults(), $stored);
    if (!is_array($cfg['locked_years'])) {
        $cfg['locked_years'] = [];
    }
    if (!is_array($cfg['role_defaults'])) {
        $cfg['role_defaults'] = ay_admin_defaults()['role_defaults'];
    }
    if (!is_array($cfg['promotion_map'])) {
        $cfg['promotion_map'] = [];
    }
    if (!is_array($cfg['graduate_class_ids'])) {
        $cfg['graduate_class_ids'] = [];
    }
    if (!is_array($cfg['rollover_log'])) {
        $cfg['rollover_log'] = [];
    }
    $sm = (int) ($cfg['start_month'] ?? 6);
    $cfg['start_month'] = ($sm >= 1 && $sm <= 12) ? $sm : 6;

    return $cfg;
}

function ay_admin_save(array $partial): bool
{
    if (!function_exists('table_exists') || !table_exists('schools')) {
        return false;
    }
    $school = ay_school_settings();
    $current = ay_admin_config();
    $merged = array_merge($current, $partial);
    $school[AY_SETTINGS_KEY] = $merged;
    $json = json_encode($school, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $ok = (bool) safe_db_run('UPDATE schools SET settings = :s, updated_at = NOW() WHERE id = :id', [
        ':s' => $json,
        ':id' => AY_SCHOOL_ID,
    ]);
    if ($ok) {
        ay_settings_cache_clear();
        $GLOBALS['_ay_school_settings'] = $school;
        if (isset($merged['start_month'])) {
            $sm = (int) $merged['start_month'];
            $GLOBALS['_ay_start_month'] = ($sm >= 1 && $sm <= 12) ? $sm : 6;
        }
    }

    return $ok;
}

function ay_next_label(string $ay): ?string
{
    $range = ay_to_range($ay);
    if ($range === null) {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})$/', $range['label'], $m)) {
        return null;
    }
    $start = (int) $m[1] + 1;
    $end = $start + 1;

    return sprintf('%04d-%02d', $start, $end % 100);
}

function ay_is_locked(?string $ay = null): bool
{
    $ay = $ay ?? ay_selected();
    $cfg = ay_admin_config();

    return in_array($ay, $cfg['locked_years'], true);
}

function ay_can_edit(?string $ay = null): bool
{
    if (function_exists('auth_is_owner_super') && auth_is_owner_super()) {
        return !ay_is_locked($ay);
    }

    return !ay_is_locked($ay);
}

function ay_role_default_year(?string $role): string
{
    $role = strtolower((string) $role);
    $cfg = ay_admin_config();
    $mode = (string) ($cfg['role_defaults'][$role] ?? 'current');
    if ($mode === 'current' || $mode === '') {
        return ay_current();
    }
    if (ay_is_valid($mode)) {
        return $mode;
    }

    return ay_current();
}

/**
 * Build default promotion map from class order (by id ASC).
 *
 * @return array<string, int|null>
 */
function ay_default_promotion_map(): array
{
    if (!function_exists('table_exists') || !table_exists('classes')) {
        return [];
    }
    $classes = safe_db_get_all('SELECT id, name FROM classes ORDER BY id ASC') ?: [];
    $map = [];
    $count = count($classes);
    for ($i = 0; $i < $count; $i++) {
        $id = (int) ($classes[$i]['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $next = ($i + 1 < $count) ? (int) ($classes[$i + 1]['id'] ?? 0) : null;
        $map[(string) $id] = $next > 0 ? $next : null;
    }

    return $map;
}

function ay_promotion_map(): array
{
    $cfg = ay_admin_config();
    $map = $cfg['promotion_map'];
    if (empty($map)) {
        $map = ay_default_promotion_map();
    }
    $out = [];
    foreach ($map as $from => $to) {
        $out[(string) $from] = ($to === null || $to === '' || $to === 'graduate') ? null : (int) $to;
    }

    return $out;
}

function ay_graduate_class_ids(): array
{
    $cfg = ay_admin_config();
    $ids = array_map('intval', $cfg['graduate_class_ids']);
    if (!empty($ids)) {
        return array_values(array_filter($ids, static fn(int $v): bool => $v > 0));
    }
    $map = ay_promotion_map();
    $last = [];
    foreach ($map as $from => $to) {
        if ($to === null) {
            $last[] = (int) $from;
        }
    }

    return $last;
}

/**
 * Year statistics for reports and compare.
 *
 * @return array<string, mixed>
 */
function ay_compute_stats(string $ay): array
{
    $stats = [
        'academic_year' => $ay,
        'active_students' => 0,
        'admissions' => 0,
        'alumni' => 0,
        'pending' => 0.0,
        'collected' => 0.0,
        'expenses' => 0.0,
        'enquiries' => 0,
        'by_class' => [],
    ];
    if (!ay_students_have_column()) {
        return $stats;
    }

    $params = [':ay' => $ay];
    $r = safe_db_get_one("SELECT COUNT(*) AS c FROM students WHERE academic_year = :ay AND status = 'active'", $params);
    $stats['active_students'] = (int) ($r['c'] ?? 0);
    $r = safe_db_get_one("SELECT COUNT(*) AS c FROM students WHERE academic_year = :ay", $params);
    $stats['admissions'] = (int) ($r['c'] ?? 0);
    $r = safe_db_get_one("SELECT COUNT(*) AS c FROM students WHERE academic_year = :ay AND status = 'alumni'", $params);
    $stats['alumni'] = (int) ($r['c'] ?? 0);

    $r = safe_db_get_one(
        "SELECT COALESCE(SUM(GREATEST(COALESCE(s.total_fees,0) - COALESCE(fr_sum.paid_sum,0),0)),0) AS p
         FROM students s
         LEFT JOIN (SELECT student_id, SUM(paid_amount) AS paid_sum FROM fees_records GROUP BY student_id) fr_sum ON fr_sum.student_id = s.id
         WHERE s.academic_year = :ay",
        $params
    );
    $stats['pending'] = (float) ($r['p'] ?? 0);

    if (table_exists('fees_records')) {
        $r = safe_db_get_one(
            "SELECT COALESCE(SUM(fr.paid_amount),0) AS c FROM fees_records fr
             INNER JOIN students s ON s.id = fr.student_id AND s.academic_year = :ay
             WHERE fr.paid_amount > 0",
            $params
        );
        $stats['collected'] = (float) ($r['c'] ?? 0);
    }

    $range = ay_to_range($ay);
    if ($range && table_exists('expenses')) {
        $r = safe_db_get_one(
            'SELECT COALESCE(SUM(amount),0) AS s FROM expenses WHERE expense_date BETWEEN :s AND :e',
            [':s' => $range['start'], ':e' => $range['end']]
        );
        $stats['expenses'] = (float) ($r['s'] ?? 0);
    }

    if ($range && table_exists('enquiries')) {
        $r = safe_db_get_one(
            'SELECT COUNT(*) AS c FROM enquiries WHERE created_at BETWEEN :s AND :e',
            [':s' => $range['start'], ':e' => $range['end'] . ' 23:59:59']
        );
        $stats['enquiries'] = (int) ($r['c'] ?? 0);
    }

    if (table_exists('classes')) {
        $rows = safe_db_get_all(
            "SELECT c.id, c.name, COUNT(s.id) AS cnt
             FROM classes c
             LEFT JOIN students s ON s.class_id = c.id AND s.academic_year = :ay AND s.status = 'active'
             GROUP BY c.id, c.name ORDER BY c.id ASC",
            $params
        ) ?: [];
        foreach ($rows as $row) {
            $stats['by_class'][] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'count' => (int) ($row['cnt'] ?? 0),
            ];
        }
    }

    return $stats;
}

/**
 * Active students eligible for rollover preview.
 *
 * @return list<array<string,mixed>>
 */
function ay_rollover_candidates(string $fromAy): array
{
    if (!ay_students_have_column()) {
        return [];
    }

    return safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.class_id, s.status, s.academic_year,
                COALESCE(c.name, '') AS class_name, COALESCE(s.total_fees, 0) AS total_fees
         FROM students s
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE s.academic_year = :ay AND s.status = 'active'
         ORDER BY c.name ASC, s.first_name ASC",
        [':ay' => $fromAy]
    ) ?: [];
}

/**
 * Execute rollover for selected continuing students.
 *
 * @param list<int> $continuingIds
 * @return array{promoted: int, graduated: int, alumni: int, errors: list<string>}
 */
function ay_execute_rollover(string $fromAy, string $toAy, array $continuingIds, bool $lockFromYear, int $userId): array
{
    $result = ['promoted' => 0, 'graduated' => 0, 'alumni' => 0, 'errors' => []];
    $continuingIds = array_values(array_unique(array_filter(array_map('intval', $continuingIds), static fn(int $v): bool => $v > 0)));

    if ($fromAy === '' || $toAy === '' || $fromAy === $toAy) {
        $result['errors'][] = 'Invalid academic years.';
        return $result;
    }
    if (ay_is_locked($fromAy)) {
        $result['errors'][] = 'Source year is locked.';
        return $result;
    }

    $candidates = ay_rollover_candidates($fromAy);
    $candidateIds = array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $candidates);
    $map = ay_promotion_map();
    $graduateIds = ay_graduate_class_ids();
    $classFees = [];
    if (table_exists('classes')) {
        foreach (safe_db_get_all('SELECT id, fees FROM classes') ?: [] as $c) {
            $classFees[(int) $c['id']] = (float) ($c['fees'] ?? 0);
        }
    }

    $pdo = pdo_connect();
    if (!($pdo instanceof PDO)) {
        $result['errors'][] = 'Database connection failed.';
        return $result;
    }

    try {
        $pdo->beginTransaction();

        foreach ($candidates as $row) {
            $sid = (int) ($row['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $classId = (int) ($row['class_id'] ?? 0);
            $continuing = in_array($sid, $continuingIds, true);

            if ($continuing) {
                $nextClass = $map[(string) $classId] ?? null;
                $isGraduateClass = in_array($classId, $graduateIds, true) || $nextClass === null;

                if ($isGraduateClass) {
                    safe_db_run(
                        "UPDATE students SET status = 'alumni', updated_at = NOW() WHERE id = :id",
                        [':id' => $sid]
                    );
                    $result['graduated']++;
                } else {
                    $newFees = $classFees[$nextClass] ?? (float) ($row['total_fees'] ?? 0);
                    safe_db_run(
                        "UPDATE students SET academic_year = :ay, class_id = :cid, total_fees = :fees, status = 'active', updated_at = NOW() WHERE id = :id",
                        [':ay' => $toAy, ':cid' => $nextClass, ':fees' => $newFees, ':id' => $sid]
                    );
                    $result['promoted']++;
                }
            } else {
                safe_db_run(
                    "UPDATE students SET status = 'alumni', updated_at = NOW() WHERE id = :id",
                    [':id' => $sid]
                );
                $result['alumni']++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['errors'][] = 'Rollover failed: ' . $e->getMessage();
        return $result;
    }

    $cfg = ay_admin_config();
    $log = $cfg['rollover_log'];
    array_unshift($log, [
        'at' => date('Y-m-d H:i:s'),
        'by' => $userId,
        'from' => $fromAy,
        'to' => $toAy,
        'continuing' => $continuingIds,
        'promoted' => $result['promoted'],
        'graduated' => $result['graduated'],
        'alumni' => $result['alumni'],
    ]);
    $log = array_slice($log, 0, 50);

    $save = ['rollover_log' => $log];
    if ($lockFromYear) {
        $locked = $cfg['locked_years'];
        if (!in_array($fromAy, $locked, true)) {
            $locked[] = $fromAy;
        }
        $save['locked_years'] = array_values(array_unique($locked));
    }
    ay_admin_save($save);

    if (function_exists('parent_sync_logins_for_year')) {
        parent_sync_logins_for_year($toAy);
    }

    return $result;
}

function render_ay_lock_banner(): void
{
    if (!ay_is_locked()) {
        return;
    }
    ?>
    <div class="alert alert-secondary border d-flex align-items-center gap-2 py-2 mb-3">
      <i class="bi bi-lock-fill text-danger"></i>
      <span class="small"><strong><?php echo htmlspecialchars(ay_display_short(), ENT_QUOTES, 'UTF-8'); ?> is locked.</strong> Editing admissions, students and fees is disabled (audit mode).</span>
    </div>
    <?php
}
