<?php
/**
 * parent/children.php
 *
 * Parent -> Linked children listing (parents_children -> students).
 *
 * - Uses parents_children (id, parent_user_id, child_student_id) to determine linked children.
 * - Displays columns from students table: ID, Child (photo + name), Class / Form No, Admission, Actions.
 * - Actions: View (modal shows full student record using the schema you supplied), Attendance, Fees.
 * - Export CSV of linked children.
 * - Pagination.
 *
 * Notes:
 * - This version intentionally avoids referring to any non-existing columns (e.g. roll_no).
 * - It assumes the students table contains the fields you listed (form_no, first_name, middle_name, last_name, etc.).
 *
 * Place at: /pioneerplayschool01/parent/children.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
$DEBUG = panel_debug();

/* ---------- Require parent login ---------- */
/* ---------- Parent identity ---------- */
$parent = auth_user() ?? [];
$parentId = panel_parent_context_id();

/* ---------- Load mappings from parents_children (id, parent_user_id, child_student_id) ---------- */
$childMappings = [];
$childIds = [];
if (table_exists('parents_children')) {
    $childMappings = safe_db_get_all("SELECT id, parent_user_id, child_student_id FROM parents_children WHERE parent_user_id = :pid ORDER BY id DESC", [':pid'=>$parentId]);
    foreach ($childMappings as $m) $childIds[] = (int)$m['child_student_id'];
}

/* ---------- Actions: view (modal) and export ---------- */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

/* VIEW modal: full student record using provided students schema */
if ($action === 'view' && !empty($_GET['id'])) {
    $sid = (int)$_GET['id'];
    if ($sid <= 0) { echo '<div class="p-3 text-danger">Invalid student id.</div>'; exit; }
    if (!in_array($sid, $childIds, true)) { echo '<div class="p-3 text-muted">This child is not linked to your account.</div>'; exit; }
    if (!table_exists('students')) { echo '<div class="p-3 text-muted">Students table missing.</div>'; exit; }

    $student = safe_db_get_one(
        "SELECT
            id, school_id, first_name, middle_name, last_name, dob, class_id, parent_id, photo_path, admission_date,
            status, created_at, updated_at, form_no, location, place_of_birth, nationality, caste, languages,
            address, city, state, country, pin,
            father_first, father_middle, father_last, father_email, father_edu, father_prof, father_designation, father_phone,
            mother_first, mother_middle, mother_last, mother_email, mother_edu, mother_prof, mother_designation, mother_phone,
            guardian_name, guardian_email, guardian_relation, guardian_phone,
            previous_school, allergies, health_conditions, current_medications, immunization_records,
            sibling1, sibling2, additional_info, parent_signature, total_fees, installment1, installment2, installment3, remark, extended_json
         FROM students WHERE id = :id LIMIT 1",
        [':id'=>$sid]
    );

    if (!$student) { echo '<div class="p-3 text-muted">Student not found.</div>'; exit; }

    // optional class info
    $classInfo = null;
    if (!empty($student['class_id']) && table_exists('classes')) {
        $classInfo = safe_db_get_one("SELECT id, name, short_name, section FROM classes WHERE id = :cid LIMIT 1", [':cid'=>$student['class_id']]);
    }

    // render detailed view
    echo '<div class="p-3">';
    echo '<div class="d-flex gap-3 mb-3">';
    if (!empty($student['photo_path'])) {
        echo '<img src="'.e(function_exists('student_photo_url') ? student_photo_url((string) ($student['photo_path'] ?? '')) : (string) ($student['photo_path'] ?? '')).'" alt="" style="width:140px;height:140px;object-fit:cover;border-radius:6px">';
    } else {
        echo '<div style="width:140px;height:140px;background:#f1f1f1;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#777">No Photo</div>';
    }
    echo '<div>';
    echo '<h4 class="mb-1">'.e(trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? ''))).'</h4>';
    echo '<div class="text-muted mb-1">ID: '.(int)$student['id'].' • Form: '.e($student['form_no'] ?? '—').'</div>';
    echo '<div class="text-muted">'.e($classInfo ? (trim(($classInfo['short_name'] ?? '') . ' ' . ($classInfo['name'] ?? '')) . (!empty($classInfo['section']) ? ' • Sec: '.$classInfo['section'] : '')) : ($student['class_id'] ?? '—')).'</div>';
    echo '<div class="text-muted">Admission: '.e(substr((string)($student['admission_date'] ?? ''),0,10) ?: '—').'</div>';
    echo '</div></div>';

    echo '<div class="row">';
    echo '<div class="col-md-4"><strong>DOB</strong><div class="small-muted">'.e(substr((string)$student['dob'] ?? '',0,10) ?: '—').'</div></div>';
    echo '<div class="col-md-4"><strong>Nationality</strong><div class="small-muted">'.e($student['nationality'] ?? '—').'</div></div>';
    echo '<div class="col-md-4"><strong>Caste</strong><div class="small-muted">'.e($student['caste'] ?? '—').'</div></div>';

    echo '<div class="col-12 mt-3"><h6>Contact & Address</h6></div>';
    $addr = trim(implode(', ', array_filter([$student['address'] ?? '', $student['city'] ?? '', $student['state'] ?? '', $student['pin'] ?? ''])));
    echo '<div class="col-12"><div class="small-muted">'.($addr ?: '—').'</div></div>';

    echo '<div class="col-12 mt-3"><h6>Parents / Guardian</h6></div>';
    echo '<div class="col-md-6"><strong>Father</strong><div class="small-muted">'.e(trim(($student['father_first'] ?? '') . ' ' . ($student['father_middle'] ?? '') . ' ' . ($student['father_last'] ?? ''))).' • '.e($student['father_phone'] ?? '—').'</div></div>';
    echo '<div class="col-md-6"><strong>Mother</strong><div class="small-muted">'.e(trim(($student['mother_first'] ?? '') . ' ' . ($student['mother_middle'] ?? '') . ' ' . ($student['mother_last'] ?? ''))).' • '.e($student['mother_phone'] ?? '—').'</div></div>';
    echo '<div class="col-md-6 mt-2"><strong>Guardian</strong><div class="small-muted">'.e($student['guardian_name'] ?? '—').' • '.e($student['guardian_relation'] ?? '—').' • '.e($student['guardian_phone'] ?? '—').'</div></div>';

    echo '<div class="col-12 mt-3"><h6>Medical</h6></div>';
    echo '<div class="col-md-6"><strong>Allergies</strong><div class="small-muted">'.e($student['allergies'] ?? '—').'</div></div>';
    echo '<div class="col-md-6"><strong>Health conditions</strong><div class="small-muted">'.e($student['health_conditions'] ?? '—').'</div></div>';

    echo '<div class="col-12 mt-3"><h6>Fees</h6></div>';
    echo '<div class="col-md-4"><strong>Total Fees</strong><div class="small-muted">'.(isset($student['total_fees']) ? e((string)$student['total_fees']) : '—').'</div></div>';
    echo '<div class="col-md-8"><strong>Installments</strong><div class="small-muted">'.e($student['installment1'] ?? '—').' / '.e($student['installment2'] ?? '—').' / '.e($student['installment3'] ?? '—').'</div></div>';

    echo '<div class="col-12 mt-3"><h6>Misc</h6></div>';
    echo '<div class="col-12"><strong>Previous School</strong><div class="small-muted">'.e($student['previous_school'] ?? '—').'</div></div>';
    echo '<div class="col-12 mt-2"><strong>Remark</strong><div class="small-muted">'.nl2br(e($student['remark'] ?? '—')).'</div></div>';

    if (!empty($student['extended_json'])) {
        $js = @json_decode($student['extended_json'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($js)) {
            echo '<div class="col-12 mt-3"><h6>Extended Info</h6><pre style="max-height:260px;overflow:auto;background:#f8f9fa;padding:8px;border-radius:4px">'.e(json_encode($js, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre></div>';
        }
    }

    echo '</div>'; // row
    echo '</div>'; // p-3
    exit;
}

/* ---------- Export CSV ---------- */
if ($action === 'export') {
    $rows = [];
    if (!empty($childIds) && table_exists('students')) {
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        // fetch only the columns required for CSV
        $students = safe_db_get_all("SELECT id, school_id, first_name, middle_name, last_name, class_id, form_no, admission_date FROM students WHERE id IN ($ph)", $childIds);
        $byId = []; foreach ($students as $s) $byId[(int)$s['id']] = $s;
        foreach ($childIds as $cid) {
            if (isset($byId[$cid])) $rows[] = $byId[$cid];
            else $rows[] = ['id'=>$cid,'school_id'=>'','first_name'=>'','middle_name'=>'','last_name'=>'','class_id'=>'','form_no'=>'','admission_date'=>''];
        }
    } else {
        foreach ($childIds as $cid) $rows[] = ['id'=>$cid,'school_id'=>'','first_name'=>'','middle_name'=>'','last_name'=>'','class_id'=>'','form_no'=>'','admission_date'=>''];
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=children_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['id','school_id','first_name','middle_name','last_name','class_id','form_no','admission_date']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $r['first_name'] ?? '',
            $r['middle_name'] ?? '',
            $r['last_name'] ?? '',
            $r['class_id'] ?? '',
            $r['form_no'] ?? '',
            $r['admission_date'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/* ---------- Build children list preserving mapping order ---------- */
$childrenAll = [];
if (!empty($childIds)) {
    if (table_exists('students')) {
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        // select only fields we will display in the table
        $students = safe_db_get_all(
            "SELECT id, school_id, first_name, middle_name, last_name, class_id, form_no, admission_date, photo_path, father_first, father_phone, mother_first, mother_phone
             FROM students WHERE id IN ($ph)",
            $childIds
        );
        $byId = [];
        foreach ($students as $s) $byId[(int)$s['id']] = $s;
        foreach ($childIds as $cid) {
            if (isset($byId[$cid])) $childrenAll[] = $byId[$cid];
            else $childrenAll[] = [
                'id' => $cid,
                'first_name' => 'Student',
                'middle_name' => '',
                'last_name' => '#'.$cid,
                'class_id' => null,
                'form_no' => null,
                'admission_date' => null,
                'photo_path' => null,
                'placeholder' => true,
            ];
        }
    } else {
        foreach ($childIds as $cid) {
            $childrenAll[] = [
                'id' => $cid,
                'first_name' => 'Student',
                'middle_name' => '',
                'last_name' => '#'.$cid,
                'class_id' => null,
                'form_no' => null,
                'admission_date' => null,
                'photo_path' => null,
                'placeholder' => true,
            ];
        }
    }
}

/* ---------- Pagination ---------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, (int)($_GET['per'] ?? 20));
$total = count($childrenAll);
$totalPages = (int) ceil($total / $perPage);
$offset = ($page - 1) * $perPage;
$childrenPage = array_slice($childrenAll, $offset, $perPage);

/* ---------- Render ---------- */
$pageTitle = 'My Children';
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>

<div class="d-flex justify-content-end gap-2 mb-3">
  <a class="btn btn-outline-secondary" href="dashboard.php">Dashboard</a>
  <a class="btn btn-sm btn-success" href="?action=export">Export CSV</a>
</div>

<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:70px">ID</th>
            <th>Child</th>
            <th>Class / Form No</th>
            <th>Admission</th>
            <th style="width:220px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($childrenPage)): foreach ($childrenPage as $c): $cid = (int)$c['id']; ?>
            <tr<?php echo !empty($c['placeholder']) ? ' class="table-warning"' : ''; ?>>
              <td><?php echo $cid; ?></td>
              <td>
                <div class="d-flex align-items-center">
                  <div class="me-2">
                    <?php if (!empty($c['photo_path'])): ?>
                      <img src="<?php echo e(function_exists('student_photo_url') ? student_photo_url((string) ($c['photo_path'] ?? '')) : (string) ($c['photo_path'] ?? '')); ?>" alt="" class="child-photo">
                    <?php else: ?>
                      <div class="child-photo" style="background:#f1f1f1"></div>
                    <?php endif; ?>
                  </div>
                  <div>
                    <div class="fw-semibold"><?php echo e(trim((($c['first_name'] ?? '') . ' ' . ($c['middle_name'] ?? '') . ' ' . ($c['last_name'] ?? '')))); ?></div>
                    <?php if (!empty($c['placeholder'])): ?>
                      <div class="small-muted">Placeholder — no student record</div>
                    <?php else: ?>
                      <div class="small-muted">Father: <?php echo e($c['father_first'] ?? '—'); ?> • <?php echo e($c['father_phone'] ?? '—'); ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td><?php echo e($c['class_id'] ?? '—'); ?> / <?php echo e($c['form_no'] ?? '—'); ?></td>
              <td><?php echo e(substr((string)($c['admission_date'] ?? ''),0,10) ?: '—'); ?></td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo $cid; ?>">View</button>
                <a class="btn btn-sm btn-outline-primary" href="../parent/attendance.php?student_id=<?php echo $cid; ?>">Attendance</a>
                <a class="btn btn-sm btn-outline-success" href="../parent/fees.php?student_id=<?php echo $cid; ?>">Fees</a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5" class="text-center small-muted">No linked children found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($childrenPage)); ?> of <?php echo $total; ?></div>
      <nav>
        <ul class="pagination mb-0">
          <?php for ($p = 1; $p <= max(1,$totalPages); $p++): ?>
            <li class="page-item <?php if ($p === $page) echo 'active'; ?>"><a class="page-link" href="?page=<?php echo $p; ?>"><?php echo $p; ?></a></li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </div>
</main>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Child details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewModalBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ if (!resp.ok) throw new Error('Network'); return resp.text(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load details.</div>'; });
    });
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>