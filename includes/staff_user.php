<?php
/**
 * Staff user helpers (accounts / teacher / reception / staff).
 * Parents are created from admission, not from Staff & Users.
 */
declare(strict_types=1);

function staff_all_roles(): array
{
    return [
        'owner' => 'Owner',
        'accounts' => 'Accounts',
        'teacher' => 'Teacher',
        'reception' => 'Reception',
        'parent' => 'Parent',
        'staff' => 'Staff',
    ];
}

/** Roles that can be added from Staff & Users (not parent, not owner). */
function staff_addable_roles(): array
{
    return [
        'accounts' => 'Accounts',
        'teacher' => 'Teacher',
        'reception' => 'Reception',
        'staff' => 'Staff',
    ];
}

function staff_staff_roles(): array
{
    return [
        'owner' => 'Owner',
        'accounts' => 'Accounts',
        'teacher' => 'Teacher',
        'reception' => 'Reception',
        'staff' => 'Staff',
    ];
}

function staff_people_nav(string $active): void
{
    $staffUrl = function_exists('site_url') ? site_url('/owner/staff_manage.php') : 'staff_manage.php';
    $parentUrl = function_exists('site_url') ? site_url('/owner/parents.php') : 'parents.php';
    $staffN = 0;
    $parentN = 0;
    try {
        $a = safe_db_get_one("SELECT COUNT(*) AS c FROM users WHERE role <> 'parent'");
        $staffN = (int) ($a['c'] ?? 0);
        $ay = function_exists('ay_selected') ? ay_selected() : '';
        if ($ay !== '' && function_exists('parent_ids_with_child_in_year')) {
            $parentN = count(parent_ids_with_child_in_year($ay));
        } else {
            $b = safe_db_get_one("SELECT COUNT(*) AS c FROM users WHERE role = 'parent'");
            $parentN = (int) ($b['c'] ?? 0);
        }
    } catch (Throwable $e) {
        // ignore
    }
    $staffCls = $active === 'staff' ? 'btn-primary' : 'btn-outline-primary';
    $parentCls = $active === 'parents' ? 'btn-primary' : 'btn-outline-primary';
    echo '<div class="d-flex flex-wrap gap-2 mb-3">';
    echo '<a class="btn ' . $staffCls . '" href="' . htmlspecialchars($staffUrl, ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-person-badge me-1"></i> Staff (' . $staffN . ')</a>';
    echo '<a class="btn ' . $parentCls . '" href="' . htmlspecialchars($parentUrl, ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-people me-1"></i> Parents (' . $parentN . ')</a>';
    echo '</div>';
}

/**
 * @param list<int> $parentIds
 * @return array<int, list<array<string,mixed>>>
 */
function staff_children_by_parent_ids(array $parentIds, ?string $ay = null): array
{
    $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds))));
    if ($parentIds === []) {
        return [];
    }
    $in = implode(',', $parentIds);
    $aySql = '';
    if (is_string($ay) && preg_match('/^\d{4}-\d{2}$/', $ay)) {
        $aySql = " AND s.academic_year = '" . $ay . "'";
    }
    $map = [];
    foreach ($parentIds as $id) {
        $map[$id] = [];
    }
    $seen = [];
    try {
        $rows = [];
        if (function_exists('table_exists') && table_exists('students')) {
            $rows = safe_db_get_all(
                "SELECT s.id, s.parent_id, s.first_name, s.middle_name, s.last_name, s.status, s.academic_year,
                        s.class_id, cl.name AS class_name
                 FROM students s
                 LEFT JOIN classes cl ON cl.id = s.class_id
                 WHERE s.parent_id IN ($in) $aySql
                 ORDER BY s.first_name ASC, s.last_name ASC"
            ) ?: [];
        }
        if (function_exists('table_exists') && table_exists('parents_children')) {
            $extra = safe_db_get_all(
                "SELECT s.id, pc.parent_user_id AS parent_id, s.first_name, s.middle_name, s.last_name, s.status,
                        s.academic_year, s.class_id, cl.name AS class_name
                 FROM parents_children pc
                 INNER JOIN students s ON s.id = pc.child_student_id
                 LEFT JOIN classes cl ON cl.id = s.class_id
                 WHERE pc.parent_user_id IN ($in) $aySql
                 ORDER BY s.first_name ASC"
            ) ?: [];
            $rows = array_merge($rows, $extra);
        }
        foreach ($rows as $r) {
            $pid = (int) ($r['parent_id'] ?? 0);
            $sid = (int) ($r['id'] ?? 0);
            if ($pid <= 0 || $sid <= 0) {
                continue;
            }
            $key = $pid . ':' . $sid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $name = trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['middle_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''));
            $name = preg_replace('/\s+/', ' ', $name) ?? $name;
            $r['display_name'] = $name !== '' ? $name : ('Student #' . $sid);
            $map[$pid][] = $r;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $map;
}

function staff_child_label(array $child): string
{
    $label = (string) ($child['display_name'] ?? '');
    $cls = trim((string) ($child['class_name'] ?? ''));
    if ($cls !== '') {
        $label .= ' (' . $cls . ')';
    }
    return $label;
}

function staff_phone_last10(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    return strlen($digits) >= 10 ? substr($digits, -10) : $digits;
}

function staff_e164(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($d) === 10) {
        return '+91' . $d;
    }
    if (strlen($d) === 12 && str_starts_with($d, '91')) {
        return '+' . $d;
    }
    return $d !== '' ? '+' . $d : '';
}

function staff_has_phone_last10_column(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $has = function_exists('column_exists') && column_exists('users', 'phone_last10');
    return $has;
}

function staff_user_email_from_meta($meta): string
{
    if (is_string($meta) && $meta !== '') {
        $decoded = json_decode($meta, true);
        $meta = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($meta)) {
        return '';
    }
    $email = trim((string) ($meta['email'] ?? ''));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function staff_meta_with_email(?string $existingJson, string $email): string
{
    $meta = [];
    if ($existingJson !== null && $existingJson !== '') {
        $decoded = json_decode($existingJson, true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    $email = trim($email);
    if ($email !== '') {
        $meta['email'] = $email;
        $meta['email_verified'] = true;
        $meta['phone_verified'] = true;
        $meta['whatsapp_verified'] = true;
    }
    return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function staff_find_by_phone10(string $last10, int $exceptId = 0): ?array
{
    if (strlen($last10) !== 10) {
        return null;
    }
    $sql = "SELECT id, name, role, phone, whatsapp_id FROM users
            WHERE (
                RIGHT(REPLACE(REPLACE(REPLACE(IFNULL(phone,''), '+', ''), ' ', ''), '-', ''), 10) = ?
                OR RIGHT(REPLACE(REPLACE(REPLACE(IFNULL(whatsapp_id,''), '+', ''), ' ', ''), '-', ''), 10) = ?
            )";
    $params = [$last10, $last10];
    if ($exceptId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    $sql .= ' ORDER BY id ASC LIMIT 1';
    $row = function_exists('safe_db_get_one') ? safe_db_get_one($sql, $params) : db_fetch_one($sql, $params);
    return $row ?: null;
}

function staff_generate_otp(): string
{
    $len = defined('OTP_LENGTH') ? (int) OTP_LENGTH : 4;
    $len = max(4, min(8, $len));
    $min = (int) pow(10, $len - 1);
    $max = (int) pow(10, $len) - 1;
    try {
        return (string) random_int($min, $max);
    } catch (Throwable $e) {
        return (string) mt_rand($min, $max);
    }
}

function staff_add_otp_ctx(): array
{
    if (empty($_SESSION['staff_add_otp']) || !is_array($_SESSION['staff_add_otp'])) {
        $_SESSION['staff_add_otp'] = [];
    }
    return $_SESSION['staff_add_otp'];
}

function staff_add_otp_store(array $ctx): void
{
    $_SESSION['staff_add_otp'] = $ctx;
}

function staff_add_otp_clear(): void
{
    unset($_SESSION['staff_add_otp']);
}

/**
 * @return array{ok:bool,error?:string,info?:string,same_phone?:bool,expires_in?:int}
 */
function staff_send_add_otps(string $phone, string $whatsapp, string $email): array
{
    $phone10 = staff_phone_last10($phone);
    $wa10 = staff_phone_last10($whatsapp !== '' ? $whatsapp : $phone);
    $email = strtolower(trim($email));
    if (strlen($phone10) !== 10) {
        return ['ok' => false, 'error' => 'Enter a valid 10-digit mobile number.'];
    }
    if (strlen($wa10) !== 10) {
        return ['ok' => false, 'error' => 'Enter a valid 10-digit WhatsApp number.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }
    $taken = staff_find_by_phone10($phone10);
    if ($taken) {
        return ['ok' => false, 'error' => 'This mobile number is already used by ' . ($taken['name'] ?? 'another user') . ' (' . ($taken['role'] ?? '') . ').'];
    }
    if ($wa10 !== $phone10) {
        $waTaken = staff_find_by_phone10($wa10);
        if ($waTaken) {
            return ['ok' => false, 'error' => 'This WhatsApp number is already used by ' . ($waTaken['name'] ?? 'another user') . '.'];
        }
    }

    $ctx = staff_add_otp_ctx();
    $now = time();
    if (!empty($ctx['sent_at']) && ($now - (int) $ctx['sent_at']) < 45) {
        $wait = 45 - ($now - (int) $ctx['sent_at']);
        return ['ok' => false, 'error' => "Please wait {$wait} seconds before resending OTPs."];
    }

    $minutes = function_exists('otp_validity_minutes') ? otp_validity_minutes() : 5;
    $expires = $now + max(60, $minutes * 60);
    $phoneOtp = staff_generate_otp();
    $emailOtp = staff_generate_otp();
    $same = ($phone10 === $wa10);
    $waOtp = $same ? $phoneOtp : staff_generate_otp();

    $fail = [];
    if (!function_exists('otp_send_sms') || !function_exists('otp_send_whatsapp') || !function_exists('otp_send_email')) {
        return ['ok' => false, 'error' => 'OTP helpers are not loaded. Open OTP Settings first.'];
    }

    $sms = otp_send_sms($phone10, $phoneOtp);
    if (empty($sms['ok'])) {
        $fail[] = 'SMS: ' . (string) ($sms['error'] ?? 'failed');
    }
    $wa = otp_send_whatsapp(staff_e164($wa10), $waOtp);
    if (empty($wa['ok'])) {
        $fail[] = 'WhatsApp: ' . (string) ($wa['error'] ?? 'failed');
    }
    $em = otp_send_email($email, $emailOtp);
    if (empty($em['ok'])) {
        $fail[] = 'Email: ' . (string) ($em['error'] ?? 'failed');
    }
    if ($fail !== []) {
        return ['ok' => false, 'error' => 'Could not send all OTPs. ' . implode(' ', $fail)];
    }

    staff_add_otp_store([
        'phone10' => $phone10,
        'wa10' => $wa10,
        'email' => $email,
        'phone_hash' => password_hash($phoneOtp, PASSWORD_DEFAULT),
        'wa_hash' => password_hash($waOtp, PASSWORD_DEFAULT),
        'email_hash' => password_hash($emailOtp, PASSWORD_DEFAULT),
        'phone_ok' => false,
        'wa_ok' => false,
        'email_ok' => false,
        'sent_at' => $now,
        'expires' => $expires,
        'same_phone' => $same,
    ]);

    $info = $same
        ? 'OTPs sent to mobile (SMS + WhatsApp) and email. Enter both codes below.'
        : 'OTPs sent to mobile (SMS), WhatsApp, and email. Enter all three codes below.';
    return ['ok' => true, 'info' => $info, 'same_phone' => $same, 'expires_in' => $expires - $now];
}

/**
 * @return array{ok:bool,error?:string,phone_ok?:bool,wa_ok?:bool,email_ok?:bool,all_ok?:bool}
 */
function staff_verify_add_otp(string $channel, string $otp): array
{
    $ctx = staff_add_otp_ctx();
    if (empty($ctx['expires']) || time() > (int) $ctx['expires']) {
        return ['ok' => false, 'error' => 'OTP expired. Send OTPs again.'];
    }
    $otp = trim($otp);
    if ($otp === '') {
        return ['ok' => false, 'error' => 'Enter the OTP.'];
    }
    $map = [
        'phone' => 'phone_hash',
        'whatsapp' => 'wa_hash',
        'email' => 'email_hash',
    ];
    if (!isset($map[$channel])) {
        return ['ok' => false, 'error' => 'Invalid channel.'];
    }
    $hash = (string) ($ctx[$map[$channel]] ?? '');
    if ($hash === '' || !password_verify($otp, $hash)) {
        return ['ok' => false, 'error' => 'Incorrect OTP.'];
    }
    if ($channel === 'phone') {
        $ctx['phone_ok'] = true;
        if (!empty($ctx['same_phone'])) {
            $ctx['wa_ok'] = true;
        }
    } elseif ($channel === 'whatsapp') {
        $ctx['wa_ok'] = true;
    } else {
        $ctx['email_ok'] = true;
    }
    staff_add_otp_store($ctx);
    $all = !empty($ctx['phone_ok']) && !empty($ctx['wa_ok']) && !empty($ctx['email_ok']);
    return [
        'ok' => true,
        'phone_ok' => !empty($ctx['phone_ok']),
        'wa_ok' => !empty($ctx['wa_ok']),
        'email_ok' => !empty($ctx['email_ok']),
        'all_ok' => $all,
    ];
}

function staff_add_otp_matches(string $phone, string $whatsapp, string $email): bool
{
    $ctx = staff_add_otp_ctx();
    if (empty($ctx['phone_ok']) || empty($ctx['wa_ok']) || empty($ctx['email_ok'])) {
        return false;
    }
    if (empty($ctx['expires']) || time() > (int) $ctx['expires']) {
        return false;
    }
    $phone10 = staff_phone_last10($phone);
    $wa10 = staff_phone_last10($whatsapp !== '' ? $whatsapp : $phone);
    $email = strtolower(trim($email));
    return ($ctx['phone10'] ?? '') === $phone10
        && ($ctx['wa10'] ?? '') === $wa10
        && strtolower((string) ($ctx['email'] ?? '')) === $email;
}
