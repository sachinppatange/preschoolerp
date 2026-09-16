<?php
/**
 * Printable Student Admission Form (Pioneer Play School layout) + payment records.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid student id.';
    exit;
}

$student = safe_db_get_one(
    "SELECT st.*, COALESCE(c.name,'') AS class_name,
            COALESCE(u.name,'') AS parent_login_name, COALESCE(u.phone,'') AS parent_login_phone
     FROM students st
     LEFT JOIN classes c ON c.id = st.class_id
     LEFT JOIN users u ON u.id = st.parent_id
     WHERE st.id = :id LIMIT 1",
    [':id' => $id]
);
if (!$student) {
    http_response_code(404);
    echo 'Student not found.';
    exit;
}
if (function_exists('student_hydrate_row')) {
    $student = student_hydrate_row($student);
}

$school = [];
if (table_exists('schools')) {
    $school = safe_db_get_one('SELECT * FROM schools ORDER BY id ASC LIMIT 1') ?: [];
}

$fullName = function_exists('student_full_name') ? student_full_name($student) : trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
$photo = function_exists('student_photo_url') ? student_photo_url((string) ($student['photo_path'] ?? '')) : '';
$logo = function_exists('resolve_image_url')
    ? resolve_image_url((string) ($school['logo_path'] ?? ''), function_exists('asset_url') ? asset_url('assets/images/logo.png') : '')
    : '';
$fees = function_exists('student_fee_summary') ? student_fee_summary($student) : ['total' => 0, 'paid' => 0, 'remaining' => 0];
$payments = function_exists('student_fee_payments') ? student_fee_payments($id) : [];
$ayLabel = function_exists('ay_display_long') ? ay_display_long((string) ($student['academic_year'] ?? '')) : (string) ($student['academic_year'] ?? '');

$dash = static function ($v): string {
    $s = trim(strip_tags((string) ($v ?? '')));
    return $s === '' ? '' : $s;
};
$cls = strtolower((string) ($student['class_name'] ?? ''));
$mark = static function (string $hay, array $needles) use ($cls): string {
    foreach ($needles as $n) {
        if ($n !== '' && str_contains($cls, $n)) {
            return 'checked';
        }
    }
    return '';
};
$g = strtolower((string) ($student['gender'] ?? ''));
$phone = $dash($school['contact_phone'] ?? '');
$email = $dash($school['contact_email'] ?? '');
$addr = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($school['address'] ?? ''))));
$money = static function (float $n): string {
    return '₹ ' . number_format($n, 2);
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admission Form — <?php echo htmlspecialchars($fullName !== '' ? $fullName : ('#' . $id), ENT_QUOTES, 'UTF-8'); ?></title>
  <style>
    :root { --navy:#143a7a; --line:#9bb4d4; --ink:#1a1a1a; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #eceff4; color: var(--ink); font-family: "Segoe UI", Arial, sans-serif; }
    .toolbar { position: sticky; top: 0; background: #fff; padding: 10px 14px; display: flex; gap: 8px; border-bottom: 1px solid #ddd; z-index: 5; }
    .btn { background: var(--navy); color: #fff; border: 0; padding: 8px 14px; border-radius: 6px; cursor: pointer; font: inherit; }
    .btn.ghost { background: #fff; color: #222; border: 1px solid #ccc; }
    .page { width: 210mm; min-height: 297mm; margin: 12px auto; background: #fff; padding: 10mm 12mm 18mm; position: relative; box-shadow: 0 8px 20px rgba(0,0,0,.08); }
    .topbar { height: 8px; background: linear-gradient(90deg,#ffd54a 0 18%, #5ec8f0 18% 82%, #ffd54a 82% 100%); margin: -10mm -12mm 8px; }
    .meta { display: flex; justify-content: space-between; font-size: 12px; }
    .brand { text-align: center; margin-top: -8px; }
    .brand img.logo { height: 58px; }
    .brand .tag { font-size: 12px; color: var(--navy); font-weight: 700; }
    .brand .addr { font-size: 11px; max-width: 150mm; margin: 2px auto; }
    .title { display: inline-block; background: var(--navy); color: #fff; border-radius: 18px; padding: 4px 18px; font-weight: 800; margin: 6px 0 10px; }
    .photo { position: absolute; right: 12mm; top: 28mm; width: 28mm; height: 34mm; border: 1px dashed #888; overflow: hidden; background: #fafafa; text-align: center; font-size: 9px; color: #888; }
    .photo img { width: 100%; height: 100%; object-fit: cover; }
    .row { display: flex; gap: 10px; align-items: flex-end; margin: 5px 0; font-size: 13px; }
    .lab { font-weight: 700; white-space: nowrap; }
    .line { flex: 1; border-bottom: 1px solid #333; min-height: 16px; padding: 0 4px; }
    .sec { font-weight: 800; margin: 10px 0 4px; font-size: 14px; }
    .green { color: #2e9a3a; } .pink { color: #d23b7a; } .blue { color: #2a6fbd; } .teal { color: #1aa39a; }
    .checks { display: flex; gap: 14px; flex-wrap: wrap; font-size: 13px; margin: 4px 0 8px; font-weight: 700; }
    .box { display: inline-block; width: 12px; height: 12px; border: 1.5px solid #222; margin-right: 4px; vertical-align: -1px; text-align: center; line-height: 10px; font-size: 10px; }
    .box.on { background: var(--navy); color: #fff; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 14px; }
    table.pay { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 6px; }
    table.pay th, table.pay td { border: 1px solid #c5d0e0; padding: 5px 6px; }
    table.pay th { background: #eaf1fb; text-align: left; }
    .sum { display: flex; gap: 10px; margin: 8px 0; }
    .sum div { flex: 1; border: 1px solid #c5d0e0; border-radius: 8px; padding: 8px; text-align: center; }
    .sum b { display: block; font-size: 16px; }
    .foot { position: absolute; left: 0; right: 0; bottom: 0; height: 28mm; background: linear-gradient(#fff 0 8mm, #eaf8c8 8mm 100%); overflow: hidden; }
    .rainbow { position: absolute; left: -20px; bottom: -30px; width: 90mm; height: 50mm; border-radius: 50%; border: 10px solid #ff5d7a; border-right-color: #ffd54a; border-bottom-color: #5ec8f0; border-left-color: #7be07b; opacity: .85; }
    .small { font-size: 11px; color: #555; }
    @page { size: A4; margin: 8mm; }
    @media print {
      body { background: #fff; }
      .toolbar { display: none !important; }
      .page { margin: 0; box-shadow: none; width: auto; min-height: auto; page-break-after: always; }
      .page:last-child { page-break-after: auto; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <button class="btn" type="button" onclick="window.print()">Download PDF / Print</button>
    <button class="btn ghost" type="button" onclick="history.back()">Back</button>
  </div>

  <section class="page">
    <div class="topbar"></div>
    <div class="meta">
      <div>Location: <strong><?php echo htmlspecialchars($dash($student['location'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
      <div>Form NO.: <strong><?php echo htmlspecialchars($dash($student['form_no'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
    </div>
    <div class="brand">
      <?php if ($logo): ?><img class="logo" src="<?php echo htmlspecialchars($logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo"><?php endif; ?>
      <div class="tag"><?php echo htmlspecialchars((string) ($school['tagline'] ?? 'A Unit of Garje Foundation'), ENT_QUOTES, 'UTF-8'); ?></div>
      <div class="addr"><?php echo htmlspecialchars($addr !== '' ? $addr : 'MIDC, Barshi Road, Latur', ENT_QUOTES, 'UTF-8'); ?></div>
      <div class="addr"><strong>Call :</strong> <?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>
        &nbsp; <strong>Email :</strong> <?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></div>
      <div class="title">Student Admission Form</div>
    </div>
    <div class="photo">
      <?php if ($photo): ?>
        <img src="<?php echo htmlspecialchars($photo, ENT_QUOTES, 'UTF-8'); ?>" alt="Photo">
      <?php else: ?>
        <div style="padding:18px 6px">Attach a recent passport size color photograph</div>
      <?php endif; ?>
    </div>

    <div class="row"><span class="lab">Admission Seeking In :</span>
      <div class="checks">
        <span><span class="box <?php echo $mark($cls, ['play']) ? 'on' : ''; ?>"><?php echo $mark($cls, ['play']) ? '✓' : ''; ?></span> Play Group</span>
        <span><span class="box <?php echo $mark($cls, ['nurs']) ? 'on' : ''; ?>"><?php echo $mark($cls, ['nurs']) ? '✓' : ''; ?></span> Nursery</span>
        <span><span class="box <?php echo $mark($cls, ['lkg', 'l.k.g', 'l k g']) ? 'on' : ''; ?>"><?php echo $mark($cls, ['lkg', 'l.k.g', 'l k g']) ? '✓' : ''; ?></span> L.K.G.</span>
        <span><span class="box <?php echo $mark($cls, ['ukg', 'u.k.g', 'u k g']) ? 'on' : ''; ?>"><?php echo $mark($cls, ['ukg', 'u.k.g', 'u k g']) ? '✓' : ''; ?></span> U.K.G.</span>
      </div>
    </div>
    <div class="small"><?php echo htmlspecialchars($ayLabel, ENT_QUOTES, 'UTF-8'); ?> · Class: <?php echo htmlspecialchars($dash($student['class_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>

    <div class="sec green">Student's Personal Details :</div>
    <div class="row"><span class="lab">Student's Name:</span>
      <span class="line"><?php echo htmlspecialchars($dash($student['first_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="line"><?php echo htmlspecialchars($dash($student['middle_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="line"><?php echo htmlspecialchars($dash($student['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="row"><span class="lab">Date of Birth:</span><span class="line"><?php echo htmlspecialchars($dash($student['dob'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="lab">Gender :</span>
      <span><span class="box <?php echo $g === 'male' ? 'on' : ''; ?>"><?php echo $g === 'male' ? '✓' : ''; ?></span> Male</span>
      <span><span class="box <?php echo $g === 'female' ? 'on' : ''; ?>"><?php echo $g === 'female' ? '✓' : ''; ?></span> Female</span>
    </div>
    <div class="row"><span class="lab">Place of Birth:</span><span class="line"><?php echo htmlspecialchars($dash($student['place_of_birth'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="lab">Nationality :</span><span class="line"><?php echo htmlspecialchars($dash($student['nationality'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="row"><span class="lab">Caste categories :</span><span class="line"><?php echo htmlspecialchars($dash($student['caste'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="lab">Languages Known :</span><span class="line"><?php echo htmlspecialchars($dash($student['languages'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>

    <div class="sec pink">Residential Address &amp; Family information</div>
    <div class="row"><span class="lab">Address :</span><span class="line"><?php echo htmlspecialchars($dash($student['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="row">
      <span class="line"><?php echo htmlspecialchars($dash($student['city'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="line"><?php echo htmlspecialchars($dash($student['state'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="line"><?php echo htmlspecialchars($dash($student['country'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="line"><?php echo htmlspecialchars($dash($student['pin'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
    </div>

    <div class="sec blue">Father :</div>
    <div class="row"><span class="lab">Full Name :</span>
      <span class="line"><?php echo htmlspecialchars($dash($student['father_first'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="line"><?php echo htmlspecialchars($dash($student['father_middle'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="line"><?php echo htmlspecialchars($dash($student['father_last'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="grid2">
      <div class="row"><span class="lab">E-mail :</span><span class="line"><?php echo htmlspecialchars($dash($student['father_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="row"><span class="lab">Educational Qualification :</span><span class="line"><?php echo htmlspecialchars($dash($student['father_edu'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="row"><span class="lab">Profession :</span><span class="line"><?php echo htmlspecialchars($dash($student['father_prof'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="row"><span class="lab">Designation :</span><span class="line"><?php echo htmlspecialchars($dash($student['father_designation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="row"><span class="lab">Phone :</span><span class="line"><?php echo htmlspecialchars($dash($student['father_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    </div>

    <div class="sec teal">Mother :</div>
    <div class="row"><span class="lab">Full Name :</span>
      <span class="line"><?php echo htmlspecialchars($dash($student['mother_first'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="line"><?php echo htmlspecialchars($dash($student['mother_middle'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="line"><?php echo htmlspecialchars($dash($student['mother_last'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="grid2">
      <div class="row"><span class="lab">E-mail :</span><span class="line"><?php echo htmlspecialchars($dash($student['mother_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="row"><span class="lab">Educational Qualification :</span><span class="line"><?php echo htmlspecialchars($dash($student['mother_edu'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="row"><span class="lab">Profession :</span><span class="line"><?php echo htmlspecialchars($dash($student['mother_prof'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="row"><span class="lab">Designation :</span><span class="line"><?php echo htmlspecialchars($dash($student['mother_designation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="row"><span class="lab">Phone :</span><span class="line"><?php echo htmlspecialchars($dash($student['mother_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    </div>

    <div class="sec pink">Guardian (Emergency numbers)</div>
    <div class="row"><span class="lab">Full Name :</span><span class="line"><?php echo htmlspecialchars($dash($student['guardian_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="lab">E-mail :</span><span class="line"><?php echo htmlspecialchars($dash($student['guardian_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="row"><span class="lab">Relation with student :</span><span class="line"><?php echo htmlspecialchars($dash($student['guardian_relation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="lab">Phone :</span><span class="line"><?php echo htmlspecialchars($dash($student['guardian_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>

    <div class="sec green">Educational Background</div>
    <div class="row"><span class="lab">Previous School :</span><span class="line"><?php echo htmlspecialchars($dash($student['previous_school'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="foot"><div class="rainbow"></div></div>
  </section>

  <section class="page">
    <div class="topbar"></div>
    <div class="sec green">Health &amp; other details</div>
    <div class="row"><span class="lab">Allergies :</span><span class="line"><?php echo htmlspecialchars($dash($student['allergies'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="row"><span class="lab">Health conditions :</span><span class="line"><?php echo htmlspecialchars($dash($student['health_conditions'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="row"><span class="lab">Current medications :</span><span class="line"><?php echo htmlspecialchars($dash($student['current_medications'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="row"><span class="lab">Immunization :</span><span class="line"><?php echo htmlspecialchars($dash($student['immunization_records'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="row"><span class="lab">Sibling 1 :</span><span class="line"><?php echo htmlspecialchars($dash($student['sibling1'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="lab">Sibling 2 :</span><span class="line"><?php echo htmlspecialchars($dash($student['sibling2'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="row"><span class="lab">Additional info :</span><span class="line"><?php echo htmlspecialchars($dash($student['additional_info'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>

    <div class="sec pink">Parent Portal login</div>
    <div class="row"><span class="lab">Login name :</span><span class="line"><?php echo htmlspecialchars($dash($student['parent_login_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="lab">Mobile :</span><span class="line"><?php echo htmlspecialchars($dash($student['parent_login_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>

    <div class="sec blue">Fee payment record</div>
    <div class="small">Same summary as Collect Fees — Total fee, paid, remaining, and every receipt.</div>
    <div class="sum">
      <div>Total Fee<b><?php echo htmlspecialchars($money((float) $fees['total']), ENT_QUOTES, 'UTF-8'); ?></b></div>
      <div>Paid<b><?php echo htmlspecialchars($money((float) $fees['paid']), ENT_QUOTES, 'UTF-8'); ?></b></div>
      <div>Remaining / Pending<b><?php echo htmlspecialchars($money((float) $fees['remaining']), ENT_QUOTES, 'UTF-8'); ?></b></div>
    </div>
    <table class="pay">
      <thead>
        <tr>
          <th>Date</th><th>Receipt</th><th>Type</th><th>Amount</th><th>Note</th><th>Collected by</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$payments): ?>
        <tr><td colspan="6">No payment recorded yet.</td></tr>
      <?php else: foreach ($payments as $p): ?>
        <tr>
          <td><?php echo htmlspecialchars((string) ($p['collected_at'] ?? $p['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string) ($p['receipt_display'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string) ($p['payment_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars($money((float) ($p['paid_amount'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string) ($p['payment_note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string) ($p['collector_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>

    <div class="sec teal">Office use</div>
    <div class="row"><span class="lab">Total fees :</span><span class="line"><?php echo htmlspecialchars($dash((string) ($student['total_fees'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="lab">Remark :</span><span class="line"><?php echo htmlspecialchars($dash($student['remark'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="row"><span class="lab">Installment 1 :</span><span class="line"><?php echo htmlspecialchars($dash((string) ($student['installment1'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="lab">2 :</span><span class="line"><?php echo htmlspecialchars($dash((string) ($student['installment2'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="lab">3 :</span><span class="line"><?php echo htmlspecialchars($dash((string) ($student['installment3'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="row" style="margin-top:28px"><span class="lab">Parent signature :</span><span class="line"><?php echo htmlspecialchars($dash($student['parent_signature'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="lab">Office stamp :</span><span class="line"><?php echo htmlspecialchars($dash($student['stamp'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <p class="small" style="margin-top:18px">Generated <?php echo htmlspecialchars(date('d M Y, h:i A'), ENT_QUOTES, 'UTF-8'); ?>. Use Print → Save as PDF.</p>
  </section>
</body>
</html>
