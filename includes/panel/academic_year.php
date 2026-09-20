<?php
/**
 * Academic Year — global panel filter.
 * Start month is set by the owner (default June). Selected year is stored in session.
 */
declare(strict_types=1);

const AY_SESSION_KEY = 'panel_academic_year';

/**
 * 1 = January … 12 = December. Default June.
 */
function ay_start_month(): int
{
    if (isset($GLOBALS['_ay_start_month']) && is_int($GLOBALS['_ay_start_month'])) {
        return $GLOBALS['_ay_start_month'];
    }
    $m = 6;
    if (function_exists('ay_admin_config')) {
        $m = (int) (ay_admin_config()['start_month'] ?? 6);
    }
    if ($m < 1 || $m > 12) {
        $m = 6;
    }
    $GLOBALS['_ay_start_month'] = $m;

    return $m;
}

function ay_month_name(int $month): string
{
    $names = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];

    return $names[$month] ?? 'June';
}

function ay_month_short(int $month): string
{
    return substr(ay_month_name($month), 0, 3);
}

/** Last month of the school year (the month before start). */
function ay_end_month(): int
{
    $sm = ay_start_month();

    return $sm === 1 ? 12 : $sm - 1;
}

/** Jun, Jul, … in school-year order. */
function ay_month_short_labels(): array
{
    $sm = ay_start_month();
    $out = [];
    for ($i = 0; $i < 12; $i++) {
        $out[] = ay_month_short((($sm - 1 + $i) % 12) + 1);
    }

    return $out;
}

function ay_calendar_month_index(int $month): int
{
    if ($month < 1 || $month > 12) {
        $month = 1;
    }

    return ($month - ay_start_month() + 12) % 12;
}

function ay_period_phrase(): string
{
    return ay_month_name(ay_start_month()) . ' to ' . ay_month_name(ay_end_month());
}

/**
 * Current academic year label. e.g. 2025-26
 */
function ay_current(): string
{
    $y = (int) date('Y');
    $m = (int) date('n');
    $start = $m >= ay_start_month() ? $y : $y - 1;

    return sprintf('%04d-%02d', $start, ($start + 1) % 100);
}

/**
 * Parse label to date range using the school's start month.
 *
 * @return array{start: string, end: string, label: string}|null
 */
function ay_to_range(?string $ay): ?array
{
    if ($ay === null || trim($ay) === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})\s*-\s*(\d{2}|\d{4})$/', trim($ay), $m)) {
        return null;
    }
    $startYear = (int) $m[1];
    $sm = ay_start_month();
    $em = ay_end_month();
    $endYear = $sm === 1 ? $startYear : $startYear + 1;
    $endDay = (int) date('t', strtotime(sprintf('%04d-%02d-01', $endYear, $em)));

    return [
        'start' => sprintf('%04d-%02d-01', $startYear, $sm),
        'end' => sprintf('%04d-%02d-%02d', $endYear, $em, $endDay),
        'label' => sprintf('%04d-%02d', $startYear, ($startYear + 1) % 100),
    ];
}

function ay_is_valid(string $ay): bool
{
    return in_array($ay, ay_list(), true);
}

/**
 * Available academic years (newest first).
 *
 * @return list<string>
 */
function ay_list(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $years = [];
    $base = (int) date('Y');
    for ($offset = -3; $offset <= 4; $offset++) {
        $start = $base + $offset;
        $years[] = sprintf('%04d-%02d', $start, ($start + 1) % 100);
    }

    if (function_exists('table_exists') && function_exists('column_exists')
        && table_exists('students') && column_exists('students', 'academic_year')) {
        try {
            $rows = safe_db_get_all(
                "SELECT DISTINCT academic_year FROM students
                 WHERE academic_year IS NOT NULL AND TRIM(academic_year) <> ''
                 ORDER BY academic_year DESC"
            ) ?: [];
            foreach ($rows as $r) {
                $v = trim((string) ($r['academic_year'] ?? ''));
                if ($v !== '') {
                    $years[] = $v;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $years[] = ay_current();
    $years = array_values(array_unique($years));
    usort($years, static function (string $a, string $b): int {
        return strcmp($b, $a);
    });

    $cache = $years;
    return $cache;
}

function ay_selected(): string
{
    $stored = $_SESSION[AY_SESSION_KEY] ?? '';
    if (is_string($stored) && $stored !== '' && ay_is_valid($stored)) {
        return $stored;
    }

    if (function_exists('ay_role_default_year') && function_exists('auth_role')) {
        $role = auth_role();
        if ($role !== null) {
            $roleDefault = ay_role_default_year($role);
            if (ay_is_valid($roleDefault)) {
                return $roleDefault;
            }
        }
    }

    $current = ay_current();
    $list = ay_list();
    if (in_array($current, $list, true)) {
        return $current;
    }

    return $list[0] ?? $current;
}

function ay_range(): array
{
    $range = ay_to_range(ay_selected());
    if ($range !== null) {
        return $range;
    }

    $fallback = ay_to_range(ay_current());
    if ($fallback !== null) {
        return $fallback;
    }

    return [
        'start' => date('Y') . '-01-01',
        'end' => date('Y') . '-12-31',
        'label' => ay_current(),
    ];
}

function ay_students_have_column(): bool
{
    return function_exists('table_exists') && function_exists('column_exists')
        && table_exists('students') && column_exists('students', 'academic_year');
}

/**
 * Handle POST/GET year switch (call once from panel_bootstrap).
 */
function ay_init(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['panel_academic_year'])) {
        $ay = trim((string) $_POST['panel_academic_year']);
        if (ay_is_valid($ay)) {
            $_SESSION[AY_SESSION_KEY] = $ay;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if (!headers_sent()) {
            header('Location: ' . $uri);
            exit;
        }
    }

    if (!empty($_GET['set_ay'])) {
        $ay = trim((string) $_GET['set_ay']);
        if (ay_is_valid($ay)) {
            $_SESSION[AY_SESSION_KEY] = $ay;
        }
    }
}

/**
 * Append student academic_year filter.
 */
function ay_apply_student_filter(array &$where, array &$params, string $alias = 's'): void
{
    if (!ay_students_have_column()) {
        return;
    }
    $where[] = $alias . '.academic_year = :panel_ay';
    $params[':panel_ay'] = ay_selected();
}

/** SQL snippet: this academic year's students only. */
function ay_sql_student(string $alias = 's'): string
{
    if (!ay_students_have_column()) {
        return '1=1';
    }

    return $alias . '.academic_year = :panel_ay';
}

function ay_params_student(array $params = []): array
{
    if (ay_students_have_column()) {
        $params[':panel_ay'] = ay_selected();
    }

    return $params;
}

/**
 * Clip a date range to the selected academic year (June–May).
 *
 * @return array{0: string, 1: string}
 */
function ay_limit_dates(string $from, string $to): array
{
    $range = ay_range();
    if ($from < $range['start']) {
        $from = $range['start'];
    }
    if ($to > $range['end']) {
        $to = $range['end'];
    }
    if ($to < $from) {
        $to = $from;
    }

    return [$from, $to];
}

function ay_contains_date(string $date): bool
{
    $range = ay_range();

    return $date >= $range['start'] && $date <= $range['end'];
}

/**
 * Append date-column filter for selected academic year (June–June).
 */
function ay_apply_date_filter(array &$where, array &$params, string $column): void
{
    $range = ay_range();
    $where[] = $column . ' BETWEEN :panel_ay_start AND :panel_ay_end';
    $params[':panel_ay_start'] = $range['start'];
    $params[':panel_ay_end'] = $range['end'] . ' 23:59:59';
}

/**
 * SQL fragment for fees joined to students (selected year).
 */
function ay_fees_student_join(): string
{
    return ' INNER JOIN students ay_stu ON ay_stu.id = fr.student_id ';
}

function ay_fees_student_where(): array
{
    if (!ay_students_have_column()) {
        return ['sql' => '', 'params' => []];
    }

    return [
        'sql' => 'ay_stu.academic_year = :panel_ay',
        'params' => [':panel_ay' => ay_selected()],
    ];
}

function ay_display_short(?string $ay = null): string
{
    $ay = $ay ?? ay_selected();

    return 'A.Y. ' . $ay;
}

function ay_display_long(?string $ay = null): string
{
    $ay = $ay ?? ay_selected();
    $range = ay_to_range($ay);

    if ($range === null) {
        return 'Academic Year ' . $ay;
    }

    $startLabel = date('M Y', strtotime($range['start']));
    $endLabel = date('M Y', strtotime($range['end']));

    return 'Academic Year ' . $ay . ' (' . $startLabel . ' – ' . $endLabel . ')';
}

/**
 * Effective end date for dashboard display (today if current A.Y., else full year end).
 */
function ay_effective_end(?string $ay = null): string
{
    $selected = $ay ?? ay_selected();
    $range = ay_to_range($selected);
    if ($range === null) {
        return date('Y-m-d');
    }
    if ($selected === ay_current()) {
        return min(date('Y-m-d'), $range['end']);
    }

    return $range['end'];
}

/**
 * dd-mm-yyyy To dd-mm-yyyy for selected academic year (reference dashboard style).
 */
function ay_display_date_range(?string $ay = null): string
{
    $range = ay_to_range($ay ?? ay_selected());
    if ($range === null) {
        return '';
    }
    $end = ay_effective_end($ay);

    return date('d-m-Y', strtotime($range['start'])) . ' To ' . date('d-m-Y', strtotime($end));
}

/** Previous academic year label (newer-first list), or null. */
function ay_previous(?string $ay = null): ?string
{
    $ay = $ay ?? ay_selected();
    $list = ay_list();
    $idx = array_search($ay, $list, true);
    if ($idx === false || $idx >= count($list) - 1) {
        return null;
    }

    return $list[$idx + 1];
}

/**
 * Show global year selector on staff panels (not parent).
 */
function ay_panel_enabled(?string $panelRole): bool
{
    return in_array($panelRole, ['owner', 'reception', 'accounts', 'teacher'], true);
}

/** Full-width A.Y. dropdown for dashboard on mobile. */
function render_dashboard_academic_year_dropdown(): void
{
    if (!ay_panel_enabled($GLOBALS['panelRole'] ?? null)) {
        return;
    }

    $selected = ay_selected();
    $years = ay_list();
    $current = ay_current();
    ?>
    <form method="post" class="dc-ay-form" action="">
      <label class="dc-ay-form-label" for="panelAcademicYearMobile">
        <i class="bi bi-calendar2-range"></i> Select Academic Year
      </label>
      <select name="panel_academic_year" id="panelAcademicYearMobile" class="form-select dc-ay-select" onchange="this.form.submit()" title="<?php echo htmlspecialchars(ay_display_long($selected), ENT_QUOTES, 'UTF-8'); ?>">
        <?php foreach ($years as $y): ?>
          <option value="<?php echo htmlspecialchars($y, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $y === $selected ? ' selected' : ''; ?>>
            <?php echo htmlspecialchars(ay_display_long($y), ENT_QUOTES, 'UTF-8'); ?><?php echo $y === $current ? ' ★' : ''; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php
}

function render_panel_academic_year_selector(): void
{
    if (!ay_panel_enabled($GLOBALS['panelRole'] ?? null)) {
        return;
    }

    $selected = ay_selected();
    $years = ay_list();
    $current = ay_current();
    $isCurrent = $selected === $current;
    ?>
    <form method="post" class="panel-ay-form d-none d-md-flex align-items-center" action="">
      <label class="panel-ay-label mb-0 me-1" for="panelAcademicYear">
        <i class="bi bi-calendar3 me-1"></i>
      </label>
      <select name="panel_academic_year" id="panelAcademicYear" class="form-select form-select-sm panel-ay-select" onchange="this.form.submit()" title="<?php echo htmlspecialchars(ay_display_long($selected), ENT_QUOTES, 'UTF-8'); ?>">
        <?php foreach ($years as $y): ?>
          <option value="<?php echo htmlspecialchars($y, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $y === $selected ? ' selected' : ''; ?>>
            <?php echo htmlspecialchars(ay_display_short($y), ENT_QUOTES, 'UTF-8'); ?><?php echo $y === $current ? ' ★' : ''; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if (!$isCurrent): ?>
      <span class="badge bg-warning text-dark panel-ay-badge d-none d-lg-inline"><?php echo htmlspecialchars(ay_display_short($selected), ENT_QUOTES, 'UTF-8'); ?></span>
    <?php endif;
}

function render_panel_academic_year_banner(): void
{
    if (!ay_panel_enabled($GLOBALS['panelRole'] ?? null)) {
        return;
    }
    ?>
    <div class="panel-ay-banner alert alert-light border py-2 px-3 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
      <span class="small">
        <i class="bi bi-calendar3 text-success me-1"></i>
        <strong><?php echo htmlspecialchars(ay_display_long(), ENT_QUOTES, 'UTF-8'); ?></strong>
        <span class="text-muted">— only this year's students &amp; records are shown.</span>
      </span>
      <?php if (ay_selected() !== ay_current()): ?>
        <form method="post" class="m-0">
          <input type="hidden" name="panel_academic_year" value="<?php echo htmlspecialchars(ay_current(), ENT_QUOTES, 'UTF-8'); ?>">
          <button type="submit" class="btn btn-sm btn-outline-success">Switch to current (<?php echo htmlspecialchars(ay_display_short(ay_current()), ENT_QUOTES, 'UTF-8'); ?>)</button>
        </form>
      <?php endif; ?>
    </div>
    <?php
}
