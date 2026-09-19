<?php
/**
 * CR80 student ID card (85.6mm × 54mm) + signed QR scan URL.
 */
declare(strict_types=1);

function student_idcard_secret(): string
{
    $a = defined('DB_NAME') ? (string) DB_NAME : 'school';
    $b = defined('SESSION_COOKIE_NAME') ? (string) SESSION_COOKIE_NAME : 'pps';
    return hash('sha256', $a . '|idcard|' . $b);
}

function student_idcard_token(int $id): string
{
    return substr(hash_hmac('sha256', 'sid:' . $id, student_idcard_secret()), 0, 16);
}

function student_idcard_check(int $id, string $token): bool
{
    if ($id <= 0 || $token === '') {
        return false;
    }
    return hash_equals(student_idcard_token($id), $token);
}

function student_idcard_scan_url(int $id): string
{
    $q = 's=' . $id . '&t=' . student_idcard_token($id);
    if (function_exists('site_url')) {
        return site_url('/card.php?' . $q);
    }
    return '/card.php?' . $q;
}

function student_idcard_qr_src(string $data, int $size = 200): string
{
    $size = max(80, min(800, $size));
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&ecc=M&margin=1&data=' . rawurlencode($data);
}

/**
 * Stream a PNG QR for download. Returns false if fetch failed.
 */
function student_idcard_qr_download(int $studentId, string $filename = ''): bool
{
    if ($studentId <= 0) {
        return false;
    }
    $scan = student_idcard_scan_url($studentId);
    $src = student_idcard_qr_src($scan, 480);
    $bin = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($src);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($out) && $out !== '' && $code >= 200 && $code < 300) {
            $bin = $out;
        }
    }
    if ($bin === '' && ini_get('allow_url_fopen')) {
        $got = @file_get_contents($src);
        if (is_string($got) && $got !== '') {
            $bin = $got;
        }
    }
    if ($bin === '') {
        return false;
    }
    if ($filename === '') {
        $filename = 'student-' . $studentId . '-qr.png';
    }
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: ('student-' . $studentId . '-qr.png');
    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    return true;
}

function student_idcard_print_url(int $id, string $panel = 'owner'): string
{
    $path = $panel === 'parent'
        ? '/parent/id_card.php?student_id=' . $id . '&print=1'
        : ($panel === 'reception'
            ? '/reception/id_cards.php?id=' . $id . '&print=1'
            : '/owner/id_cards.php?id=' . $id . '&print=1');
    return function_exists('site_url') ? site_url($path) : $path;
}

function student_idcard_staff_url(int $id = 0, int $classId = 0): string
{
    $role = function_exists('auth_role') ? (string) auth_role() : 'owner';
    $base = $role === 'reception' ? '/reception/id_cards.php' : '/owner/id_cards.php';
    $q = [];
    if ($id > 0) {
        $q['id'] = $id;
    }
    if ($classId > 0) {
        $q['class_id'] = $classId;
    }
    $path = $base . ($q !== [] ? ('?' . http_build_query($q)) : '');
    return function_exists('site_url') ? site_url($path) : $path;
}

/**
 * @return array<string, mixed>
 */
function student_idcard_school(): array
{
    if (!function_exists('table_exists') || !table_exists('schools')) {
        return [];
    }
    return safe_db_get_one('SELECT * FROM schools ORDER BY id ASC LIMIT 1') ?: [];
}

/**
 * @return array<string, mixed>|null
 */
function student_idcard_load(int $id): ?array
{
    if ($id <= 0 || !function_exists('table_exists') || !table_exists('students')) {
        return null;
    }
    $join = table_exists('classes') ? 'LEFT JOIN classes c ON c.id = st.class_id' : '';
    $classSel = table_exists('classes') ? ", COALESCE(c.name,'') AS class_name" : ", '' AS class_name";
    $userJoin = table_exists('users') ? 'LEFT JOIN users u ON u.id = st.parent_id' : '';
    $userSel = table_exists('users')
        ? ", COALESCE(u.name,'') AS parent_login_name, COALESCE(u.phone,'') AS parent_login_phone"
        : ", '' AS parent_login_name, '' AS parent_login_phone";
    $row = safe_db_get_one(
        "SELECT st.*{$classSel}{$userSel}
         FROM students st {$join} {$userJoin}
         WHERE st.id = :id LIMIT 1",
        [':id' => $id]
    );
    if (!$row) {
        return null;
    }
    if (function_exists('student_hydrate_row')) {
        $row = student_hydrate_row($row);
    }
    return $row;
}

/**
 * @param array<string, mixed> $s
 * @return array{name: string, phone: string}
 */
function student_idcard_emergency(array $s): array
{
    $phones = [
        trim((string) ($s['father_phone'] ?? '')),
        trim((string) ($s['mother_phone'] ?? '')),
        trim((string) ($s['guardian_phone'] ?? '')),
        trim((string) ($s['parent_login_phone'] ?? '')),
    ];
    $phone = '';
    foreach ($phones as $p) {
        if ($p !== '') {
            $phone = $p;
            break;
        }
    }
    $name = trim((string) ($s['father_first'] ?? '') . ' ' . (string) ($s['father_last'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($s['mother_first'] ?? '') . ' ' . (string) ($s['mother_last'] ?? ''));
    }
    if ($name === '') {
        $name = trim((string) ($s['guardian_name'] ?? ''));
    }
    if ($name === '') {
        $name = trim((string) ($s['parent_login_name'] ?? ''));
    }
    return ['name' => $name, 'phone' => $phone];
}

function student_idcard_css(): string
{
    return <<<'CSS'
@page { size: 85.6mm 53.98mm; margin: 0; }
* { box-sizing: border-box; }
body.idc-print { margin: 0; background: #e8eef6; font-family: "Segoe UI", Arial, sans-serif; }
.idc-toolbar { position: sticky; top: 0; z-index: 5; background: #fff; padding: 10px 14px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; border-bottom: 1px solid #dbe7fb; }
.idc-toolbar .btn { background: #1d4ed8; color: #fff; border: 0; padding: 8px 14px; border-radius: 8px; cursor: pointer; font: inherit; text-decoration: none; display: inline-block; }
.idc-toolbar .btn2 { background: #fff; color: #1e3a5f; border: 1px solid #dbe7fb; }
.idc-hint { color: #64748b; font-size: .85rem; }
.idc-sheet { padding: 12px; display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; }
.idc {
  width: 85.6mm; height: 53.98mm;
  background: linear-gradient(180deg, #143a7a 0 16mm, #fff 16mm);
  border-radius: 3mm; overflow: hidden; position: relative;
  box-shadow: 0 8px 24px rgba(20,58,122,.18);
  color: #143a7a; page-break-after: always; break-after: page;
}
.idc-brand { height: 16mm; display: flex; align-items: center; gap: 2.5mm; padding: 0 3.5mm; color: #fff; }
.idc-brand img { height: 11mm; width: 11mm; object-fit: contain; background: #fff; border-radius: 2mm; padding: .6mm; }
.idc-brand .sch { font-weight: 800; font-size: 3.1mm; line-height: 1.15; }
.idc-brand .tag { font-size: 2.2mm; opacity: .9; letter-spacing: .08em; text-transform: uppercase; }
.idc-main { display: flex; gap: 2.5mm; padding: 2.2mm 3.2mm 2mm; height: 31mm; }
.idc-photo { width: 22mm; height: 26mm; object-fit: cover; border-radius: 2mm; background: #e2e8f0; flex-shrink: 0; border: .4mm solid #dbe7fb; }
.idc-info { flex: 1; min-width: 0; }
.idc-name { font-weight: 800; font-size: 4.1mm; line-height: 1.15; color: #0f2744; }
.idc-line { font-size: 2.6mm; color: #334155; margin-top: .6mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.idc-qr { width: 22mm; height: 22mm; background: #fff; padding: .6mm; border-radius: 1.5mm; flex-shrink: 0; }
.idc-qr img, .idc-qr canvas { width: 100%; height: 100%; display: block; }
.idc-foot { position: absolute; left: 0; right: 0; bottom: 0; height: 6.5mm; background: #f8fafc; border-top: .3mm solid #e2e8f0;
  display: flex; align-items: center; justify-content: space-between; padding: 0 3.5mm; font-size: 2.2mm; color: #64748b; }
@media print {
  .idc-toolbar { display: none !important; }
  body.idc-print { background: #fff; }
  .idc-sheet { padding: 0; gap: 0; }
  .idc { box-shadow: none; border-radius: 0; margin: 0; }
}
CSS;
}

/**
 * @param array<string, mixed> $s
 */
function student_idcard_markup(array $s): string
{
    $id = (int) ($s['id'] ?? 0);
    $name = function_exists('student_full_name') ? student_full_name($s) : trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
    if ($name === '') {
        $name = 'Student #' . $id;
    }
    $class = trim((string) ($s['class_name'] ?? ''));
    $form = trim((string) ($s['form_no'] ?? ''));
    $ay = trim((string) ($s['academic_year'] ?? ''));
    if ($ay !== '' && function_exists('ay_display_short')) {
        $ay = ay_display_short($ay);
    }
    $em = student_idcard_emergency($s);
    $photo = function_exists('student_photo_url') ? student_photo_url((string) ($s['photo_path'] ?? '')) : '';
    $scan = student_idcard_scan_url($id);
    $qr = student_idcard_qr_src($scan);
    $school = student_idcard_school();
    $schoolName = trim((string) ($school['name'] ?? ''));
    if ($schoolName === '') {
        $schoolName = defined('APP_NAME') ? (string) APP_NAME : 'Preschool';
    }
    $logo = '';
    if (!empty($school['logo_path']) && function_exists('resolve_image_url')) {
        $logo = (string) resolve_image_url((string) $school['logo_path'], '');
    } elseif (function_exists('resolve_image_url')) {
        $logo = (string) resolve_image_url('assets/images/logo.png', '');
    }
    $meta = $class !== '' ? $class : 'Class';
    if ($form !== '') {
        $meta .= ' · ' . $form;
    }
    $call = $em['phone'] !== '' ? ('Call ' . $em['phone']) : ($em['name'] !== '' ? $em['name'] : '');

    $e = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    $html = '<article class="idc" data-qr="' . $e($scan) . '">';
    $html .= '<div class="idc-brand">';
    if ($logo !== '') {
        $html .= '<img src="' . $e($logo) . '" alt="">';
    }
    $html .= '<div><div class="sch">' . $e($schoolName) . '</div><div class="tag">Student ID card</div></div></div>';
    $html .= '<div class="idc-main">';
    if ($photo !== '') {
        $html .= '<img class="idc-photo" src="' . $e($photo) . '" alt="">';
    } else {
        $html .= '<div class="idc-photo"></div>';
    }
    $html .= '<div class="idc-info"><div class="idc-name">' . $e($name) . '</div>';
    $html .= '<div class="idc-line">' . $e($meta) . '</div>';
    if ($call !== '') {
        $html .= '<div class="idc-line">' . $e($call) . '</div>';
    }
    $html .= '</div>';
    $html .= '<div class="idc-qr"><img src="' . $e($qr) . '" alt="QR"></div>';
    $html .= '</div>';
    $html .= '<div class="idc-foot"><span>' . $e($ay !== '' ? $ay : '') . '</span><span>Scan QR for full details</span></div>';
    $html .= '</article>';
    return $html;
}

/**
 * @param list<array<string, mixed>> $students
 */
function student_idcard_print_document(array $students, string $backUrl = ''): void
{
    $title = count($students) === 1
        ? ('ID card — ' . (function_exists('student_full_name') ? student_full_name($students[0]) : 'Student'))
        : ('ID cards (' . count($students) . ')');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>' . student_idcard_css() . '</style></head><body class="idc-print">';
    echo '<div class="idc-toolbar">';
    echo '<button type="button" class="btn" onclick="window.print()">Print ID card</button>';
    if ($backUrl !== '') {
        echo '<a class="btn btn2" href="' . htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') . '">Back</a>';
    }
    echo '<span class="idc-hint">CR80 size (85.6 × 54 mm) — same as a PVC ID-card printer. In print settings pick that paper size, 1 card per page, no margins.</span>';
    echo '</div><div class="idc-sheet">';
    foreach ($students as $s) {
        echo student_idcard_markup($s);
    }
    echo '</div></body></html>';
}

/**
 * A4 sheet of visit QRs (desk / parent meeting).
 *
 * @param list<array<string, mixed>> $students
 */
function student_idcard_qr_sheet(array $students, string $backUrl = '', string $heading = 'Visit QR'): void
{
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>
      @page { size: A4; margin: 12mm; }
      body { font-family: "Segoe UI", Arial, sans-serif; margin: 0; color: #0f2744; }
      .bar { padding: 10px 14px; display:flex; gap:8px; align-items:center; border-bottom:1px solid #dbe7fb; }
      .bar button, .bar a { background:#1d4ed8; color:#fff; border:0; padding:8px 14px; border-radius:8px; text-decoration:none; font:inherit; cursor:pointer; }
      .bar a.alt { background:#fff; color:#1e3a5f; border:1px solid #dbe7fb; }
      .hint { color:#64748b; font-size:.85rem; }
      .grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; padding:14px; }
      .cell { border:1px solid #dbe7fb; border-radius:12px; padding:10px; text-align:center; break-inside:avoid; }
      .cell img { width:120px; height:120px; }
      .nm { font-weight:800; margin-top:6px; font-size:.95rem; }
      .cl { color:#64748b; font-size:.8rem; }
      @media print { .bar { display:none !important; } .grid { padding:0; } }
    </style></head><body>';
    echo '<div class="bar"><button type="button" onclick="window.print()">Print QRs</button>';
    if ($backUrl !== '') {
        echo '<a class="alt" href="' . htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') . '">Back</a>';
    }
    echo '<span class="hint">Scan opens the child visit page (attendance, fees, homework).</span></div>';
    echo '<div class="grid">';
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    foreach ($students as $s) {
        $id = (int) ($s['id'] ?? 0);
        $name = function_exists('student_full_name') ? student_full_name($s) : trim((string) ($s['first_name'] ?? ''));
        $class = trim((string) ($s['class_name'] ?? ''));
        $scan = student_idcard_scan_url($id);
        $qr = student_idcard_qr_src($scan, 240);
        echo '<div class="cell"><img src="' . $e($qr) . '" alt="QR"><div class="nm">' . $e($name !== '' ? $name : ('#' . $id)) . '</div>';
        if ($class !== '') {
            echo '<div class="cl">' . $e($class) . '</div>';
        }
        echo '</div>';
    }
    echo '</div></body></html>';
}

/**
 * @return list<array<string, mixed>>
 */
function student_idcard_list_for_class(int $classId): array
{
    if ($classId <= 0 || !table_exists('students')) {
        return [];
    }
    $where = ['s.class_id = :cid'];
    $params = [':cid' => $classId];
    if (function_exists('column_exists') && column_exists('students', 'status')) {
        $where[] = "(s.status IS NULL OR s.status = '' OR LOWER(s.status) IN ('active','current'))";
    }
    if (function_exists('ay_apply_student_filter')) {
        ay_apply_student_filter($where, $params, 's');
    }
    $join = table_exists('classes') ? 'LEFT JOIN classes c ON c.id = s.class_id' : '';
    $classSel = table_exists('classes') ? ", COALESCE(c.name,'') AS class_name" : ", '' AS class_name";
    $userJoin = table_exists('users') ? 'LEFT JOIN users u ON u.id = s.parent_id' : '';
    $userSel = table_exists('users')
        ? ", COALESCE(u.name,'') AS parent_login_name, COALESCE(u.phone,'') AS parent_login_phone"
        : '';
    $rows = safe_db_get_all(
        'SELECT s.*' . $classSel . $userSel . '
         FROM students s ' . $join . ' ' . $userJoin . '
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY s.first_name ASC, s.last_name ASC',
        $params
    ) ?: [];
    if (function_exists('student_hydrate_row')) {
        foreach ($rows as $i => $r) {
            $rows[$i] = student_hydrate_row($r);
        }
    }
    return $rows;
}
