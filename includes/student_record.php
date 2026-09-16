<?php
/**
 * Student row helpers: copy nested extended_json into real columns,
 * and treat table columns as the source of truth for view/edit/PDF.
 */
declare(strict_types=1);

/**
 * @return array<string,mixed>
 */
function student_decode_extended(mixed $json): array
{
    if (is_array($json)) {
        return $json;
    }
    $raw = trim((string) $json);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function student_nonempty(mixed $value): bool
{
    if ($value === null) {
        return false;
    }
    if (is_string($value)) {
        return trim($value) !== '';
    }
    if (is_int($value) || is_float($value)) {
        return true;
    }
    return false;
}

function student_json_get(array $json, string $dotPath): mixed
{
    $cur = $json;
    foreach (explode('.', $dotPath) as $key) {
        if (!is_array($cur) || !array_key_exists($key, $cur)) {
            return null;
        }
        $cur = $cur[$key];
    }
    return $cur;
}

/**
 * Map nested JSON (admission payload) onto students table column names.
 *
 * @param array<string,mixed> $json
 * @return array<string,mixed>
 */
function student_flatten_extended(array $json): array
{
    $fees = is_array($json['fees'] ?? null) ? $json['fees'] : [];
    $inst = is_array($fees['installments'] ?? null) ? array_values($fees['installments']) : [];
    $siblings = is_array($json['siblings'] ?? null) ? $json['siblings'] : [];
    $student = is_array($json['student'] ?? null) ? $json['student'] : [];
    $address = is_array($json['address'] ?? null) ? $json['address'] : [];
    $father = is_array($json['father'] ?? null) ? $json['father'] : [];
    $mother = is_array($json['mother'] ?? null) ? $json['mother'] : [];
    $guardian = is_array($json['guardian'] ?? null) ? $json['guardian'] : [];
    $medical = is_array($json['medical'] ?? null) ? $json['medical'] : [];

    $classFromJson = $json['admission_seeking_in'] ?? $json['class_id'] ?? null;

    return [
        'form_no' => $json['form_no'] ?? null,
        'location' => $json['location'] ?? null,
        'academic_year' => $json['academic_year'] ?? null,
        'class_id' => $classFromJson,
        'first_name' => $student['first'] ?? null,
        'middle_name' => $student['middle'] ?? null,
        'last_name' => $student['last'] ?? null,
        'dob' => $student['dob'] ?? null,
        'gender' => $student['gender'] ?? ($json['gender'] ?? null),
        'place_of_birth' => $json['place_of_birth'] ?? null,
        'nationality' => $json['nationality'] ?? null,
        'caste' => $json['caste'] ?? null,
        'languages' => $json['languages'] ?? null,
        'address' => $address['address'] ?? ($json['address'] ?? null),
        'city' => $address['city'] ?? null,
        'state' => $address['state'] ?? null,
        'country' => $address['country'] ?? null,
        'pin' => $address['pin'] ?? null,
        'father_first' => $father['first'] ?? null,
        'father_middle' => $father['middle'] ?? null,
        'father_last' => $father['last'] ?? null,
        'father_email' => $father['email'] ?? null,
        'father_edu' => $father['edu'] ?? ($father['education'] ?? null),
        'father_prof' => $father['prof'] ?? ($father['profession'] ?? null),
        'father_designation' => $father['designation'] ?? null,
        'father_phone' => $father['phone'] ?? null,
        'mother_first' => $mother['first'] ?? null,
        'mother_middle' => $mother['middle'] ?? null,
        'mother_last' => $mother['last'] ?? null,
        'mother_email' => $mother['email'] ?? null,
        'mother_edu' => $mother['edu'] ?? ($mother['education'] ?? null),
        'mother_prof' => $mother['prof'] ?? ($mother['profession'] ?? null),
        'mother_designation' => $mother['designation'] ?? null,
        'mother_phone' => $mother['phone'] ?? null,
        'guardian_name' => $guardian['name'] ?? null,
        'guardian_email' => $guardian['email'] ?? null,
        'guardian_relation' => $guardian['relation'] ?? null,
        'guardian_phone' => $guardian['phone'] ?? null,
        'previous_school' => $json['previous_school'] ?? null,
        'allergies' => $medical['allergies'] ?? null,
        'health_conditions' => $medical['health_conditions'] ?? null,
        'current_medications' => $medical['current_medications'] ?? null,
        'immunization_records' => $medical['immunization_records'] ?? null,
        'sibling1' => $siblings['1'] ?? ($siblings[1] ?? null),
        'sibling2' => $siblings['2'] ?? ($siblings[2] ?? null),
        'additional_info' => $json['additional_info'] ?? null,
        'parent_signature' => $json['parent_signature'] ?? null,
        'total_fees' => $fees['total'] ?? null,
        'installment1' => $inst[0] ?? null,
        'installment2' => $inst[1] ?? null,
        'installment3' => $inst[2] ?? null,
        'remark' => $fees['remark'] ?? null,
        'stamp' => $fees['stamp'] ?? null,
    ];
}

/**
 * Fill empty student columns from extended_json. Columns win when already set.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function student_hydrate_row(array $row): array
{
    $flat = student_flatten_extended(student_decode_extended($row['extended_json'] ?? null));
    foreach ($flat as $col => $val) {
        if (!student_nonempty($val)) {
            continue;
        }
        if (!student_nonempty($row[$col] ?? null)) {
            $row[$col] = $val;
        }
    }
    return $row;
}

/**
 * @param array<string,mixed> $row hydrated student
 * @return array<string,mixed>
 */
function student_extended_from_row(array $row): array
{
    return [
        'form_no' => $row['form_no'] ?? '',
        'location' => $row['location'] ?? '',
        'academic_year' => $row['academic_year'] ?? '',
        'admission_seeking_in' => $row['class_id'] ?? '',
        'student' => [
            'first' => $row['first_name'] ?? '',
            'middle' => $row['middle_name'] ?? '',
            'last' => $row['last_name'] ?? '',
            'dob' => $row['dob'] ?? '',
            'gender' => $row['gender'] ?? '',
        ],
        'place_of_birth' => $row['place_of_birth'] ?? '',
        'nationality' => $row['nationality'] ?? '',
        'caste' => $row['caste'] ?? '',
        'languages' => $row['languages'] ?? '',
        'address' => [
            'address' => $row['address'] ?? '',
            'city' => $row['city'] ?? '',
            'state' => $row['state'] ?? '',
            'country' => $row['country'] ?? '',
            'pin' => $row['pin'] ?? '',
        ],
        'father' => [
            'first' => $row['father_first'] ?? '',
            'middle' => $row['father_middle'] ?? '',
            'last' => $row['father_last'] ?? '',
            'email' => $row['father_email'] ?? '',
            'edu' => $row['father_edu'] ?? '',
            'prof' => $row['father_prof'] ?? '',
            'designation' => $row['father_designation'] ?? '',
            'phone' => $row['father_phone'] ?? '',
        ],
        'mother' => [
            'first' => $row['mother_first'] ?? '',
            'middle' => $row['mother_middle'] ?? '',
            'last' => $row['mother_last'] ?? '',
            'email' => $row['mother_email'] ?? '',
            'edu' => $row['mother_edu'] ?? '',
            'prof' => $row['mother_prof'] ?? '',
            'designation' => $row['mother_designation'] ?? '',
            'phone' => $row['mother_phone'] ?? '',
        ],
        'guardian' => [
            'name' => $row['guardian_name'] ?? '',
            'email' => $row['guardian_email'] ?? '',
            'phone' => $row['guardian_phone'] ?? '',
            'relation' => $row['guardian_relation'] ?? '',
        ],
        'previous_school' => $row['previous_school'] ?? '',
        'medical' => [
            'allergies' => $row['allergies'] ?? '',
            'health_conditions' => $row['health_conditions'] ?? '',
            'current_medications' => $row['current_medications'] ?? '',
            'immunization_records' => $row['immunization_records'] ?? '',
        ],
        'siblings' => [
            '1' => $row['sibling1'] ?? '',
            '2' => $row['sibling2'] ?? '',
        ],
        'additional_info' => $row['additional_info'] ?? '',
        'parent_signature' => $row['parent_signature'] ?? '',
        'fees' => [
            'total' => $row['total_fees'] ?? '',
            'installments' => [
                $row['installment1'] ?? '',
                $row['installment2'] ?? '',
                $row['installment3'] ?? '',
            ],
            'remark' => $row['remark'] ?? '',
            'stamp' => $row['stamp'] ?? '',
        ],
    ];
}

function student_full_name(array $row): string
{
    return trim(preg_replace(
        '/\s+/',
        ' ',
        trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['middle_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''))
    ) ?? '');
}

function student_dash(mixed $value): string
{
    $s = trim((string) ($value ?? ''));
    return $s === '' ? '—' : $s;
}

function student_list_url(): string
{
    $role = function_exists('auth_role') ? (string) auth_role() : 'reception';
    if ($role === 'owner' && function_exists('site_url')) {
        return site_url('/owner/students_list.php');
    }
    if (function_exists('site_url')) {
        return site_url('/reception/students_list.php');
    }
    return '../reception/students_list.php';
}

function student_view_url(int $id): string
{
    $role = function_exists('auth_role') ? (string) auth_role() : 'reception';
    $path = $role === 'owner'
        ? '/owner/students_view.php?id=' . $id
        : '/reception/students_view.php?id=' . $id;
    return function_exists('site_url') ? site_url($path) : $path;
}

function student_edit_url(int $id): string
{
    $role = function_exists('auth_role') ? (string) auth_role() : 'reception';
    $path = $role === 'owner'
        ? '/owner/students_edit.php?id=' . $id
        : '/reception/students_list_edit.php?id=' . $id;
    return function_exists('site_url') ? site_url($path) : $path;
}

function student_print_url(int $id): string
{
    $path = '/reception/students_form_print.php?id=' . $id;
    return function_exists('site_url') ? site_url($path) : $path;
}

/**
 * @return array{receipt: string, method: string, note: string}
 */
function student_parse_receipt_meta(?string $receiptNo): array
{
    $raw = trim((string) $receiptNo);
    $out = ['receipt' => $raw, 'method' => '', 'note' => ''];
    if ($raw === '') {
        return $out;
    }
    $parts = explode('||', $raw);
    $out['receipt'] = trim((string) ($parts[0] ?? $raw));
    foreach ($parts as $i => $p) {
        if ($i === 0) {
            continue;
        }
        $p = trim($p);
        if (stripos($p, 'METHOD:') === 0) {
            $out['method'] = trim(substr($p, 7));
        } elseif (stripos($p, 'NOTE:') === 0) {
            $out['note'] = trim(substr($p, 5));
        }
    }
    return $out;
}

/**
 * @return array<int, array<string,mixed>>
 */
function student_fee_payments(int $studentId): array
{
    if ($studentId <= 0 || !function_exists('table_exists') || !table_exists('fees_records')) {
        return [];
    }
    $rows = safe_db_get_all(
        "SELECT fr.id, fr.receipt_no, fr.amount, fr.paid_amount, fr.status, fr.collected_at, fr.created_at, fr.collected_by,
                COALESCE(u.name, '') AS collector_name
         FROM fees_records fr
         LEFT JOIN users u ON u.id = fr.collected_by
         WHERE fr.student_id = :id
         ORDER BY COALESCE(fr.collected_at, fr.created_at) ASC, fr.id ASC",
        [':id' => $studentId]
    ) ?: [];
    foreach ($rows as &$r) {
        $meta = student_parse_receipt_meta((string) ($r['receipt_no'] ?? ''));
        $r['receipt_display'] = $meta['receipt'];
        $r['payment_type'] = $meta['method'];
        $r['payment_note'] = $meta['note'];
    }
    unset($r);
    return $rows;
}

/**
 * @param array<string,mixed> $student
 * @return array{total: float, paid: float, remaining: float}
 */
function student_fee_summary(array $student): array
{
    $total = (float) ($student['total_fees'] ?? 0);
    $paid = 0.0;
    $id = (int) ($student['id'] ?? 0);
    if ($id > 0 && function_exists('table_exists') && table_exists('fees_records')) {
        $row = safe_db_get_one(
            "SELECT COALESCE(SUM(paid_amount),0) AS paid_sum FROM fees_records WHERE student_id = :id",
            [':id' => $id]
        );
        $paid = (float) ($row['paid_sum'] ?? 0);
    }
    return [
        'total' => $total,
        'paid' => $paid,
        'remaining' => max(0.0, $total - $paid),
    ];
}
