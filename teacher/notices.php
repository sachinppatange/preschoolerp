<?php
/**
 * teacher/notices.php
 *
 * Notices management for teachers (full file).
 *
 * Features:
 * - Lists notices from `notices` table (paginated + filter by class).
 * - Create / Edit / Delete notices (server-side authorization).
 * - View single notice (action=view&id=...).
 * - Auto-detects column names in `notices` and `classes` to adapt to schema variations.
 * - Reuses includes if present (config.php, db.php, functions.php, csrf.php).
 * - Safe PDO fallbacks provided for environments without project helpers.
 *
 * Place at: /pioneerplayschool01/teacher/notices.php
 *
 * NOTE:
 * - Ensure session is working and includes/csrf.php (or equivalent) is not redeclaring functions.
 * - If you still face CSRF redeclare issues, remove duplicate functions or ensure includes use guards (if (!function_exists(...))).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

if (!defined('DEV_SHOW_ERRORS')) define('DEV_SHOW_ERRORS', false);
$env = getenv('DEV_SHOW_ERRORS');
$teacherId = auth_user_id() ?? 0;
$teacherSession = auth_user() ?? [];

/* -------------------------
   Notices table detection & column mapping
   ------------------------- */
$noticesTable = 'notices';
$noticesExists = table_exists($noticesTable);
$noticeCols = $noticesExists ? get_table_columns($noticesTable) : [];

$colId = in_array('id', $noticeCols, true) ? 'id' : (in_array('ID', $noticeCols, true) ? 'ID' : null);
$colSchool = in_array('school_id', $noticeCols, true) ? 'school_id' : null;
$colTitle = in_array('title', $noticeCols, true) ? 'title' : (in_array('heading', $noticeCols, true) ? 'heading' : null);
$colContent = in_array('content', $noticeCols, true) ? 'content' : (in_array('body', $noticeCols, true) ? 'body' : (in_array('description', $noticeCols, true) ? 'description' : null));
$colClass = in_array('class_id', $noticeCols, true) ? 'class_id' : (in_array('target_class', $noticeCols, true) ? 'target_class' : null);
$colCreator = in_array('created_by', $noticeCols, true) ? 'created_by' : (in_array('recorded_by', $noticeCols, true) ? 'recorded_by' : null);
$colPublish = in_array('publish_date', $noticeCols, true) ? 'publish_date' : (in_array('date', $noticeCols, true) ? 'date' : null);
$colExpire = in_array('expire_date', $noticeCols, true) ? 'expire_date' : null;
$colPublishedFlag = in_array('is_published', $noticeCols, true) ? 'is_published' : null;
$colCreatedAt = in_array('created_at', $noticeCols, true) ? 'created_at' : null;
$colUpdatedAt = in_array('updated_at', $noticeCols, true) ? 'updated_at' : null;

$assignedClassIds = array_map(
    static fn(array $c): int => (int) ($c['id'] ?? 0),
    panel_teacher_assigned_classes($teacherId)
);
$assignedClassIds = array_values(array_unique(array_filter($assignedClassIds, static fn(int $v): bool => $v > 0)));

/* -------------------------
   Action handling
   ------------------------- */
$messages = []; $errors = [];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$noticeId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);

/* permission helper */
function can_manage_notice(array $notice, int $teacherId, array $assignedClassIds, ?string $colCreator = null, ?string $colClass = null): bool {
    if (function_exists('auth_is_owner_super') && auth_is_owner_super()) {
        return true;
    }
    if ($colCreator && isset($notice[$colCreator]) && (int)$notice[$colCreator] === $teacherId) return true;
    if ($colClass && isset($notice[$colClass]) && in_array((int)$notice[$colClass], $assignedClassIds, true)) return true;
    return false;
}

/* helper: sanitize posted input keys dynamically */
function posted(string $k) { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : null; }

/* POST: create or update */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create','update'], true)) {
    if (!validate_csrf_token($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } elseif (!$noticesExists) {
        $errors[] = "Table `{$noticesTable}` not found.";
    } else {
        // gather fields
        $title = $colTitle ? (posted($colTitle) ?: null) : null;
        // content can be under several names; check common ones
        $content = null;
        foreach ([$colContent, 'content', 'body', 'description', 'message', 'notice'] as $n) {
            if ($n && isset($_POST[$n]) && trim((string)$_POST[$n]) !== '') { $content = trim((string)$_POST[$n]); break; }
        }
        $class_val = $colClass ? (isset($_POST[$colClass]) && $_POST[$colClass] !== '' ? (int)$_POST[$colClass] : null) : null;
        $publish_date = $colPublish ? (posted($colPublish) ?: date('Y-m-d')) : null;
        $expire_date = $colExpire ? (posted($colExpire) ?: null) : null;
        $is_published = $colPublishedFlag ? (!empty($_POST[$colPublishedFlag]) ? 1 : 0) : null;

        // basic validation
        if ($colTitle && empty($title)) $errors[] = 'Title is required.';
        if ($colContent && empty($content)) $errors[] = 'Content is required.';
        if ($colClass && !is_null($class_val) && $class_val > 0 && !in_array($class_val, $assignedClassIds, true)) {
            $errors[] = 'You are not assigned to the selected class.';
        }

        if (empty($errors)) {
            $pdo = pdo_connect();
            if (!($pdo instanceof PDO)) {
                $errors[] = 'Database connection failed.';
            } else {
                try {
                    if ($action === 'create') {
                        $cols = []; $placeholders = []; $params = [];
                        if ($colSchool) { $cols[] = $colSchool; $placeholders[] = ':school_id'; $params[':school_id'] = null; }
                        if ($colTitle) { $cols[] = $colTitle; $placeholders[] = ':title'; $params[':title'] = $title; }
                        if ($colContent) { $cols[] = $colContent; $placeholders[] = ':content'; $params[':content'] = $content; }
                        if ($colClass) { $cols[] = $colClass; $placeholders[] = ':class_id'; $params[':class_id'] = $class_val; }
                        if ($colPublish) { $cols[] = $colPublish; $placeholders[] = ':publish_date'; $params[':publish_date'] = $publish_date; }
                        if ($colExpire) { $cols[] = $colExpire; $placeholders[] = ':expire_date'; $params[':expire_date'] = $expire_date; }
                        if ($colPublishedFlag) { $cols[] = $colPublishedFlag; $placeholders[] = ':is_published'; $params[':is_published'] = $is_published; }
                        if ($colCreator) { $cols[] = $colCreator; $placeholders[] = ':created_by'; $params[':created_by'] = $teacherId; }
                        if ($colCreatedAt) { $cols[] = $colCreatedAt; $placeholders[] = ':created_at'; $params[':created_at'] = date('Y-m-d H:i:s'); }

                        if (empty($cols)) {
                            $errors[] = 'No writable columns detected in notices table.';
                        } else {
                            $sql = "INSERT INTO `{$noticesTable}` (" . implode(',', array_map(function($c){ return "`{$c}`"; }, $cols)) . ") VALUES (" . implode(',', $placeholders) . ")";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute($params);
                            $messages[] = 'Notice created.';
                        }
                    } else { // update
                        $existing = safe_db_get_one("SELECT * FROM `{$noticesTable}` WHERE " . ($colId ? "`{$colId}` = :id" : "id = :id") . " LIMIT 1", [':id'=>$noticeId]);
                        if (empty($existing)) {
                            $errors[] = 'Notice not found.';
                        } elseif (!can_manage_notice($existing, $teacherId, $assignedClassIds, $colCreator, $colClass)) {
                            $errors[] = 'Not authorized to edit this notice.';
                        } else {
                            $sets = []; $params = [];
                            if ($colTitle) { $sets[] = "`{$colTitle}` = :title"; $params[':title'] = $title; }
                            if ($colContent) { $sets[] = "`{$colContent}` = :content"; $params[':content'] = $content; }
                            if ($colClass) { $sets[] = "`{$colClass}` = :class_id"; $params[':class_id'] = $class_val; }
                            if ($colPublish) { $sets[] = "`{$colPublish}` = :publish_date"; $params[':publish_date'] = $publish_date; }
                            if ($colExpire) { $sets[] = "`{$colExpire}` = :expire_date"; $params[':expire_date'] = $expire_date; }
                            if ($colPublishedFlag) { $sets[] = "`{$colPublishedFlag}` = :is_published"; $params[':is_published'] = $is_published; }
                            if ($colUpdatedAt) { $sets[] = "`{$colUpdatedAt}` = :updated_at"; $params[':updated_at'] = date('Y-m-d H:i:s'); }

                            if (empty($sets)) {
                                $errors[] = 'No updatable columns detected.';
                            } else {
                                $params[':id'] = $noticeId;
                                $sql = "UPDATE `{$noticesTable}` SET " . implode(',', $sets) . " WHERE " . ($colId ? "`{$colId}` = :id" : "id = :id");
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute($params);
                                $messages[] = 'Notice updated.';
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = 'DB error: ' . ($DEBUG ? $e->getMessage() : 'Failed to save notice.');
                }
            }
        }
    }
}

/* DELETE (GET or POST) */
if ($action === 'delete' && $noticeId > 0) {
    $tokenOk = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') $tokenOk = validate_csrf_token($_POST['csrf'] ?? '');
    if (!$tokenOk) {
        $errors[] = 'Invalid CSRF token for delete.';
    } elseif (!$noticesExists) {
        $errors[] = "Table `{$noticesTable}` not found.";
    } else {
        $row = safe_db_get_one("SELECT * FROM `{$noticesTable}` WHERE " . ($colId ? "`{$colId}` = :id" : "id = :id") . " LIMIT 1", [':id'=>$noticeId]);
        if (empty($row)) {
            $errors[] = 'Notice not found.';
        } elseif (!can_manage_notice($row, $teacherId, $assignedClassIds, $colCreator, $colClass)) {
            $errors[] = 'Not authorized to delete this notice.';
        } else {
            $ok = safe_db_run("DELETE FROM `{$noticesTable}` WHERE " . ($colId ? "`{$colId}` = :id" : "id = :id"), [':id'=>$noticeId]);
            if ($ok) $messages[] = 'Notice deleted.';
            else $errors[] = 'Failed to delete notice.';
        }
    }
    $action = '';
}

/* -------------------------
   Listing & pagination
   ------------------------- */
$perPage = 20;
$page = max(1, isset($_GET['p']) ? (int)$_GET['p'] : 1);
$offset = ($page - 1) * $perPage;

$notices = [];
$totalNotices = 0;

if ($noticesExists) {
    $whereParts = []; $params = [];

    if ($colPublishedFlag) {
        $whereParts[] = "(`{$colPublishedFlag}` = 1 OR (`{$colCreator}` = :me))";
        $params[':me'] = $teacherId;
    } else {
        $whereParts[] = "1";
    }

    $filterClass = isset($_GET['filter_class']) ? (int)$_GET['filter_class'] : 0;
    if ($filterClass > 0 && $colClass) {
        $whereParts[] = "(`{$colClass}` = :filter_class OR `{$colClass}` IS NULL)";
        $params[':filter_class'] = $filterClass;
    }

    $whereSql = implode(' AND ', $whereParts);
    $cntRow = safe_db_get_one("SELECT COUNT(*) AS cnt FROM `{$noticesTable}` WHERE {$whereSql}", $params);
    $totalNotices = intval($cntRow['cnt'] ?? 0);

    if ($totalNotices > 0) {
        // Build select
        $selectCols = [];
        if ($colId) $selectCols[] = "`{$colId}` AS id";
        if ($colTitle) $selectCols[] = "`{$colTitle}` AS title";
        if ($colContent) $selectCols[] = "`{$colContent}` AS content";
        if ($colClass) $selectCols[] = "`{$colClass}` AS class_id";
        if ($colCreator) $selectCols[] = "`{$colCreator}` AS created_by";
        if ($colPublish) $selectCols[] = "`{$colPublish}` AS publish_date";
        if ($colPublishedFlag) $selectCols[] = "`{$colPublishedFlag}` AS is_published";
        if ($colCreatedAt) $selectCols[] = "`{$colCreatedAt}` AS created_at";
        if (empty($selectCols)) $selectCols[] = "*";

        // We'll bind named params then append LIMIT/OFFSET as positional
        $sql = "SELECT " . implode(',', $selectCols) . " FROM `{$noticesTable}` WHERE {$whereSql} ORDER BY " . ($colPublish ? "`{$colPublish}` DESC" : ($colCreatedAt ? "`{$colCreatedAt}` DESC" : "`id` DESC")) . " LIMIT ? OFFSET ?";
        $pdo = pdo_connect();
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare($sql);
                $execParams = array_values($params);
                $execParams[] = $perPage;
                $execParams[] = $offset;
                $stmt->execute($execParams);
                $notices = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                if ($DEBUG) error_log('Listing error: ' . $e->getMessage());
                $notices = safe_db_get_all("SELECT * FROM `{$noticesTable}` ORDER BY " . ($colPublish ? "`{$colPublish}` DESC" : ($colCreatedAt ? "`{$colCreatedAt}` DESC" : "`id` DESC")) . " LIMIT ? OFFSET ?", [$perPage, $offset]);
            }
        } else {
            $notices = safe_db_get_all("SELECT * FROM `{$noticesTable}` ORDER BY " . ($colPublish ? "`{$colPublish}` DESC" : ($colCreatedAt ? "`{$colCreatedAt}` DESC" : "`id` DESC")) . " LIMIT ? OFFSET ?", [$perPage, $offset]);
        }
    }
}

/* Fetch classes for select (if exists) */
$classesForSelect = [];
if (table_exists('classes')) {
    $avail = get_table_columns('classes');
    $selCols = ['id'];
    if (in_array('name', $avail, true)) $selCols[] = 'name';
    if (in_array('short_name', $avail, true)) $selCols[] = 'short_name';
    $rows = safe_db_get_all('SELECT ' . implode(',', array_map(function($c){ return "`{$c}`"; }, $selCols)) . ' FROM classes ORDER BY ' . (in_array('name', $selCols, true) ? 'name' : 'id') . ' ASC');
    foreach ($rows as $r) $classesForSelect[] = $r;
}

/* CSRF token for forms */
$csrf = get_csrf_token();

/* -------------------------
   Render HTML
   ------------------------- */
$pageTitle = 'Notices';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

<div class="row g-3">
  <div class="col-md-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6>Create Notice</h6>

        <?php if (!$noticesExists): ?>
          <div class="small-muted">Notices table not found. Create the table first.</div>
        <?php else: ?>
          <form method="post" class="mb-0">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="create">

            <?php if ($colTitle): ?>
              <div class="mb-2">
                <label class="form-label small">Title</label>
                <input name="<?php echo e($colTitle); ?>" class="form-control" required>
              </div>
            <?php endif; ?>

            <?php if ($colContent): ?>
              <div class="mb-2">
                <label class="form-label small">Content</label>
                <textarea name="<?php echo e($colContent); ?>" rows="4" class="form-control"></textarea>
              </div>
            <?php endif; ?>

            <?php if ($colClass && !empty($classesForSelect)): ?>
              <div class="mb-2">
                <label class="form-label small">Target Class (optional)</label>
                <select name="<?php echo e($colClass); ?>" class="form-select">
                  <option value="">All / School-wide</option>
                  <?php foreach ($classesForSelect as $cl): $cid = (int)$cl['id']; $label = (!empty($cl['short_name']) ? $cl['short_name'] . ' ' : '') . ($cl['name'] ?? ''); ?>
                    <option value="<?php echo $cid; ?>"><?php echo e($label ?: ('Class #' . $cid)); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <?php if ($colPublish): ?>
              <div class="mb-2">
                <label class="form-label small">Publish Date</label>
                <input type="date" name="<?php echo e($colPublish); ?>" class="form-control" value="<?php echo e(date('Y-m-d')); ?>">
              </div>
            <?php endif; ?>

            <?php if ($colExpire): ?>
              <div class="mb-2">
                <label class="form-label small">Expire Date</label>
                <input type="date" name="<?php echo e($colExpire); ?>" class="form-control" value="">
              </div>
            <?php endif; ?>

            <?php if ($colPublishedFlag): ?>
              <div class="mb-2 form-check">
                <input type="checkbox" name="<?php echo e($colPublishedFlag); ?>" value="1" class="form-check-input" id="pubFlag" checked>
                <label class="form-check-label small" for="pubFlag">Publish now</label>
              </div>
            <?php endif; ?>

            <div class="d-grid">
              <button class="btn btn-primary">Create Notice</button>
            </div>
          </form>
        <?php endif; ?>

      </div>
    </div>

    <?php if ($DEBUG): ?>
      <div class="card mt-3"><div class="card-body small text-muted">Debug: noticeCols=<?php echo e(json_encode($noticeCols)); ?>; assignedClasses=<?php echo e(json_encode($assignedClassIds)); ?></div></div>
    <?php endif; ?>
  </div>

  <div class="col-md-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="mb-0">Notices</h6>
          <form method="get" class="d-flex align-items-center">
            <?php if (!empty($classesForSelect) && $colClass): ?>
              <select name="filter_class" class="form-select form-select-sm me-2">
                <option value="0">All classes</option>
                <?php foreach ($classesForSelect as $cl): $cid = (int)$cl['id']; $lab = (!empty($cl['short_name']) ? $cl['short_name'] . ' ' : '') . ($cl['name'] ?? ''); ?>
                  <option value="<?php echo $cid; ?>" <?php if(isset($_GET['filter_class']) && (int)$_GET['filter_class'] === $cid) echo 'selected'; ?>><?php echo e($lab ?: ('Class #'.$cid)); ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
          </form>
        </div>

        <?php if (empty($notices)): ?>
          <div class="small-muted">No notices found.</div>
        <?php else: ?>
          <div class="list-group">
            <?php foreach ($notices as $n):
              $nid = (int)($n['id'] ?? $n['ID'] ?? 0);
              $ntitle = $n['title'] ?? ($n['heading'] ?? 'Untitled');
              $nsnippet = $n['content'] ?? ($n['body'] ?? '');
              $nclass = isset($n['class_id']) ? (int)$n['class_id'] : null;
              $ncreated = $n['created_at'] ?? ($n['publish_date'] ?? null);
            ?>
              <div class="list-group-item">
                <div class="d-flex justify-content-between">
                  <div>
                    <div class="fw-semibold"><?php echo e($ntitle); ?></div>
                    <div class="small-muted content-snippet"><?php echo nl2br(e(mb_substr((string)$nsnippet, 0, 300))); ?></div>
                    <div class="small-muted mt-1"><?php if ($nclass) echo 'Class: ' . e($nclass) . ' · '; ?><?php echo e($ncreated ? substr($ncreated,0,10) : ''); ?></div>
                  </div>
                  <div class="text-end">
                    <?php
                      $manageAllowed = false;
                      if ($noticesExists) {
                          $full = safe_db_get_one("SELECT * FROM `{$noticesTable}` WHERE " . ($colId ? "`{$colId}` = :id" : "id = :id") . " LIMIT 1", [':id'=>$nid]);
                          if ($full) $manageAllowed = can_manage_notice($full, $teacherId, $assignedClassIds, $colCreator, $colClass);
                      }
                    ?>
                    <?php if ($manageAllowed): ?>
                      <a class="btn btn-sm btn-outline-primary" href="?action=edit&id=<?php echo $nid; ?>">Edit</a>
                      <?php echo render_secure_delete_button((int)$nid, 'Delete', 'Delete this notice?', 'btn btn-sm btn-outline-danger'); ?>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-outline-secondary" href="?action=view&id=<?php echo $nid; ?>">View</a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($totalNotices > $perPage): $totalPages = (int) ceil($totalNotices / $perPage); ?>
            <nav class="mt-3" aria-label="pagination">
              <ul class="pagination pagination-sm">
                <li class="page-item <?php echo $page<=1 ? 'disabled' : ''; ?>"><a class="page-link" href="?p=<?php echo max(1,$page-1); ?>">Prev</a></li>
                <li class="page-item disabled"><span class="page-link">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
                <li class="page-item <?php echo $page>=$totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="?p=<?php echo min($totalPages,$page+1); ?>">Next</a></li>
              </ul>
            </nav>
          <?php endif; ?>

        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<?php
/* -------------------------
   View single notice (detailed)
   ------------------------- */
if ($action === 'view' && $noticeId > 0 && $noticesExists) {
    $pdo = pdo_connect();
    if (!($pdo instanceof PDO)) {
        echo '<div class="alert alert-danger">Database connection error while loading notice.</div>';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM `{$noticesTable}` WHERE " . ($colId ? "`{$colId}` = ?" : "id = ?") . " LIMIT 1");
            $stmt->execute([$noticeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (empty($row)) {
                echo '<div class="alert alert-warning">Notice not found.</div>';
            } else {
                echo '<div class="container py-3"><div class="card"><div class="card-body">';
                $title = $colTitle && isset($row[$colTitle]) ? $row[$colTitle] : ($row['title'] ?? ($row['heading'] ?? 'Untitled'));
                $content = $colContent && isset($row[$colContent]) ? $row[$colContent] : ($row['content'] ?? ($row['body'] ?? ''));
                $publish = $colPublish && isset($row[$colPublish]) ? $row[$colPublish] : ($row['publish_date'] ?? ($row['date'] ?? ''));
                echo '<h4>' . e((string)$title) . '</h4>';
                if ($publish) echo '<div class="small-muted mb-2">' . e(substr((string)$publish,0,10)) . '</div>';
                echo '<div>' . nl2br(e((string)$content)) . '</div>';
                // raw debug fields for visibility
                echo '<hr><h6 class="small-muted">Raw fields</h6><pre style="max-height:300px;overflow:auto;background:#f8f9fa;padding:.5rem;border-radius:.25rem;">';
                foreach ($row as $k => $v) {
                    echo e((string)$k) . ': ' . e((string)$v) . "\n";
                }
                echo '</pre>';
                echo '<p class="mt-3"><a class="btn btn-sm btn-outline-secondary" href="notices.php">Back to notices</a></p>';
                echo '</div></div></div>';
            }
        } catch (Throwable $e) {
            if ($DEBUG) {
                echo '<div class="alert alert-danger">Error: ' . e($e->getMessage()) . '</div>';
            } else {
                echo '<div class="alert alert-danger">Failed to load notice.</div>';
            }
        }
    }
}

/* -------------------------
   Edit form (if requested)
   ------------------------- */
if ($action === 'edit' && $noticeId > 0 && $noticesExists) {
    $row = safe_db_get_one("SELECT * FROM `{$noticesTable}` WHERE " . ($colId ? "`{$colId}` = :id" : "id = :id") . " LIMIT 1", [':id'=>$noticeId]);
    if (empty($row)) {
        echo '<div class="alert alert-warning">Notice not found.</div>';
    } elseif (!can_manage_notice($row, $teacherId, $assignedClassIds, $colCreator, $colClass)) {
        echo '<div class="alert alert-danger">Not authorized to edit this notice.</div>';
    } else {
        ?>
        <div class="container py-3">
          <div class="card">
            <div class="card-body">
              <h5>Edit Notice</h5>
              <form method="post">
                <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?php echo (int)$noticeId; ?>">

                <?php if ($colTitle): ?>
                  <div class="mb-2">
                    <label class="form-label small">Title</label>
                    <input name="<?php echo e($colTitle); ?>" class="form-control" value="<?php echo e($row[$colTitle] ?? $row['title'] ?? ''); ?>">
                  </div>
                <?php endif; ?>

                <?php if ($colContent): ?>
                  <div class="mb-2">
                    <label class="form-label small">Content</label>
                    <textarea name="<?php echo e($colContent); ?>" rows="6" class="form-control"><?php echo e($row[$colContent] ?? $row['content'] ?? ''); ?></textarea>
                  </div>
                <?php endif; ?>

                <?php if ($colClass && !empty($classesForSelect)): ?>
                  <div class="mb-2">
                    <label class="form-label small">Target Class</label>
                    <select name="<?php echo e($colClass); ?>" class="form-select">
                      <option value="">All / School-wide</option>
                      <?php foreach ($classesForSelect as $cl): $cid = (int)$cl['id']; $lab = (!empty($cl['short_name']) ? $cl['short_name'].' ' : '') . ($cl['name'] ?? ''); ?>
                        <option value="<?php echo $cid; ?>" <?php if(isset($row[$colClass]) && (int)$row[$colClass] === $cid) echo 'selected'; ?>><?php echo e($lab ?: ('Class #'.$cid)); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>

                <?php if ($colPublish): ?>
                  <div class="mb-2">
                    <label class="form-label small">Publish Date</label>
                    <input type="date" name="<?php echo e($colPublish); ?>" class="form-control" value="<?php echo e(substr($row[$colPublish] ?? $row['publish_date'] ?? '',0,10)); ?>">
                  </div>
                <?php endif; ?>

                <?php if ($colExpire): ?>
                  <div class="mb-2">
                    <label class="form-label small">Expire Date</label>
                    <input type="date" name="<?php echo e($colExpire); ?>" class="form-control" value="<?php echo e(substr($row[$colExpire] ?? '',0,10)); ?>">
                  </div>
                <?php endif; ?>

                <?php if ($colPublishedFlag): ?>
                  <div class="mb-2 form-check">
                    <input type="checkbox" name="<?php echo e($colPublishedFlag); ?>" value="1" class="form-check-input" id="pubFlagEdit" <?php if(!empty($row[$colPublishedFlag])) echo 'checked'; ?>>
                    <label class="form-check-label small" for="pubFlagEdit">Published</label>
                  </div>
                <?php endif; ?>

                <div class="d-grid">
                  <button class="btn btn-primary">Save</button>
                  <a class="btn btn-outline-secondary mt-2" href="notices.php">Cancel</a>
                </div>
              </form>
            </div>
          </div>
        </div>
        <?php
    }
}

/* -------------------------
   Footer include or fallback
   ------------------------- */
require_once __DIR__ . '/../includes/footer.php';

?>