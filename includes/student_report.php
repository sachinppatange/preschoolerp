<?php
/**
 * One-place child snapshot for QR visits + monthly WhatsApp/email text.
 */
declare(strict_types=1);

/**
 * @return array{0: string, 1: string, 2: string} start, end, label
 */
function student_report_month_bounds(string $ym): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        $ym = date('Y-m');
    }
    $start = $ym . '-01';
    $end = date('Y-m-t', strtotime($start) ?: time());
    $label = date('F Y', strtotime($start) ?: time());
    return [$start, $end, $label];
}

/**
 * @return array<string, mixed>|null
 */
function student_report_build(int $studentId, string $ym = ''): ?array
{
    if ($studentId <= 0) {
        return null;
    }
    if (!function_exists('student_idcard_load')) {
        require_once __DIR__ . '/student_idcard.php';
    }
    $s = student_idcard_load($studentId);
    if (!$s) {
        return null;
    }
    if ($ym === '') {
        $ym = date('Y-m');
    }
    [$from, $to, $label] = student_report_month_bounds($ym);
    $today = date('Y-m-d');
    $name = function_exists('student_full_name') ? student_full_name($s) : trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
    $em = function_exists('student_idcard_emergency') ? student_idcard_emergency($s) : ['name' => '', 'phone' => ''];
    $fees = function_exists('student_fee_summary') ? student_fee_summary($s) : ['total' => 0.0, 'paid' => 0.0, 'remaining' => 0.0];

    $att = ['present' => 0, 'absent' => 0, 'leave' => 0, 'today' => ''];
    if (table_exists('attendance')) {
        $rows = safe_db_get_all(
            'SELECT `date`, status FROM attendance WHERE student_id = :sid AND `date` BETWEEN :a AND :b',
            [':sid' => $studentId, ':a' => $from, ':b' => $to]
        ) ?: [];
        foreach ($rows as $r) {
            $st = strtolower(trim((string) ($r['status'] ?? '')));
            $d = substr((string) ($r['date'] ?? ''), 0, 10);
            if ($st === 'late') {
                $st = 'present';
            }
            if ($st === 'excused') {
                $st = 'leave';
            }
            if (isset($att[$st])) {
                $att[$st]++;
            }
            if ($d === $today) {
                $att['today'] = $st;
            }
        }
    }

    $paidMonth = 0.0;
    if (table_exists('fees_records')) {
        $row = safe_db_get_one(
            'SELECT COALESCE(SUM(paid_amount),0) AS p FROM fees_records
             WHERE student_id = :sid AND DATE(COALESCE(collected_at, created_at)) BETWEEN :a AND :b',
            [':sid' => $studentId, ':a' => $from, ':b' => $to]
        );
        $paidMonth = (float) ($row['p'] ?? 0);
    }

    $hw = '';
    $classId = (int) ($s['class_id'] ?? 0);
    if ($classId > 0 && table_exists('homeworks')) {
        $row = safe_db_get_one(
            'SELECT title FROM homeworks WHERE class_id = :c AND assigned_date BETWEEN :a AND :b
             ORDER BY assigned_date DESC, id DESC LIMIT 1',
            [':c' => $classId, ':a' => $from, ':b' => $to]
        );
        $hw = trim((string) ($row['title'] ?? ''));
    }

    $remark = '';
    if (table_exists('student_remarks')) {
        $dateCol = function_exists('column_exists') && column_exists('student_remarks', 'date');
        $sql = $dateCol
            ? 'SELECT remark FROM student_remarks WHERE student_id = :sid
               AND COALESCE(`date`, DATE(created_at)) BETWEEN :a AND :b
               ORDER BY COALESCE(`date`, created_at) DESC, id DESC LIMIT 1'
            : 'SELECT remark FROM student_remarks WHERE student_id = :sid
               AND DATE(created_at) BETWEEN :a AND :b
               ORDER BY created_at DESC, id DESC LIMIT 1';
        $row = safe_db_get_one($sql, [':sid' => $studentId, ':a' => $from, ':b' => $to]);
        $remark = trim((string) ($row['remark'] ?? ''));
    }

    $allergy = trim((string) ($s['allergies'] ?? ''));
    if (strcasecmp($allergy, 'none') === 0) {
        $allergy = '';
    }

    $school = function_exists('student_idcard_school') ? student_idcard_school() : [];
    $schoolName = trim((string) ($school['name'] ?? ''));
    if ($schoolName === '') {
        $schoolName = defined('APP_NAME') ? (string) APP_NAME : 'School';
    }

    return [
        'student' => $s,
        'id' => $studentId,
        'name' => $name !== '' ? $name : ('Student #' . $studentId),
        'class' => trim((string) ($s['class_name'] ?? '')),
        'form' => trim((string) ($s['form_no'] ?? '')),
        'photo' => function_exists('student_photo_url') ? student_photo_url((string) ($s['photo_path'] ?? '')) : '',
        'month' => $ym,
        'month_label' => $label,
        'school' => $schoolName,
        'attendance' => $att,
        'fees' => $fees,
        'paid_month' => $paidMonth,
        'homework' => $hw,
        'remark' => $remark,
        'allergy' => $allergy,
        'emergency' => $em,
        'dob' => substr((string) ($s['dob'] ?? ''), 0, 10),
        'ay' => trim((string) ($s['academic_year'] ?? '')),
    ];
}

/**
 * @param array<string, mixed> $r
 */
function student_report_text(array $r): string
{
    $lines = [];
    $lines[] = (string) ($r['school'] ?? 'School');
    $lines[] = 'Month report — ' . (string) ($r['name'] ?? '') . ((string) ($r['class'] ?? '') !== '' ? ' (' . $r['class'] . ')' : '');
    $lines[] = (string) ($r['month_label'] ?? '');
    $lines[] = '';
    $att = is_array($r['attendance'] ?? null) ? $r['attendance'] : [];
    $lines[] = 'Attendance: ' . (int) ($att['present'] ?? 0) . ' present, '
        . (int) ($att['absent'] ?? 0) . ' absent, ' . (int) ($att['leave'] ?? 0) . ' leave';
    $fees = is_array($r['fees'] ?? null) ? $r['fees'] : [];
    $due = (float) ($fees['remaining'] ?? 0);
    $lines[] = $due < 0.5
        ? 'Fees: paid'
        : ('Fees still due: Rs ' . number_format($due, 0));
    $hw = trim((string) ($r['homework'] ?? ''));
    if ($hw !== '') {
        $lines[] = 'Homework: ' . $hw;
    }
    $rm = trim((string) ($r['remark'] ?? ''));
    if ($rm !== '') {
        $lines[] = 'Teacher: ' . $rm;
    }
    $lines[] = '';
    $lines[] = 'Pay fees at school. Open Parent app for photos and notices.';
    return implode("\n", $lines);
}

function student_report_phone10(array $student): string
{
    $em = function_exists('student_idcard_emergency') ? student_idcard_emergency($student) : ['phone' => ''];
    $d = preg_replace('/\D+/', '', (string) ($em['phone'] ?? '')) ?? '';
    return strlen($d) >= 10 ? substr($d, -10) : '';
}

function student_report_email(array $student): string
{
    foreach (['father_email', 'mother_email', 'guardian_email'] as $k) {
        $e = trim((string) ($student[$k] ?? ''));
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
            return $e;
        }
    }
    if (function_exists('parent_email_from_user_row') && !empty($student['parent_id'])) {
        $u = safe_db_get_one('SELECT * FROM users WHERE id = :id LIMIT 1', [':id' => (int) $student['parent_id']]);
        if ($u) {
            $e = parent_email_from_user_row($u);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                return $e;
            }
        }
    }
    return '';
}

/**
 * @return array{ok: bool, error: string}
 */
function student_report_send_whatsapp(string $phone10, string $text): array
{
    $phone10 = preg_replace('/\D+/', '', $phone10) ?? '';
    $phone10 = strlen($phone10) >= 10 ? substr($phone10, -10) : '';
    if ($phone10 === '') {
        return ['ok' => false, 'error' => 'No parent WhatsApp number.'];
    }
    $waFile = __DIR__ . '/whatsapp_config.php';
    if (is_file($waFile)) {
        require_once $waFile;
    }
    if (!function_exists('whatsapp_send_text')) {
        return ['ok' => false, 'error' => 'WhatsApp is not set up.'];
    }
    $res = whatsapp_send_text('+91' . $phone10, $text);
    $ok = !empty($res['success']) || !empty($res['ok']);
    return ['ok' => $ok, 'error' => $ok ? '' : (string) ($res['error'] ?? 'Could not send WhatsApp.')];
}

/**
 * @return array{ok: bool, error: string}
 */
function student_report_send_email(string $to, string $subject, string $text): array
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'No parent email.'];
    }
    if (!function_exists('otp_settings_load')) {
        return ['ok' => false, 'error' => 'Email is not set up.'];
    }
    $cfg = otp_settings_load();
    $em = is_array($cfg['email'] ?? null) ? $cfg['email'] : [];
    $token = function_exists('otp_zeptomail_token') ? otp_zeptomail_token((string) ($em['send_mail_token'] ?? '')) : '';
    $from = trim((string) ($em['from_email'] ?? ''));
    if ($token === '' || $from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || !function_exists('otp_http_json')) {
        return ['ok' => false, 'error' => 'Email is not set up (ZeptoMail).'];
    }
    $fromName = trim((string) ($em['from_name'] ?? ''));
    $fromObj = ['address' => $from];
    if ($fromName !== '') {
        $fromObj['name'] = $fromName;
    }
    $payload = [
        'from' => $fromObj,
        'to' => [['email_address' => ['address' => $to]]],
        'subject' => $subject,
        'textbody' => $text,
    ];
    $url = function_exists('otp_zeptomail_url') ? otp_zeptomail_url((string) ($em['data_center'] ?? 'in')) : '';
    $res = otp_http_json($url, ['Authorization: Zoho-enczapikey ' . $token], $payload);
    $ok = !empty($res['ok']);
    return ['ok' => $ok, 'error' => $ok ? '' : (string) ($res['error'] ?? 'Could not send email.')];
}

function student_report_can_view_full(): bool
{
    if (function_exists('auth_is_owner_super') && auth_is_owner_super()) {
        return true;
    }
    $role = function_exists('auth_role') ? (string) auth_role() : '';
    return in_array($role, ['owner', 'reception', 'teacher'], true);
}
