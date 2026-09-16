<?php
/**
 * Global panel search — students, parents, fees, enquiries.
 */
declare(strict_types=1);

function panel_search_enabled(?string $panelRole): bool
{
    return in_array($panelRole, ['owner', 'reception', 'accounts', 'teacher'], true);
}

function panel_search_api_url(): string
{
    if (function_exists('site_url')) {
        return site_url('/panel_search.php');
    }
    $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';

    return $base . '/panel_search.php';
}

function panel_search_page_url(): string
{
    if (function_exists('site_url')) {
        return site_url('/search.php');
    }
    $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';

    return $base . '/search.php';
}

/**
 * Role-aware module URLs for search result links.
 *
 * @return array{students: string, parents: string, fees: string, enquiries: string}
 */
function panel_search_urls(): array
{
    $owner = panel_base_url('owner');

    if (function_exists('auth_is_owner_super') && auth_is_owner_super()) {
        return [
            'students' => $owner . '/students_list.php',
            'parents' => $owner . '/students_list.php',
            'fees' => $owner . '/daily_collection.php',
            'enquiries' => $owner . '/enquiry_list.php',
        ];
    }

    $role = function_exists('auth_role') ? auth_role() : 'owner';
    $base = panel_base_url($role ?? 'owner');

    if ($role === 'reception') {
        return [
            'students' => $base . '/students_list.php',
            'parents' => $base . '/students_list.php',
            'fees' => panel_base_url('accounts') . '/fees_collection.php',
            'enquiries' => $base . '/enquiry_list.php',
        ];
    }
    if ($role === 'accounts') {
        return [
            'students' => $owner . '/students_list.php',
            'parents' => $owner . '/students_list.php',
            'fees' => $base . '/daily_collection.php',
            'enquiries' => $owner . '/enquiry_list.php',
        ];
    }

    return [
        'students' => $owner . '/students_list.php',
        'parents' => $owner . '/students_list.php',
        'fees' => $owner . '/pending_fees.php',
        'enquiries' => $owner . '/enquiry_list.php',
    ];
}

/**
 * @return array<int, array{type: string, title: string, subtitle: string, url: string, icon: string}>
 */
function panel_global_search(string $query, int $limitPerType = 6): array
{
    $q = trim($query);
    if (strlen($q) < 2) {
        return [];
    }

    $like = '%' . $q . '%';
    $results = [];
    $urls = panel_search_urls();
    $ay = function_exists('ay_selected') ? ay_selected() : '';

    if (function_exists('table_exists') && table_exists('students')) {
        $params = [
            ':q_name' => $like,
            ':q_father' => $like,
            ':q_mother' => $like,
        ];
        $ayOrder = '';
        if (function_exists('ay_students_have_column') && ay_students_have_column() && $ay !== '') {
            $params[':ay'] = $ay;
            $ayOrder = "CASE WHEN s.academic_year = :ay THEN 0 ELSE 1 END ASC,";
        }
        $digits = preg_replace('/\D+/', '', $q);
        $idClause = '';
        if ($digits !== '' && ctype_digit($digits)) {
            $idClause = ' OR s.id = :sid';
            $params[':sid'] = (int) $digits;
        }
        $guardianClause = '';
        if (function_exists('column_exists') && column_exists('students', 'guardian_phone')) {
            $guardianClause = " OR COALESCE(s.guardian_phone, '') LIKE :q_guardian";
            $params[':q_guardian'] = $like;
        }
        $formClause = '';
        if (function_exists('column_exists') && column_exists('students', 'form_no')) {
            $formClause = ' OR s.form_no LIKE :q_form';
            $params[':q_form'] = $like;
        }

        $rows = safe_db_get_all(
            "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.form_no,
                    s.father_phone, s.mother_phone, s.academic_year, s.status,
                    COALESCE(c.name, '') AS class_name
             FROM students s
             LEFT JOIN classes c ON c.id = s.class_id
             WHERE (
                CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name) LIKE :q_name
                {$formClause}
                OR COALESCE(s.father_phone, '') LIKE :q_father
                OR COALESCE(s.mother_phone, '') LIKE :q_mother
                {$guardianClause}
                {$idClause}
             )
             ORDER BY {$ayOrder} s.first_name ASC
             LIMIT " . (int) $limitPerType,
            $params
        ) ?: [];

        foreach ($rows as $r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $sub = array_filter([
                !empty($r['class_name']) ? (string) $r['class_name'] : null,
                !empty($r['form_no']) ? 'Form: ' . $r['form_no'] : null,
                !empty($r['academic_year']) ? 'AY ' . $r['academic_year'] : null,
                !empty($r['father_phone']) ? (string) $r['father_phone'] : null,
            ]);
            $results[] = [
                'type' => 'student',
                'title' => $name !== '' ? $name : 'Student #' . ($r['id'] ?? ''),
                'subtitle' => implode(' · ', $sub),
                'url' => $urls['students'] . '?q=' . rawurlencode($name !== '' ? $name : $q),
                'icon' => 'bi-person-badge',
            ];
        }
    }

    if (function_exists('table_exists') && table_exists('users')) {
        $activeClause = (function_exists('column_exists') && column_exists('users', 'is_active'))
            ? ' AND is_active = 1'
            : '';
        $rows = safe_db_get_all(
            "SELECT id, name, phone, role FROM users
             WHERE role = 'parent'{$activeClause} AND (name LIKE :q_name OR phone LIKE :q_phone)
             ORDER BY name ASC LIMIT " . (int) $limitPerType,
            [':q_name' => $like, ':q_phone' => $like]
        ) ?: [];
        foreach ($rows as $r) {
            $results[] = [
                'type' => 'parent',
                'title' => (string) ($r['name'] ?? 'Parent'),
                'subtitle' => 'Parent · ' . (string) ($r['phone'] ?? ''),
                'url' => $urls['students'] . '?q=' . rawurlencode((string) ($r['phone'] ?? $r['name'] ?? $q)),
                'icon' => 'bi-people',
            ];
        }
    }

    if (function_exists('table_exists') && table_exists('fees_records')) {
        $receiptClause = (function_exists('column_exists') && column_exists('fees_records', 'receipt_no'))
            ? 'fr.receipt_no LIKE :q'
            : 'CAST(fr.id AS CHAR) LIKE :q';
        $rows = safe_db_get_all(
            "SELECT fr.id, fr.receipt_no, fr.paid_amount, fr.student_id,
                    COALESCE(s.first_name, '') AS fn, COALESCE(s.last_name, '') AS ln
             FROM fees_records fr
             LEFT JOIN students s ON s.id = fr.student_id
             WHERE {$receiptClause}
             ORDER BY fr.id DESC LIMIT " . (int) min(4, $limitPerType),
            [':q' => $like]
        ) ?: [];
        foreach ($rows as $r) {
            $student = trim(($r['fn'] ?? '') . ' ' . ($r['ln'] ?? ''));
            $feeUrl = $urls['fees'] . '?q=' . rawurlencode((string) ($r['receipt_no'] ?? $q));
            if ($student !== '') {
                $feeUrl = $urls['students'] . '?q=' . rawurlencode($student);
            }
            $results[] = [
                'type' => 'fee',
                'title' => 'Receipt ' . (string) ($r['receipt_no'] ?? ('#' . ($r['id'] ?? ''))),
                'subtitle' => ($student !== '' ? $student . ' · ' : '') . format_money((float) ($r['paid_amount'] ?? 0)),
                'url' => $feeUrl,
                'icon' => 'bi-receipt',
            ];
        }
    }

    if (function_exists('table_exists') && table_exists('enquiries')) {
        $rows = safe_db_get_all(
            "SELECT id, name, phone, status FROM enquiries
             WHERE name LIKE :q_name OR phone LIKE :q_phone OR message LIKE :q_message
             ORDER BY created_at DESC LIMIT " . (int) min(4, $limitPerType),
            [':q_name' => $like, ':q_phone' => $like, ':q_message' => $like]
        ) ?: [];
        foreach ($rows as $r) {
            $results[] = [
                'type' => 'enquiry',
                'title' => (string) ($r['name'] ?? 'Enquiry'),
                'subtitle' => (string) ($r['phone'] ?? '') . ' · ' . (string) ($r['status'] ?? 'new'),
                'url' => $urls['enquiries'] . '?q=' . rawurlencode((string) ($r['name'] ?? $q)),
                'icon' => 'bi-chat-left-text',
            ];
        }
    }

    return $results;
}

function render_panel_global_search(): void
{
    if (!panel_search_enabled($GLOBALS['panelRole'] ?? null)) {
        return;
    }
    $api = panel_search_api_url();
    $page = panel_search_page_url();
    ?>
    <div class="panel-global-search d-none d-md-block" id="panelGlobalSearchWrap">
      <div class="panel-global-search-inner">
        <i class="bi bi-search panel-global-search-icon"></i>
        <input type="search" id="panelGlobalSearchInput" class="panel-global-search-input"
               placeholder="Search student, phone, form no…" autocomplete="off"
               aria-label="Global search" aria-expanded="false" aria-controls="panelGlobalSearchDropdown">
        <kbd class="panel-global-search-kbd d-none d-lg-inline">Ctrl+K</kbd>
      </div>
      <div id="panelGlobalSearchDropdown" class="panel-search-dropdown" hidden></div>
    </div>
    <script>
    window.PANEL_SEARCH = { api: <?php echo json_encode($api); ?>, page: <?php echo json_encode($page); ?> };
    </script>
    <?php
}
