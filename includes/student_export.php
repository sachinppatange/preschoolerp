<?php
/**
 * Student list filters + CSV / Excel / PDF export (respects Academic Year + page filters).
 */
declare(strict_types=1);

/**
 * @return array{where: list<string>, params: array<string,mixed>, q: string, class_id: ?int, status: string, gender: string}
 */
function student_list_filters(): array
{
    $where = [];
    $params = [];

    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = "(s.first_name LIKE :q OR s.middle_name LIKE :q OR s.last_name LIKE :q
            OR s.form_no LIKE :q OR s.father_phone LIKE :q OR s.mother_phone LIKE :q
            OR s.guardian_phone LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $classId = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : null;
    if ($classId !== null && $classId > 0) {
        $where[] = 's.class_id = :class_id';
        $params[':class_id'] = $classId;
    }

    $status = trim((string) ($_GET['status'] ?? ''));
    if ($status !== '') {
        $where[] = 's.status = :status';
        $params[':status'] = $status;
    }

    $gender = strtolower(trim((string) ($_GET['gender'] ?? '')));
    if (in_array($gender, ['male', 'female'], true) && function_exists('column_exists') && column_exists('students', 'gender')) {
        $where[] = 's.gender = :gender';
        $params[':gender'] = $gender;
    } else {
        $gender = '';
    }

    if (function_exists('ay_apply_student_filter')) {
        ay_apply_student_filter($where, $params, 's');
    }

    return [
        'where' => $where,
        'params' => $params,
        'q' => $q,
        'class_id' => $classId,
        'status' => $status,
        'gender' => $gender,
    ];
}

function student_list_where_sql(array $filters): string
{
    $where = $filters['where'] ?? [];
    return $where ? ('WHERE ' . implode(' AND ', $where)) : '';
}

function student_export_query_string(string $format): string
{
    $qs = $_GET;
    unset($qs['action'], $qs['format'], $qs['page']);
    $qs['action'] = 'export';
    $qs['format'] = $format;
    return http_build_query($qs);
}

/**
 * @return array<int, array<string,mixed>>
 */
function student_export_rows(array $filters): array
{
    $whereSql = student_list_where_sql($filters);
    $paidSub = (function_exists('table_exists') && table_exists('fees_records'))
        ? '(SELECT COALESCE(SUM(fr.paid_amount),0) FROM fees_records fr WHERE fr.student_id = s.id)'
        : '0';

    return safe_db_get_all(
        "SELECT s.id, s.form_no, s.academic_year, s.first_name, s.middle_name, s.last_name, s.gender, s.dob,
                s.admission_date, s.status, s.location, s.father_first, s.father_phone, s.mother_first, s.mother_phone,
                s.address, s.city, s.total_fees, s.class_id,
                COALESCE(c.name,'') AS class_name,
                COALESCE(u.name,'') AS parent_name,
                COALESCE(u.phone,'') AS parent_phone,
                {$paidSub} AS paid_total
         FROM students s
         LEFT JOIN classes c ON c.id = s.class_id
         LEFT JOIN users u ON u.id = s.parent_id
         {$whereSql}
         ORDER BY s.first_name ASC, s.last_name ASC",
        $filters['params']
    ) ?: [];
}

/**
 * @return list<string>
 */
function student_export_headers(): array
{
    return [
        'ID', 'Form No', 'Academic Year', 'First Name', 'Middle Name', 'Last Name', 'Gender', 'DOB',
        'Class', 'Status', 'Admission Date', 'Location', 'Parent Login', 'Parent Phone',
        'Father', 'Father Phone', 'Mother', 'Mother Phone', 'Address', 'City',
        'Total Fees', 'Paid', 'Remaining',
    ];
}

/**
 * @param array<string,mixed> $r
 * @return list<string>
 */
function student_export_row_values(array $r): array
{
    $total = (float) ($r['total_fees'] ?? 0);
    $paid = (float) ($r['paid_total'] ?? 0);
    return [
        (string) ($r['id'] ?? ''),
        (string) ($r['form_no'] ?? ''),
        (string) ($r['academic_year'] ?? ''),
        (string) ($r['first_name'] ?? ''),
        (string) ($r['middle_name'] ?? ''),
        (string) ($r['last_name'] ?? ''),
        (string) ($r['gender'] ?? ''),
        (string) ($r['dob'] ?? ''),
        (string) ($r['class_name'] ?? ''),
        (string) ($r['status'] ?? ''),
        (string) ($r['admission_date'] ?? ''),
        (string) ($r['location'] ?? ''),
        (string) ($r['parent_name'] ?? ''),
        (string) ($r['parent_phone'] ?? ''),
        (string) ($r['father_first'] ?? ''),
        (string) ($r['father_phone'] ?? ''),
        (string) ($r['mother_first'] ?? ''),
        (string) ($r['mother_phone'] ?? ''),
        (string) ($r['address'] ?? ''),
        (string) ($r['city'] ?? ''),
        number_format($total, 2, '.', ''),
        number_format($paid, 2, '.', ''),
        number_format(max(0, $total - $paid), 2, '.', ''),
    ];
}

function student_export_filename(string $ext): string
{
    $ay = function_exists('ay_selected') ? ay_selected() : date('Y');
    return 'students_' . preg_replace('/[^0-9A-Za-z\-]/', '', $ay) . '_' . date('Ymd_His') . '.' . $ext;
}

function student_handle_export(): void
{
    $format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
    if (!in_array($format, ['csv', 'excel', 'xls', 'pdf'], true)) {
        $format = 'csv';
    }
    $filters = student_list_filters();
    $rows = student_export_rows($filters);
    $ayLabel = function_exists('ay_display_long') ? ay_display_long() : '';

    if ($format === 'pdf') {
        student_export_pdf($rows, $ayLabel);
        exit;
    }
    if ($format === 'excel' || $format === 'xls') {
        student_export_excel($rows, $ayLabel);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . student_export_filename('csv'));
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Filter', $ayLabel]);
    fputcsv($out, student_export_headers());
    foreach ($rows as $r) {
        fputcsv($out, student_export_row_values($r));
    }
    fclose($out);
    exit;
}

/**
 * @param array<int, array<string,mixed>> $rows
 */
function student_export_excel(array $rows, string $ayLabel): void
{
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . student_export_filename('xls'));
    echo '<html><head><meta charset="utf-8"></head><body>';
    echo '<h3>' . htmlspecialchars('Students — ' . $ayLabel, ENT_QUOTES, 'UTF-8') . '</h3>';
    echo '<table border="1"><thead><tr>';
    foreach (student_export_headers() as $h) {
        echo '<th>' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach (student_export_row_values($r) as $v) {
            echo '<td>' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
}

/**
 * @param array<int, array<string,mixed>> $rows
 */
function student_export_pdf(array $rows, string $ayLabel): void
{
    $app = defined('APP_NAME') ? APP_NAME : 'Preschool';
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Students — <?php echo htmlspecialchars($ayLabel, ENT_QUOTES, 'UTF-8'); ?></title>
  <style>
    body { font-family: Arial, sans-serif; font-size: 12px; color: #222; margin: 16px; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    .muted { color: #666; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
    th { background: #eef6ff; }
    .toolbar { margin-bottom: 12px; }
    .btn { background: #1e4b8f; color: #fff; border: 0; padding: 8px 12px; border-radius: 6px; cursor: pointer; }
    @media print { .toolbar { display: none !important; } }
  </style>
</head>
<body>
  <div class="toolbar"><button class="btn" type="button" onclick="window.print()">Download PDF / Print</button></div>
  <h1><?php echo htmlspecialchars($app, ENT_QUOTES, 'UTF-8'); ?></h1>
  <div class="muted"><?php echo htmlspecialchars('Students list — ' . $ayLabel . ' · ' . count($rows) . ' students', ENT_QUOTES, 'UTF-8'); ?></div>
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Form</th><th>Name</th><th>Class</th><th>Status</th>
        <th>Parent</th><th>Phone</th><th>Total</th><th>Paid</th><th>Remaining</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $total = (float) ($r['total_fees'] ?? 0);
        $paid = (float) ($r['paid_total'] ?? 0);
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        ?>
      <tr>
        <td><?php echo (int) ($r['id'] ?? 0); ?></td>
        <td><?php echo htmlspecialchars((string) ($r['form_no'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars((string) ($r['class_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars((string) ($r['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars((string) ($r['parent_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars((string) ($r['parent_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo number_format($total, 2); ?></td>
        <td><?php echo number_format($paid, 2); ?></td>
        <td><?php echo number_format(max(0, $total - $paid), 2); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html><?php
}
