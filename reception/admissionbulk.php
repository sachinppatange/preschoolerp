<?php
/**
 * reception/admissionbulk.php
 * Bulk Student Admission with duplicate prevention.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
$DEBUG = panel_debug();

if (!function_exists('generate_form_no')) {
    function generate_form_no(): string { return sprintf('FORM-%s-%04d', date('Ymd'), random_int(1000,9999)); }
}

if (!table_exists('students') || !table_exists('users') || !table_exists('parents_children')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">Required tables missing (students, users, parents_children). कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

$schoolList = table_exists('schools') ? safe_db_get_all("SELECT id, name FROM schools ORDER BY name ASC") : [];
$classList = table_exists('classes') ? safe_db_get_all("SELECT id, name FROM classes ORDER BY name ASC") : [];
$parentList = safe_db_get_all("SELECT id, name, phone FROM users WHERE role = 'parent' ORDER BY name ASC");
$academicYears = ['2024-25','2025-26','2026-27'];

function default_bulk_row() {
    global $schoolList, $classList, $parentList, $academicYears;
    return [
        'school_id' => '',
        'class_id' => '',
        'academic_year' => $academicYears[0],
        'parent_id' => '',
        'stu_first'=>'','stu_middle'=>'','stu_last'=>'',
        'dob'=>'','total_fees'=>'',
    ];
}

$messages = [];
$errors = [];
$failed_rows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bulk = $_POST['bulk'] ?? [];
    $CSRF_post = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$CSRF_post)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $success_count = 0;
        $fail_count = 0;
        $pdo = pdo_connect();
        if (!($pdo instanceof \PDO)) {
            $errors[] = 'Database connection unavailable.';
        } else {
            try {
                $existingCols = array_column(safe_db_get_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'"), 'COLUMN_NAME');
                $now = date('Y-m-d H:i:s');
                foreach ($bulk as $idx=>$row) {
                    $rowErrors = [];
                    $row = array_map(function($v){return trim((string)$v);}, $row);
                    // Validate
                    if (($row['parent_id'] ?? '') === '') $rowErrors[] = 'Parent is required';
                    if (($row['stu_first'] ?? '') === '') $rowErrors[] = 'Student first name is required';
                    if (($row['dob'] ?? '') === '') $rowErrors[] = 'DOB required';
                    if (($row['academic_year'] ?? '') === '' || !in_array($row['academic_year'], $academicYears, true)) $rowErrors[] = 'Valid academic year required';
                    // Check duplicate:
                    $dup_chk = safe_db_get_one(
                        "SELECT id FROM students WHERE parent_id=:parent_id AND class_id=:class_id AND academic_year=:academic_year AND first_name=:first_name AND dob=:dob LIMIT 1",
                        [
                            ':parent_id'=>$row['parent_id'],
                            ':class_id'=>$row['class_id'],
                            ':academic_year'=>$row['academic_year'],
                            ':first_name'=>$row['stu_first'] . (isset($row['stu_middle']) && $row['stu_middle'] ? ' '.$row['stu_middle'] : ''),
                            ':dob'=>$row['dob']
                        ]
                    );
                    if ($dup_chk) $rowErrors[] = 'Duplicate student';
                    if (!empty($rowErrors)) {
                        $fail_count++; $failed_rows[$idx] = implode("; ", $rowErrors);
                        continue;
                    }
                    $dbData = [];
                    $dbData['school_id'] = $row['school_id'] !== '' ? $row['school_id'] : null;
                    $dbData['first_name'] = $row['stu_first'] . (isset($row['stu_middle']) && $row['stu_middle'] ? ' '.$row['stu_middle'] : '');
                    $dbData['middle_name'] = $row['stu_middle'] ?? '';
                    $dbData['last_name'] = $row['stu_last'] ?? '';
                    $dbData['dob'] = $row['dob'];
                    $dbData['class_id'] = $row['class_id'] !== '' ? $row['class_id'] : null;
                    $dbData['parent_id'] = $row['parent_id'] !== '' ? $row['parent_id'] : null;
                    $dbData['admission_date'] = date('Y-m-d');
                    $dbData['academic_year'] = $row['academic_year'];
                    $dbData['total_fees'] = $row['total_fees'] !== '' ? $row['total_fees'] : null;
                    $dbData['status'] = 'active';
                    $dbData['form_no'] = generate_form_no();
                    $dbData['created_at'] = $now;
                    $dbData['updated_at'] = $now;
                    $toInsert = [];
                    foreach ($dbData as $k=>$v) if (in_array($k, $existingCols)) $toInsert[$k] = $v;
                    $toInsert['extended_json'] = json_encode([
                        'academic_year'=>$row['academic_year'],
                        'student'=>[
                            'first'=>$row['stu_first'],
                            'middle'=>$row['stu_middle'],
                            'last'=>$row['stu_last'],
                            'dob'=>$row['dob'],
                        ],
                        'class_id'=>$row['class_id'],
                        'parent_id'=>$row['parent_id'],
                        'school_id'=>$row['school_id'],
                        'total_fees'=>$row['total_fees'],
                        'created_at'=>$now
                    ], JSON_UNESCAPED_UNICODE);

                    $colsSql = implode('`,`', array_keys($toInsert));
                    $placeholders = implode(',', array_map(function($c){ return ':' . $c; }, array_keys($toInsert)));
                    $sql = "INSERT INTO `students` (`{$colsSql}`) VALUES ({$placeholders})";
                    $stmt = $pdo->prepare($sql);

                    $bindParams = [];
                    foreach ($toInsert as $col => $val) $bindParams[':'.$col] = $val === '' ? null : $val;
                    try {
                        $stmt->execute($bindParams);
                        $success_count++;
                        $studentId = (int)$pdo->lastInsertId();
                        $mc = $pdo->prepare("SELECT id FROM parents_children WHERE parent_user_id = :p AND child_student_id = :c LIMIT 1");
                        $mc->execute([':p'=>$row['parent_id'], ':c'=>$studentId]);
                        if (!$mc->fetch(\PDO::FETCH_ASSOC)) {
                            $ins = $pdo->prepare("INSERT INTO parents_children (parent_user_id, child_student_id, relation, created_at) VALUES (:p,:c,:r,NOW())");
                            $ins->execute([':p'=>$row['parent_id'], ':c'=>$studentId, ':r'=>'guardian']);
                        }
                    } catch (Throwable $e) {
                        $fail_count++; $failed_rows[$idx] = $DEBUG ? $e->getMessage() : 'DB error';
                    }
                }
                if ($success_count > 0) $messages[] = "{$success_count} student(s) added.";
                if ($fail_count > 0) $errors[] = "{$fail_count} not added.";
            } catch (Throwable $e) {
                $errors[] = $DEBUG ? $e->getMessage() : 'Bulk insert error.';
            }
        }
    }
}

$uiBulkRows = $_POST['bulk'] ?? [default_bulk_row()];
$pageTitle = 'Bulk Student Admission';
require_once __DIR__ . '/../includes/header.php';
?>

  <?php foreach($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>
  <?php if (!empty($failed_rows)): ?><div class="alert alert-danger">Errors on rows:<br><?php foreach($failed_rows as $i=>$reason): ?>Row <?php echo ($i+1).' - '.e($reason); ?><br><?php endforeach;?></div><?php endif; ?>

  <form method="post" id="bulkAdmissionForm" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">

    <div class="card p-3 mb-3">
      <div class="table-responsive">
        <table class="table table-bordered table-striped table-bulk align-middle mb-0">
          <thead>
            <tr>
              <th>School</th>
              <th>Admission Class</th>
              <th>Academic Year</th>
              <th>Parent</th>
              <th>Name (First)</th>
              <th>Name (Middle)</th>
              <th>Name (Last)</th>
              <th>DOB</th>
              <th>Total Fees</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="bulkRows">
          <?php
          foreach ($uiBulkRows as $i => $row):
            $row = array_merge(default_bulk_row(), (array)$row);
          ?>
            <tr>
              <td>
                <select name="bulk[<?php echo $i; ?>][school_id]" class="form-select form-select-sm">
                  <option value="">-- Select --</option>
                  <?php foreach ($schoolList as $s): ?><option value="<?php echo (int)$s['id']; ?>"<?php if($row['school_id']==$s['id'])echo ' selected'; ?>><?php echo e($s['name']); ?></option><?php endforeach; ?>
                </select>
              </td>
              <td>
                <select name="bulk[<?php echo $i; ?>][class_id]" class="form-select form-select-sm">
                  <option value="">-- Select --</option>
                  <?php foreach ($classList as $c): ?><option value="<?php echo (int)$c['id']; ?>"<?php if($row['class_id']==$c['id'])echo ' selected'; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?>
                </select>
              </td>
              <td>
                <select name="bulk[<?php echo $i; ?>][academic_year]" class="form-select form-select-sm" required>
                  <option value="">-- Year --</option>
                  <?php foreach ($academicYears as $y): ?><option value="<?php echo e($y); ?>"<?php if($row['academic_year']===$y)echo ' selected';?>><?php echo e($y); ?></option><?php endforeach; ?>
                </select>
              </td>
              <td>
                <select name="bulk[<?php echo $i; ?>][parent_id]" class="form-select form-select-sm" required>
                  <option value="">-- Select --</option>
                  <?php foreach ($parentList as $pp): ?><option value="<?php echo (int)$pp['id']; ?>"<?php if($row['parent_id']==$pp['id'])echo ' selected';?>><?php echo e($pp['name']); ?><?php if(!empty($pp['phone'])) echo ' ('.e($pp['phone']).')';?></option><?php endforeach; ?>
                </select>
              </td>
              <td><input type="text" name="bulk[<?php echo $i; ?>][stu_first]" class="form-control form-control-sm" value="<?php echo e($row['stu_first']); ?>" required></td>
              <td><input type="text" name="bulk[<?php echo $i; ?>][stu_middle]" class="form-control form-control-sm" value="<?php echo e($row['stu_middle']); ?>"></td>
              <td><input type="text" name="bulk[<?php echo $i; ?>][stu_last]" class="form-control form-control-sm" value="<?php echo e($row['stu_last']); ?>"></td>
              <td><input type="date" name="bulk[<?php echo $i; ?>][dob]" class="form-control form-control-sm" value="<?php echo e($row['dob']); ?>" required></td>
              <td><input type="text" name="bulk[<?php echo $i; ?>][total_fees]" class="form-control form-control-sm" value="<?php echo e($row['total_fees']); ?>"></td>
              <td>
                <button type="button" class="btn btn-danger btn-sm remove-row-btn" onclick="removeRow(this)">×</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="py-2">
        <button type="button" class="btn btn-secondary" onclick="addNewRow()">Add Student</button>
      </div>
      <div>
        <button type="submit" class="btn btn-primary">Save All</button>
      </div>
    </div>
  </form>
</div>

<script>
function addNewRow() {
    var $tbody = document.getElementById('bulkRows');
    var rowCount = $tbody.rows.length;
    var newRow = $tbody.rows[0].cloneNode(true);
    // reset values
    ['input', 'select'].forEach(function(tag) {
      Array.from(newRow.getElementsByTagName(tag)).forEach(function(el){
        var name = el.getAttribute('name').replace(/\d+/, rowCount);
        el.setAttribute('name', name);
        if (el.tagName === 'SELECT' || el.type === 'date') el.selectedIndex = 0;
        else el.value = '';
      });
    });
    $tbody.appendChild(newRow);
}
function removeRow(btn) {
    var tbody = document.getElementById('bulkRows');
    if (tbody.rows.length > 1) btn.closest('tr').remove();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php';?>
