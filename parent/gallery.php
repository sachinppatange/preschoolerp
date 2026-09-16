<?php
/**
 * parent/gallery.php
 *
 * Parent-facing gallery (updated: class filter active behavior fixed)
 *
 * Save at: /pioneerplayschool01/parent/gallery.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
$DEBUG = panel_debug();

/* ---------- require parent login ---------- */
/* ---------- configuration - photo table ---------- */
$photoTable = 'class_photos';
$hasPhotosTable = table_exists($photoTable);

$photoCols = [
    'id'=>'id','class_id'=>'class_id','title'=>'title','description'=>'description','file_path'=>'file_path','uploaded_at'=>'uploaded_at'
];
if ($hasPhotosTable) {
    // verify required columns exist
    foreach (['id','file_path'] as $req) {
        $c = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c", [':t'=>$photoTable, ':c'=>$req]);
        if (empty($c) || intval($c['cnt'])===0) { $hasPhotosTable = false; break; }
    }
    // optional columns presence
    foreach ($photoCols as $k=>$col) {
        $present = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c", [':t'=>$photoTable, ':c'=>$col]);
        if (empty($present) || intval($present['cnt'])===0) $photoCols[$k] = null;
    }
}

/* ---------- determine classes linked to parent ---------- */
$parent = auth_user() ?? [];
$parentId = panel_parent_context_id();

$classIds = []; $childIds = [];
if (table_exists('parents_children')) {
    $maps = safe_db_get_all("SELECT child_student_id FROM parents_children WHERE parent_user_id = :pid", [':pid'=>$parentId]);
    foreach ($maps as $m) $childIds[] = (int)($m['child_student_id'] ?? 0);
}
if (!empty($childIds) && table_exists('students')) {
    $ph = implode(',', array_fill(0, count($childIds), '?'));
    $rows = safe_db_get_all("SELECT DISTINCT class_id FROM students WHERE id IN ($ph) AND class_id IS NOT NULL", $childIds);
    foreach ($rows as $r) if (!empty($r['class_id'])) $classIds[] = (int)$r['class_id'];
}
if (empty($classIds) && table_exists('students')) {
    $rows = safe_db_get_all("SELECT DISTINCT class_id FROM students WHERE parent_id = :pid AND class_id IS NOT NULL", [':pid'=>$parentId]);
    foreach ($rows as $r) if (!empty($r['class_id'])) $classIds[] = (int)$r['class_id'];
}

/* ---------- class labels ---------- */
$classOptions = [];
if (!empty($classIds) && table_exists('classes')) {
    $ph = implode(',', array_fill(0, count($classIds), '?'));
    $crow = safe_db_get_all("SELECT id, name, short_name, section FROM classes WHERE id IN ($ph)", $classIds);
    foreach ($crow as $c) {
        $id = (int)$c['id']; $lbl = trim((($c['short_name'] ?? '') . ' ' . ($c['name'] ?? '')));
        if (!empty($c['section'])) $lbl .= ' • Sec: ' . $c['section'];
        $classOptions[$id] = $lbl;
    }
    foreach ($classIds as $cid) if (!isset($classOptions[$cid])) $classOptions[$cid] = 'Class #' . $cid;
}

/* ---------- request params (ensure ints) ---------- */
$filterClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$from = isset($_GET['from']) ? substr((string)$_GET['from'],0,10) : '';
$to   = isset($_GET['to']) ? substr((string)$_GET['to'],0,10) : '';
$perPage = max(12, (int)($_GET['per'] ?? 24));
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page-1)*$perPage;

/* ---------- export (CSV) ---------- */
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    if (!$hasPhotosTable) { http_response_code(404); echo "No photos data."; exit; }
    if (empty($classIds)) { header('Content-Type:text/csv'); header('Content-Disposition:attachment;filename=photos_empty.csv'); echo "No photos\n"; exit; }

    $where=[];$params=[];
    if ($filterClass>0 && in_array($filterClass,$classIds,true) && $photoCols['class_id']) { $where[] = "{$photoCols['class_id']} = ?"; $params[] = $filterClass; }
    else {
        if ($photoCols['class_id']) { $ph = implode(',', array_fill(0, count($classIds), '?')); $where[] = "{$photoCols['class_id']} IN ($ph)"; foreach ($classIds as $v) $params[] = $v; }
    }
    if ($from !== '' && $photoCols['uploaded_at']) { $where[] = "DATE({$photoCols['uploaded_at']}) >= ?"; $params[] = $from; }
    if ($to !== '' && $photoCols['uploaded_at']) { $where[] = "DATE({$photoCols['uploaded_at']}) <= ?"; $params[] = $to; }
    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $select = "{$photoCols['id']} AS id";
    if ($photoCols['class_id']) $select .= ", {$photoCols['class_id']} AS class_id";
    if ($photoCols['title']) $select .= ", {$photoCols['title']} AS title";
    if ($photoCols['description']) $select .= ", {$photoCols['description']} AS description";
    $select .= ", {$photoCols['file_path']} AS file_path";
    if ($photoCols['uploaded_at']) $select .= ", {$photoCols['uploaded_at']} AS uploaded_at";

    $rows = safe_db_get_all("SELECT $select FROM {$photoTable} $whereSql ORDER BY " . ($photoCols['uploaded_at'] ? ($photoCols['uploaded_at'].' DESC') : "{$photoCols['id']} DESC"), $params);

    header('Content-Type:text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=photos_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output','w');
    $hdr = ['id','file_path'];
    if ($photoCols['class_id']) $hdr[] = 'class_id';
    if ($photoCols['title']) $hdr[] = 'title';
    if ($photoCols['description']) $hdr[] = 'description';
    if ($photoCols['uploaded_at']) $hdr[] = 'uploaded_at';
    fputcsv($out, $hdr);
    foreach ($rows as $r) {
        $line = [$r['id'] ?? '', $r['file_path'] ?? ''];
        if ($photoCols['class_id']) $line[] = $r['class_id'] ?? '';
        if ($photoCols['title']) $line[] = $r['title'] ?? '';
        if ($photoCols['description']) $line[] = $r['description'] ?? '';
        if ($photoCols['uploaded_at']) $line[] = $r['uploaded_at'] ?? '';
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/* ---------- fetch photos ---------- */
$photos = []; $total = 0;
if ($hasPhotosTable && !empty($classIds)) {
    $where=[];$params=[];
    if ($filterClass>0 && in_array($filterClass,$classIds,true) && $photoCols['class_id']) { $where[] = "{$photoCols['class_id']} = ?"; $params[] = $filterClass; }
    else {
        if ($photoCols['class_id']) { $ph = implode(',', array_fill(0, count($classIds), '?')); $where[] = "{$photoCols['class_id']} IN ($ph)"; foreach ($classIds as $v) $params[] = $v; }
    }
    if ($from !== '' && $photoCols['uploaded_at']) { $where[] = "DATE({$photoCols['uploaded_at']}) >= ?"; $params[] = $from; }
    if ($to !== '' && $photoCols['uploaded_at']) { $where[] = "DATE({$photoCols['uploaded_at']}) <= ?"; $params[] = $to; }
    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $cnt = safe_db_get_one("SELECT COUNT(*) AS cnt FROM {$photoTable} $whereSql", $params);
    $total = intval($cnt['cnt'] ?? 0);
    if ($total > 0) {
        $select = "{$photoCols['id']} AS id";
        if ($photoCols['class_id']) $select .= ", {$photoCols['class_id']} AS class_id";
        if ($photoCols['title']) $select .= ", {$photoCols['title']} AS title";
        if ($photoCols['description']) $select .= ", {$photoCols['description']} AS description";
        $select .= ", {$photoCols['file_path']} AS file_path";
        if ($photoCols['uploaded_at']) $select .= ", {$photoCols['uploaded_at']} AS uploaded_at";

        $sql = "SELECT $select FROM {$photoTable} $whereSql ORDER BY " . ($photoCols['uploaded_at'] ? ($photoCols['uploaded_at'].' DESC') : "{$photoCols['id']} DESC") . " LIMIT ? OFFSET ?";
        $paramsWithLimit = array_merge($params, [$perPage, $offset]);
        $photos = safe_db_get_all($sql, $paramsWithLimit);
    }
}

/* ---------- normalize url ---------- */
function normalize_file_url(string $p): string {
    $p = trim($p); if ($p === '') return '';
    if (preg_match('#^https?://#i', $p)) return $p;
    if ($p[0] === '/') return $p;
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); if ($base === '/') $base = '';
    return $base . '/' . ltrim($p, '/');
}

/* ---------- render ---------- */
$pageTitle = 'Gallery';
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>

<div class="d-flex gap-2">
    <?php if ($hasPhotosTable && $total > 0): ?>
      <a class="btn btn-sm btn-success" href="?action=export&class_id=<?php echo e($filterClass); ?>&from=<?php echo e($from); ?>&to=<?php echo e($to); ?>">Export CSV</a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary btn-sm" href="../parent/profile.php">Profile</a>
  </div>
</div>

<div class="card mb-3 p-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label">Class</label>
      <select name="class_id" class="form-select" id="classSelect">
        <option value="0"<?php if ($filterClass === 0) echo ' selected'; ?>>All linked classes</option>
        <?php foreach ($classOptions as $cid => $label): $cid = (int)$cid; ?>
          <option value="<?php echo $cid; ?>" <?php if ($filterClass === $cid) echo 'selected'; ?>><?php echo e($label); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-6">
      <!-- Quick class buttons -->
      <?php if (!empty($classOptions)): ?>
        <div class="btn-group" role="group" aria-label="Quick class filters">
          <button type="button" class="btn btn-outline-secondary <?php if ($filterClass===0) echo 'active'; ?>" data-class="0">All</button>
          <?php foreach ($classOptions as $cid=>$label): $cid=(int)$cid; ?>
            <button type="button" class="btn btn-outline-secondary <?php if ($filterClass=== $cid) echo 'active'; ?>" data-class="<?php echo $cid; ?>"><?php echo e($label); ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-md-2">
      <label class="form-label">From</label>
      <input type="date" name="from" value="<?php echo e($from); ?>" class="form-control form-control-sm">
    </div>
    <div class="col-md-2">
      <label class="form-label">To</label>
      <input type="date" name="to" value="<?php echo e($to); ?>" class="form-control form-control-sm">
    </div>
    <div class="col-12 text-end mt-2">
      <button class="btn btn-primary">Filter</button>
    </div>
  </form>
</div>

<?php if (!$hasPhotosTable): ?>
  <div class="alert alert-warning">No photos data available. Teacher uploads must populate the <code><?php echo e($photoTable); ?></code> table.</div>
<?php elseif (empty($classIds)): ?>
  <div class="alert alert-info">No classes linked to your account — no gallery photos.</div>
<?php elseif (empty($photos)): ?>
  <div class="card"><div class="card-body small-muted">No photos found for selected filters.</div></div>
<?php else: ?>
  <div class="card mb-3"><div class="card-body">
    <div class="card-grid">
      <?php foreach ($photos as $p): $pid=(int)($p['id'] ?? 0); $file=(string)($p['file_path'] ?? ''); $url=normalize_file_url($file); $title=trim((string)($p['title'] ?? '')); $desc=trim((string)($p['description'] ?? '')); $uploaded=$p['uploaded_at'] ?? ''; $classLabel = ($p['class_id'] && isset($classOptions[$p['class_id']])) ? $classOptions[$p['class_id']] : ($p['class_id'] ?? ''); ?>
        <div class="card">
          <div style="height:160px;display:flex;align-items:center;justify-content:center;background:#f8f9fa;overflow:hidden">
            <?php if ($url): ?><img src="<?php echo e($url); ?>" class="thumb" alt="<?php echo e($title ?: 'Photo'); ?>" loading="lazy"><?php else: ?><div class="small-muted">No image</div><?php endif; ?>
          </div>
          <div class="card-body p-2">
            <div class="fw-semibold small mb-1"><?php echo e($title ?: 'Untitled'); ?></div>
            <div class="small-muted" style="font-size:.85rem"><?php echo e($classLabel ? 'Class: ' . $classLabel : ''); ?></div>
            <div class="d-flex justify-content-between align-items-center mt-2">
              <div class="small-muted" style="font-size:.8rem"><?php echo e($uploaded ? substr($uploaded,0,16) : ''); ?></div>
              <div>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#photoViewModal" data-file="<?php echo e($url); ?>" data-title="<?php echo e($title); ?>" data-desc="<?php echo e($desc); ?>" data-uploaded="<?php echo e($uploaded); ?>">View</button>
                <?php if ($url): ?><a class="btn btn-sm btn-outline-secondary ms-1" href="<?php echo e($url); ?>" download>Download</a><?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mt-3 d-flex justify-content-between align-items-center">
      <div class="small-muted">Showing <?php echo min($offset+1,$total); ?> - <?php echo min($offset + count($photos), $total); ?> of <?php echo $total; ?> photos</div>
      <?php $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1; ?>
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <li class="page-item <?php if ($page<=1) echo 'disabled'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['p'=>max(1,$page-1)])); ?>">Prev</a></li>
          <li class="page-item disabled"><span class="page-link">Page <?php echo $page; ?> / <?php echo max(1,$totalPages); ?></span></li>
          <li class="page-item <?php if ($page>=$totalPages) echo 'disabled'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['p'=>min($totalPages,$page+1)])); ?>">Next</a></li>
        </ul>
      </nav>
    </div>
  </div></div>
<?php endif; ?>

<!-- Photo modal -->
<div class="modal fade" id="photoViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="photoTitle">Photo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body text-center" id="photoBody"><div class="small-muted py-3">Loading…</div></div>
      <div class="modal-footer"><div class="me-auto small-muted" id="photoMeta"></div><a id="photoDownload" class="btn btn-outline-secondary" href="#" download>Download</a><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // quick class buttons behavior: set select + submit
  document.querySelectorAll('.btn-group button[data-class]').forEach(function(b){
    b.addEventListener('click', function(){
      var cid = this.getAttribute('data-class');
      var sel = document.getElementById('classSelect');
      if (sel) sel.value = cid;
      // submit the parent form
      var form = sel && sel.form ? sel.form : document.querySelector('form');
      if (form) form.submit();
    });
  });

  var photoModal = document.getElementById('photoViewModal');
  if (photoModal) {
    photoModal.addEventListener('show.bs.modal', function(event){
      var btn = event.relatedTarget;
      var file = btn.getAttribute('data-file') || '';
      var title = btn.getAttribute('data-title') || '';
      var desc = btn.getAttribute('data-desc') || '';
      var uploaded = btn.getAttribute('data-uploaded') || '';
      document.getElementById('photoTitle').textContent = title || 'Photo';
      var body = document.getElementById('photoBody'); body.innerHTML = '';
      if (file) {
        var img = document.createElement('img'); img.src = file; img.alt = title; img.style.maxWidth='100%'; img.style.maxHeight='70vh'; img.style.objectFit='contain';
        body.appendChild(img);
        if (desc) { var p = document.createElement('p'); p.className='mt-3 small-muted'; p.textContent = desc; body.appendChild(p); }
      } else body.innerHTML = '<div class="small-muted py-3">No image</div>';
      document.getElementById('photoMeta').textContent = uploaded ? ('Uploaded: ' + uploaded) : '';
      var dl = document.getElementById('photoDownload');
      if (file) { dl.href = file; dl.style.display = 'inline-block'; } else dl.style.display = 'none';
    });
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>