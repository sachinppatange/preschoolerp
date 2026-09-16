<?php
/**
 * Dashboard widget data helpers (owner panel).
 */
declare(strict_types=1);

/** @return array{students: list<array<string,mixed>>, count: int} */
function dash_birthdays_today(?string $ay = null): array
{
    $empty = ['students' => [], 'count' => 0];
    if (!function_exists('table_exists') || !table_exists('students') || !column_exists('students', 'dob')) {
        return $empty;
    }
    $ay = $ay ?? (function_exists('ay_selected') ? ay_selected() : '');
    $params = [];
    $ayClause = '';
    if (function_exists('ay_students_have_column') && ay_students_have_column() && $ay !== '') {
        $ayClause = ' AND s.academic_year = :panel_ay';
        $params[':panel_ay'] = $ay;
    }
    $rows = safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.dob,
                COALESCE(c.name, '') AS class_name,
                TIMESTAMPDIFF(YEAR, s.dob, CURDATE()) AS age_years
         FROM students s
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE s.status = 'active' AND s.dob IS NOT NULL
           AND MONTH(s.dob) = MONTH(CURDATE()) AND DAY(s.dob) = DAY(CURDATE())
           {$ayClause}
         ORDER BY s.first_name ASC LIMIT 12",
        $params
    ) ?: [];

    return ['students' => $rows, 'count' => count($rows)];
}

/** @return array{present: int, absent: int, late: int, other: int, total_active: int, marked: int, pct: float} */
function dash_attendance_today(?string $ay = null): array
{
    $out = [
        'present' => 0, 'absent' => 0, 'late' => 0, 'other' => 0,
        'total_active' => 0, 'marked' => 0, 'pct' => 0.0,
    ];
    if (!function_exists('table_exists') || !table_exists('attendance') || !table_exists('students')) {
        return $out;
    }
    $ay = $ay ?? (function_exists('ay_selected') ? ay_selected() : '');
    $params = [];
    $ayClause = '';
    if (function_exists('ay_students_have_column') && ay_students_have_column() && $ay !== '') {
        $ayClause = ' AND s.academic_year = :panel_ay';
        $params[':panel_ay'] = $ay;
    }
    $r = safe_db_get_one(
        "SELECT COUNT(*) AS c FROM students s WHERE s.status = 'active'{$ayClause}",
        $params
    );
    $out['total_active'] = (int) ($r['c'] ?? 0);

    $arows = safe_db_get_all(
        "SELECT a.status, COUNT(*) AS c
         FROM attendance a
         INNER JOIN students s ON s.id = a.student_id AND s.status = 'active'{$ayClause}
         WHERE a.`date` = CURDATE()
         GROUP BY a.status",
        $params
    ) ?: [];
    foreach ($arows as $row) {
        $st = strtolower((string) ($row['status'] ?? ''));
        $cnt = (int) ($row['c'] ?? 0);
        $out['marked'] += $cnt;
        if ($st === 'present') {
            $out['present'] += $cnt;
        } elseif ($st === 'absent') {
            $out['absent'] += $cnt;
        } elseif ($st === 'late') {
            $out['late'] += $cnt;
        } else {
            $out['other'] += $cnt;
        }
    }
    if ($out['total_active'] > 0) {
        $out['pct'] = round(($out['present'] / $out['total_active']) * 100, 1);
    }

    return $out;
}

/** @return array{enquiries: int, new_enquiries: int, admissions: int, conversion: float} */
function dash_admission_funnel(int $enquiriesTotal, int $enquiriesNew, int $admissionsYear): array
{
    $conversion = $enquiriesTotal > 0
        ? round(($admissionsYear / $enquiriesTotal) * 100, 1)
        : 0.0;

    return [
        'enquiries' => $enquiriesTotal,
        'new_enquiries' => $enquiriesNew,
        'admissions' => $admissionsYear,
        'conversion' => $conversion,
    ];
}

/**
 * Students with pending fees overdue 60+ days (2+ months).
 *
 * @return list<array<string,mixed>>
 */
function dash_fee_reminder_students(?string $ay = null, int $minDays = 60, int $limit = 10): array
{
    if (!function_exists('table_exists') || !table_exists('students') || !table_exists('fees_records')) {
        return [];
    }
    if (!function_exists('ay_students_have_column') || !ay_students_have_column()) {
        return [];
    }
    $ay = $ay ?? ay_selected();

    $guardianCol = (function_exists('column_exists') && column_exists('students', 'guardian_phone'))
        ? 's.guardian_phone' : "'' AS guardian_phone";

    return safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name,
                s.father_phone, s.mother_phone, {$guardianCol},
                COALESCE(c.name,'') AS class_name,
                GREATEST(COALESCE(s.total_fees,0) - COALESCE(fr_sum.paid_sum,0),0) AS pending,
                DATEDIFF(CURDATE(), COALESCE(s.admission_date, DATE(s.created_at))) AS age_days
         FROM students s
         LEFT JOIN (SELECT student_id, SUM(paid_amount) AS paid_sum FROM fees_records GROUP BY student_id) fr_sum ON fr_sum.student_id = s.id
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE s.academic_year = :panel_ay AND s.status = 'active'
         HAVING pending > 0 AND age_days >= :min_days
         ORDER BY pending DESC LIMIT " . (int) $limit,
        [':panel_ay' => $ay, ':min_days' => $minDays]
    ) ?: [];
}

function dash_fee_reminder_phone(array $student): string
{
    foreach (['father_phone', 'mother_phone', 'guardian_phone'] as $col) {
        $ph = preg_replace('/\D+/', '', (string) ($student[$col] ?? ''));
        if (strlen($ph) >= 10) {
            if (strlen($ph) === 10) {
                return '91' . $ph;
            }

            return $ph;
        }
    }

    return '';
}

function dash_whatsapp_reminder_url(array $student, float $pending): string
{
    $phone = dash_fee_reminder_phone($student);
    if ($phone === '') {
        return '';
    }
    $name = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    $amt = function_exists('format_money') ? format_money($pending) : (string) $pending;
    $school = defined('APP_NAME') ? APP_NAME : 'Preschool';
    $msg = "Dear Parent,\n\nThis is a fee reminder from {$school}.\nStudent: {$name}\nPending amount: {$amt}\n\nPlease clear the pending fees at your earliest convenience.\nThank you.";
    $encoded = rawurlencode($msg);

    return 'https://wa.me/' . $phone . '?text=' . $encoded;
}

/** Chart / UI brand colors from design tokens. */
function dash_chart_theme(): array
{
    return [
        'primary' => '#ff6b8a',
        'primaryRgb' => '255,107,138',
        'accent' => '#6bcbff',
        'accentRgb' => '107,203,255',
        'mint' => '#5eead4',
        'sun' => '#ffe066',
        'lavender' => '#b8a9ff',
        'muted' => '#94a3b8',
        'danger' => '#e84d6f',
    ];
}
