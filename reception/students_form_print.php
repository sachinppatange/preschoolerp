<?php
/**
 * Print / Save as PDF — full admission form from students table columns.
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
    "SELECT st.*, COALESCE(c.name,'') AS class_name, COALESCE(u.name,'') AS parent_login_name, COALESCE(u.phone,'') AS parent_login_phone
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

$fullName = function_exists('student_full_name') ? student_full_name($student) : trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
$appName = defined('APP_NAME') ? APP_NAME : 'Preschool';
$photo = function_exists('resolve_image_url') ? resolve_image_url((string) ($student['photo_path'] ?? ''), '') : (string) ($student['photo_path'] ?? '');
$dash = static function ($v): string {
    $s = trim((string) ($v ?? ''));
    return $s === '' ? '—' : $s;
};

$row = static function (string $label, $value) use ($dash): void {
    echo '<tr><th>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th><td>' . htmlspecialchars($dash($value), ENT_QUOTES, 'UTF-8') . '</td></tr>';
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admission Form — <?php echo htmlspecialchars($fullName !== '' ? $fullName : 'Student #' . $id, ENT_QUOTES, 'UTF-8'); ?></title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: Georgia, "Times New Roman", serif; color: #222; margin: 0; background: #f4f4f4; }
    .toolbar { background: #fff; padding: 12px 16px; display: flex; gap: 8px; position: sticky; top: 0; z-index: 2; border-bottom: 1px solid #ddd; }
    .sheet { width: 210mm; max-width: 100%; margin: 16px auto; background: #fff; padding: 16mm; box-shadow: 0 8px 24px rgba(0,0,0,.08); }
    h1 { font-size: 20px; margin: 0 0 4px; }
    h2 { font-size: 13px; letter-spacing: .04em; text-transform: uppercase; background: #eef3ea; padding: 6px 8px; margin: 16px 0 0; border: 1px solid #cfd8c8; }
    .muted { color: #666; font-size: 12px; }
    .head { display: flex; justify-content: space-between; gap: 16px; border-bottom: 3px solid #2d6a3e; padding-bottom: 12px; }
    .photo { width: 28mm; height: 32mm; object-fit: cover; border: 1px solid #ccc; background: #f7f7f7; }
    table.kv { width: 100%; border-collapse: collapse; font-size: 13px; }
    table.kv th { width: 34%; text-align: left; font-weight: 600; padding: 5px 8px; border: 1px solid #ddd; background: #fafafa; vertical-align: top; }
    table.kv td { padding: 5px 8px; border: 1px solid #ddd; vertical-align: top; }
    .btn { border: 1px solid #2d6a3e; background: #2d6a3e; color: #fff; padding: 8px 14px; border-radius: 6px; cursor: pointer; font: inherit; }
    .btn-outline { background: #fff; color: #222; border-color: #ccc; }
    @page { size: A4; margin: 12mm; }
    @media print {
      body { background: #fff; }
      .toolbar { display: none !important; }
      .sheet { margin: 0; width: auto; box-shadow: none; padding: 0; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <button class="btn" type="button" onclick="window.print()">Download PDF / Print</button>
    <button class="btn btn-outline" type="button" onclick="history.back()">Back</button>
  </div>
  <div class="sheet">
    <div class="head">
      <div>
        <h1><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></h1>
        <div><strong>Student Admission Form</strong></div>
        <div class="muted">Form No: <?php echo htmlspecialchars($dash($student['form_no'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
          · AY <?php echo htmlspecialchars($dash($student['academic_year'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <?php if ($photo !== ''): ?>
        <img class="photo" src="<?php echo htmlspecialchars($photo, ENT_QUOTES, 'UTF-8'); ?>" alt="Photo">
      <?php endif; ?>
    </div>

    <h2>1. Admission</h2>
    <table class="kv">
      <?php
      $row('Form No', $student['form_no'] ?? '');
      $row('Academic year', $student['academic_year'] ?? '');
      $row('Class', $student['class_name'] ?? '');
      $row('Location', $student['location'] ?? '');
      $row('Admission date', $student['admission_date'] ?? '');
      $row('Status', $student['status'] ?? '');
      ?>
    </table>

    <h2>2. Student</h2>
    <table class="kv">
      <?php
      $row('First name', $student['first_name'] ?? '');
      $row('Middle name', $student['middle_name'] ?? '');
      $row('Last name', $student['last_name'] ?? '');
      $row('Date of birth', $student['dob'] ?? '');
      $row('Gender', $student['gender'] ?? '');
      $row('Place of birth', $student['place_of_birth'] ?? '');
      $row('Nationality', $student['nationality'] ?? '');
      $row('Caste', $student['caste'] ?? '');
      $row('Languages', $student['languages'] ?? '');
      ?>
    </table>

    <h2>3. Address</h2>
    <table class="kv">
      <?php
      $row('Address', $student['address'] ?? '');
      $row('City', $student['city'] ?? '');
      $row('State', $student['state'] ?? '');
      $row('Country', $student['country'] ?? '');
      $row('PIN', $student['pin'] ?? '');
      ?>
    </table>

    <h2>4. Father</h2>
    <table class="kv">
      <?php
      $row('Name', trim(($student['father_first'] ?? '') . ' ' . ($student['father_middle'] ?? '') . ' ' . ($student['father_last'] ?? '')));
      $row('Phone', $student['father_phone'] ?? '');
      $row('Email', $student['father_email'] ?? '');
      $row('Education', $student['father_edu'] ?? '');
      $row('Profession', $student['father_prof'] ?? '');
      $row('Designation', $student['father_designation'] ?? '');
      ?>
    </table>

    <h2>5. Mother</h2>
    <table class="kv">
      <?php
      $row('Name', trim(($student['mother_first'] ?? '') . ' ' . ($student['mother_middle'] ?? '') . ' ' . ($student['mother_last'] ?? '')));
      $row('Phone', $student['mother_phone'] ?? '');
      $row('Email', $student['mother_email'] ?? '');
      $row('Education', $student['mother_edu'] ?? '');
      $row('Profession', $student['mother_prof'] ?? '');
      $row('Designation', $student['mother_designation'] ?? '');
      ?>
    </table>

    <h2>6. Guardian / emergency</h2>
    <table class="kv">
      <?php
      $row('Name', $student['guardian_name'] ?? '');
      $row('Relation', $student['guardian_relation'] ?? '');
      $row('Phone', $student['guardian_phone'] ?? '');
      $row('Email', $student['guardian_email'] ?? '');
      ?>
    </table>

    <h2>7. Parent Portal login</h2>
    <table class="kv">
      <?php
      $row('Login name', $student['parent_login_name'] ?? '');
      $row('Mobile', $student['parent_login_phone'] ?? '');
      ?>
    </table>

    <h2>8. Education & health</h2>
    <table class="kv">
      <?php
      $row('Previous school', $student['previous_school'] ?? '');
      $row('Allergies', $student['allergies'] ?? '');
      $row('Health conditions', $student['health_conditions'] ?? '');
      $row('Current medications', $student['current_medications'] ?? '');
      $row('Immunization', $student['immunization_records'] ?? '');
      $row('Sibling 1', $student['sibling1'] ?? '');
      $row('Sibling 2', $student['sibling2'] ?? '');
      $row('Additional info', $student['additional_info'] ?? '');
      ?>
    </table>

    <h2>9. Fees / office</h2>
    <table class="kv">
      <?php
      $row('Total fees', $student['total_fees'] ?? '');
      $row('Installment 1', $student['installment1'] ?? '');
      $row('Installment 2', $student['installment2'] ?? '');
      $row('Installment 3', $student['installment3'] ?? '');
      $row('Remark', $student['remark'] ?? '');
      $row('Stamp', $student['stamp'] ?? '');
      $row('Parent signature', $student['parent_signature'] ?? '');
      ?>
    </table>

    <p class="muted" style="margin-top:18px;">Generated <?php echo htmlspecialchars(date('d M Y, h:i A'), ENT_QUOTES, 'UTF-8'); ?>. Use Print → Save as PDF.</p>
  </div>
  <script>
    if (new URLSearchParams(location.search).get('autoprint') === '1') {
      window.addEventListener('load', function () { window.print(); });
    }
  </script>
</body>
</html>
