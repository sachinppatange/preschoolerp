<?php
/**
 * Enquiry helpers — website, reception/owner add, WhatsApp auto-create.
 */
declare(strict_types=1);

function enquiry_phone(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($d) >= 10) {
        return substr($d, -10);
    }
    return $d;
}

function enquiry_ensure_schema(): void
{
    static $done = false;
    if ($done || !function_exists('db_execute')) {
        return;
    }
    $done = true;
    try {
        if (function_exists('column_exists') && !column_exists('enquiries', 'age_group')) {
            db_execute('ALTER TABLE enquiries ADD COLUMN age_group VARCHAR(16) NULL AFTER name');
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function enquiry_school_id(): int
{
    if (function_exists('auth_school_id')) {
        $id = auth_school_id();
        if ($id > 0) {
            return $id;
        }
    }
    return (int) ($_SESSION['school_id'] ?? 1);
}

function enquiry_has_age_column(): bool
{
    return function_exists('column_exists') && column_exists('enquiries', 'age_group');
}

function enquiry_is_interest(string $body): bool
{
    $t = strtolower(trim(preg_replace('/\s+/', ' ', $body) ?? ''));
    if ($t === '') {
        return false;
    }
    foreach ([
        'i am interested',
        "i'm interested",
        'im interested',
        'interested',
        'i want admission',
        'want admission',
        'new admission',
        'admission please',
    ] as $needle) {
        if (str_contains($t, $needle)) {
            return true;
        }
    }
    return false;
}

function enquiry_find_open(string $phone): ?array
{
    $phone = enquiry_phone($phone);
    if ($phone === '' || !function_exists('db_fetch_one')) {
        return null;
    }
    try {
        $row = db_fetch_one(
            "SELECT id, name, phone, status FROM enquiries
             WHERE RIGHT(REPLACE(REPLACE(IFNULL(phone,''),'+',''),' ',''), 10) = ?
               AND IFNULL(status,'new') NOT IN ('closed','converted')
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY id DESC LIMIT 1",
            [$phone]
        );
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{ok:bool,id:int,duplicate:bool,error:?string}
 */
function enquiry_create(array $data): array
{
    enquiry_ensure_schema();
    $name = trim((string) ($data['name'] ?? ''));
    $phone = enquiry_phone((string) ($data['phone'] ?? ''));
    $source = trim((string) ($data['source'] ?? 'owner_added'));
    $message = trim((string) ($data['message'] ?? ''));
    $age = trim((string) ($data['age_group'] ?? ''));
    $schoolId = (int) ($data['school_id'] ?? enquiry_school_id());
    if ($schoolId < 1) {
        $schoolId = 1;
    }
    $assigned = isset($data['assigned_to']) && $data['assigned_to'] !== '' && $data['assigned_to'] !== null
        ? (int) $data['assigned_to'] : null;
    $status = trim((string) ($data['status'] ?? 'new'));
    if (!in_array($status, ['new', 'contacted', 'converted', 'closed'], true)) {
        $status = 'new';
    }
    if ($name === '') {
        return ['ok' => false, 'id' => 0, 'duplicate' => false, 'error' => 'Name required'];
    }
    if (strlen($phone) < 10) {
        return ['ok' => false, 'id' => 0, 'duplicate' => false, 'error' => 'Valid mobile required'];
    }
    if ($age !== '' && !enquiry_has_age_column()) {
        $message = "Age group: {$age}\n" . $message;
    }
    if (!function_exists('db_execute')) {
        return ['ok' => false, 'id' => 0, 'duplicate' => false, 'error' => 'Database not available'];
    }
    try {
        if (enquiry_has_age_column()) {
            db_execute(
                'INSERT INTO enquiries (school_id, name, age_group, phone, source, message, assigned_to, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$schoolId, $name, $age !== '' ? $age : null, $phone, $source, $message !== '' ? $message : null, $assigned, $status]
            );
        } else {
            db_execute(
                'INSERT INTO enquiries (school_id, name, phone, source, message, assigned_to, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$schoolId, $name, $phone, $source, $message !== '' ? $message : null, $assigned, $status]
            );
        }
        $id = function_exists('db_last_insert_id') ? (int) db_last_insert_id() : 0;
        return ['ok' => true, 'id' => $id, 'duplicate' => false, 'error' => null];
    } catch (Throwable $e) {
        if (function_exists('whatsapp_log')) {
            whatsapp_log('enquiry_create_failed', ['error' => $e->getMessage(), 'phone' => $phone]);
        }
        return ['ok' => false, 'id' => 0, 'duplicate' => false, 'error' => $e->getMessage()];
    }
}

/**
 * @return array{ok:bool,id:int,duplicate:bool,created:bool}
 */
function enquiry_from_whatsapp(string $phone, string $name, string $message): array
{
    $phone = enquiry_phone($phone);
    $name = trim($name);
    if ($name === '') {
        $name = function_exists('wa_inbox_label') ? wa_inbox_label('91' . $phone) : '';
    }
    if ($name === '' || preg_match('/^\+?91?\s?\d/', $name)) {
        $name = 'WhatsApp parent';
    }
    $existing = enquiry_find_open($phone);
    if ($existing) {
        $id = (int) ($existing['id'] ?? 0);
        if ($id > 0 && function_exists('db_execute') && $message !== '') {
            try {
                db_execute(
                    "UPDATE enquiries SET message = CONCAT(IFNULL(message,''), ?), updated_at = NOW() WHERE id = ?",
                    ["\n" . $message, $id]
                );
            } catch (Throwable $e) {
                // ignore follow-up append
            }
        }
        return ['ok' => true, 'id' => $id, 'duplicate' => true, 'created' => false];
    }
    $res = enquiry_create([
        'name' => $name,
        'phone' => $phone,
        'source' => 'whatsapp',
        'message' => $message !== '' ? $message : 'I am interested',
        'school_id' => enquiry_school_id(),
    ]);
    return [
        'ok' => !empty($res['ok']),
        'id' => (int) ($res['id'] ?? 0),
        'duplicate' => false,
        'created' => !empty($res['ok']),
    ];
}

function enquiry_source_label(string $source): string
{
    return match ($source) {
        'whatsapp' => 'WhatsApp',
        'website_enquiry' => 'Website',
        'owner_added' => 'Owner',
        'reception_added', 'reception_updated' => 'Reception',
        'Survey' => 'Survey',
        'Online' => 'Online',
        default => $source !== '' ? $source : '—',
    };
}

function enquiry_status_label(string $status): string
{
    return match ($status) {
        'new' => 'New',
        'contacted' => 'Contacted',
        'converted' => 'Converted',
        'closed' => 'Closed',
        'in_progress' => 'Contacted',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function enquiry_status_badge(string $status): string
{
    return match ($status) {
        'new' => 'bg-primary',
        'contacted', 'in_progress' => 'bg-warning text-dark',
        'converted' => 'bg-success',
        'closed' => 'bg-secondary',
        default => 'bg-light text-dark',
    };
}

function enquiry_age_from_row(array $r): string
{
    if (!empty($r['age_group'])) {
        return (string) $r['age_group'];
    }
    $msg = (string) ($r['message'] ?? '');
    if (preg_match('/^Age group:\s*([^\r\n]+)/i', $msg, $m)) {
        return trim($m[1]);
    }
    return '';
}
