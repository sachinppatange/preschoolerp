<?php
/**
 * teacher/notices.php — short notes parents see for this class.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

$teacherId = (int) (auth_user_id() ?? 0);
$schoolId = function_exists('auth_school_id') ? (int) auth_school_id() : 1;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$assignedClasses = panel_teacher_assigned_classes($teacherId);

$classRank = static function (array $c): int {
    $n = strtolower((string) ($c['name'] ?? ''));
    if (str_starts_with($n, 'play')) {
        return 1;
    }
    if (str_starts_with($n, 'nurs')) {
        return 2;
    }
    if (str_starts_with($n, 'l')) {
        return 3;
    }
    if (str_starts_with($n, 'u')) {
        return 4;
    }
    return 9;
};
usort($assignedClasses, static function (array $a, array $b) use ($classRank): int {
    $d = $classRank($a) <=> $classRank($b);
    return $d !== 0 ? $d : strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

$allowedIds = array_values(array_filter(array_map(static fn($c) => (int) ($c['id'] ?? 0), $assignedClasses)));
$canClass = static function (int $classId) use ($allowedIds): bool {
    if (function_exists('auth_is_owner_super') && auth_is_owner_super()) {
        return true;
    }
    return in_array($classId, $allowedIds, true);
};

$tableOk = table_exists('notices');
$col = static function (string $name) use ($tableOk): bool {
    return $tableOk && function_exists('column_exists') && column_exists('notices', $name);
};

$textCol = null;
foreach (['message', 'body', 'content', 'description'] as $c) {
    if ($col($c)) {
        $textCol = $c;
        break;
    }
}

$messages = [];
$errors = [];

$selectedClassId = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
if ($selectedClassId <= 0 && $allowedIds !== []) {
    $selectedClassId = $allowedIds[0];
}
if ($selectedClassId > 0 && !$canClass($selectedClassId)) {
    $selectedClassId = 0;
}

$ownsNotice = static function (array $row) use ($teacherId, $col): bool {
    if (function_exists('auth_is_owner_super') && auth_is_owner_super()) {
        return true;
    }
    if ($col('created_by') && (int) ($row['created_by'] ?? 0) === $teacherId) {
        return true;
    }
    return $col('published_by') && (int) ($row['published_by'] ?? 0) === $teacherId;
};

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get((string) ($_REQUEST['action'] ?? ''))) {
    $errors[] = 'Delete needs confirmation.';
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0 && $tableOk) {
    $row = safe_db_get_one('SELECT * FROM notices WHERE id = :id LIMIT 1', [':id' => $deleteId]);
    if (!$row || !$ownsNotice($row)) {
        $errors[] = 'You cannot delete this notice.';
    } elseif (safe_db_run('DELETE FROM notices WHERE id = :id', [':id' => $deleteId])) {
        header('Location: ?class_id=' . $selectedClassId . '&deleted=1');
        exit;
    } else {
        $errors[] = 'Could not delete.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') !== 'delete') {
    if (!function_exists('validate_csrf_token') || !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } elseif (!$tableOk) {
        $errors[] = 'Notices are not set up yet.';
    } else {
        $classId = (int) ($_POST['class_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $text = trim((string) ($_POST['message'] ?? ''));
        $id = (int) ($_POST['id'] ?? 0);
        if ($classId <= 0 || !$canClass($classId)) {
            $errors[] = 'Choose a class.';
        }
        if ($title === '') {
            $errors[] = 'Write a short heading.';
        }
        if ($text === '') {
            $errors[] = 'Write the note for parents.';
        }

        if ($errors === []) {
            $fields = [];
            if ($col('title')) {
                $fields['title'] = $title;
            }
            if ($textCol) {
                $fields[$textCol] = $text;
            }
            if ($col('class_id')) {
                $fields['class_id'] = $classId;
            }
            if ($col('school_id')) {
                $fields['school_id'] = $schoolId > 0 ? $schoolId : 1;
            }
            if ($col('audience')) {
                $fields['audience'] = 'parents';
            }
            if ($col('published_at')) {
                $fields['published_at'] = date('Y-m-d H:i:s');
            }
            if ($col('published_by')) {
                $fields['published_by'] = $teacherId > 0 ? $teacherId : null;
            }

            if ($id > 0) {
                $existing = safe_db_get_one('SELECT * FROM notices WHERE id = :id LIMIT 1', [':id' => $id]);
                if (!$existing || !$ownsNotice($existing)) {
                    $errors[] = 'You cannot edit this notice.';
                } else {
                    if ($col('updated_at')) {
                        $fields['updated_at'] = date('Y-m-d H:i:s');
                    }
                    $set = [];
                    $params = [];
                    foreach ($fields as $k => $v) {
                        $set[] = '`' . $k . '` = :' . $k;
                        $params[':' . $k] = $v;
                    }
                    $params[':id'] = $id;
                    if ($set !== [] && safe_db_run('UPDATE notices SET ' . implode(', ', $set) . ' WHERE id = :id', $params)) {
                        header('Location: ?class_id=' . $classId . '&saved=1');
                        exit;
                    }
                    $errors[] = 'Could not update.';
                }
            } else {
                if ($col('created_by')) {
                    $fields['created_by'] = $teacherId > 0 ? $teacherId : null;
                }
                $cols = array_keys($fields);
                $ph = [];
                $params = [];
                foreach ($cols as $k) {
                    $ph[] = ':' . $k;
                    $params[':' . $k] = $fields[$k];
                }
                $sql = 'INSERT INTO notices (' . implode(',', array_map(static fn($c) => '`' . $c . '`', $cols));
                if ($col('created_at') && !isset($fields['created_at'])) {
                    $sql .= ', created_at) VALUES (' . implode(',', $ph) . ', NOW())';
                } else {
                    $sql .= ') VALUES (' . implode(',', $ph) . ')';
                }
                if ($cols !== [] && safe_db_run($sql, $params)) {
                    header('Location: ?class_id=' . $classId . '&saved=1');
                    exit;
                }
                $errors[] = 'Could not save the notice.';
            }
        }
    }
}

if (!empty($_GET['saved'])) {
    $messages[] = 'Parents can see this notice now.';
}
if (!empty($_GET['deleted'])) {
    $messages[] = 'Notice removed.';
}

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0 && $tableOk) {
    $row = safe_db_get_one('SELECT * FROM notices WHERE id = :id LIMIT 1', [':id' => $editId]);
    if ($row && $ownsNotice($row)) {
        $editRow = $row;
        if ($col('class_id')) {
            $cid = (int) ($row['class_id'] ?? 0);
            if ($cid > 0 && $canClass($cid)) {
                $selectedClassId = $cid;
            }
        }
    }
}

$classNames = [];
foreach ($assignedClasses as $c) {
    $classNames[(int) $c['id']] = (string) ($c['name'] ?? 'Class');
}

$notices = [];
if ($tableOk && $selectedClassId > 0) {
    $where = ['1=1'];
    $params = [];
    if ($col('class_id')) {
        $where[] = '(class_id = :cid OR class_id IS NULL)';
        $params[':cid'] = $selectedClassId;
    }
    $mine = [];
    if ($col('created_by')) {
        $mine[] = 'created_by = :me';
        $params[':me'] = $teacherId;
    }
    if ($col('published_by')) {
        $mine[] = 'published_by = :me2';
        $params[':me2'] = $teacherId;
    }
    if ($mine !== []) {
        $where[] = '(' . implode(' OR ', $mine) . ')';
    }
    $order = $col('published_at') ? 'COALESCE(published_at, created_at) DESC' : 'id DESC';
    $notices = safe_db_get_all(
        'SELECT * FROM notices WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT 60',
        $params
    ) ?: [];
}

$noticeText = static function (array $n) use ($textCol): string {
    if ($textCol && isset($n[$textCol])) {
        return trim((string) $n[$textCol]);
    }
    foreach (['message', 'body', 'content', 'description'] as $c) {
        if (isset($n[$c]) && trim((string) $n[$c]) !== '') {
            return trim((string) $n[$c]);
        }
    }
    return '';
};

$fmt = static function (?string $d): string {
    $d = substr((string) $d, 0, 10);
    if ($d === '' || $d === '0000-00-00') {
        return '';
    }
    $t = strtotime($d);
    return $t ? date('d M', $t) : $d;
};

$selectedName = $classNames[$selectedClassId] ?? '';

$page_title = 'Class notice';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.nt-chip { display:inline-flex; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.35rem .85rem; text-decoration:none; color:#1e3a5f; font-weight:600; font-size:.9rem; margin:0 .4rem .5rem 0; }
.nt-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.nt-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:10px; }
.nt-title { font-weight:800; color:#1e3a5f; }
.nt-meta { font-size:.85rem; color:#64748b; }
</style>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if ($assignedClasses === []): ?>
  <?php echo panel_teacher_empty_classes_html(); ?>
<?php elseif (!$tableOk): ?>
  <div class="alert alert-warning mb-0">Notices are not set up yet.</div>
<?php else: ?>

  <p class="text-muted mb-2">A short note for <strong>parents</strong> of this class (holiday, bring a bag, picnic). They see it on their Notices page.</p>

  <div class="mb-3">
    <?php foreach ($assignedClasses as $c):
        $cid = (int) $c['id'];
        ?>
      <a class="nt-chip<?php echo $cid === $selectedClassId ? ' active' : ''; ?>" href="?class_id=<?php echo $cid; ?>"><?php echo e((string) ($c['name'] ?? 'Class')); ?></a>
    <?php endforeach; ?>
  </div>

  <form method="post" class="nt-card">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?php echo (int) $editRow['id']; ?>"><?php endif; ?>
    <div class="fw-bold mb-2"><?php echo $editRow ? 'Edit notice' : 'Send a notice for ' . e($selectedName !== '' ? $selectedName : 'this class'); ?></div>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <input class="form-control" name="title" required maxlength="255" placeholder="e.g. Please send a spare dress tomorrow" value="<?php echo e((string) ($editRow['title'] ?? '')); ?>" style="flex:1; min-width:200px">
      <button class="btn btn-success" type="submit"><?php echo $editRow ? 'Save' : 'Send to parents'; ?></button>
      <?php if ($editRow): ?><a class="btn btn-outline-secondary" href="?class_id=<?php echo (int) $selectedClassId; ?>">Cancel</a><?php endif; ?>
    </div>
    <textarea class="form-control" name="message" rows="3" required placeholder="Write the full note here"><?php echo e($editRow ? $noticeText($editRow) : ''); ?></textarea>
  </form>

  <?php if ($notices === []): ?>
    <div class="text-muted">No notices from you for this class yet.</div>
  <?php else: ?>
    <?php foreach ($notices as $n):
        $nid = (int) ($n['id'] ?? 0);
        $when = $fmt((string) ($n['published_at'] ?? $n['created_at'] ?? ''));
        $cid = (int) ($n['class_id'] ?? 0);
        $who = $cid > 0 ? ($classNames[$cid] ?? 'Class') : 'Whole school';
        $canEdit = $ownsNotice($n);
        ?>
      <div class="nt-card">
        <div class="d-flex justify-content-between gap-2 flex-wrap">
          <div>
            <div class="nt-title"><?php echo e((string) ($n['title'] ?? '')); ?></div>
            <div class="nt-meta"><?php echo e($who); ?><?php echo $when !== '' ? ' · ' . e($when) : ''; ?> · Parents can see this</div>
            <?php $body = $noticeText($n); if ($body !== ''): ?>
              <div class="mt-1" style="white-space:pre-wrap"><?php echo e($body); ?></div>
            <?php endif; ?>
          </div>
          <?php if ($canEdit): ?>
            <div class="text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="?class_id=<?php echo (int) $selectedClassId; ?>&amp;edit=<?php echo $nid; ?>">Edit</a>
              <?php echo render_secure_delete_button($nid, 'Delete', 'Remove this notice for parents?', 'btn btn-sm btn-outline-danger', ['class_id' => $selectedClassId]); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
