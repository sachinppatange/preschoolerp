<?php
/**
 * School classes: Playgroup / Nursery / LKG / UKG names and ages.
 * Yearly fee amounts are edited on Fees Setup.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$page_title = 'Classes';
$pageTitle = $page_title;

$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$schoolId = function_exists('auth_school_id') ? (int) auth_school_id() : 1;
if ($schoolId < 1) {
    $schoolId = 1;
}
$esc = static fn(string $v): string => e($v);
$feesUrl = function_exists('site_url') ? site_url('/owner/fees_setup.php') : 'fees_setup.php';

$ages = [
    '2-3 Years' => '2–3 years',
    '3-4 Years' => '3–4 years',
    '4-5 Years' => '4–5 years',
    '5-6 Years' => '5–6 years',
];
$defaults = [
    ['Playgroup', '2-3 Years'],
    ['Nursery', '3-4 Years'],
    ['L.K.G.', '4-5 Years'],
    ['U.K.G.', '5-6 Years'],
];

if (!function_exists('table_exists') || !table_exists('classes')) {
    require_once __DIR__ . '/../header.php';
    echo '<div class="alert alert-danger">The classes table was not found.</div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

$col = static function (string $name): bool {
    return function_exists('column_exists') && column_exists('classes', $name);
};

$csrfOk = static function () use ($csrf): bool {
    return function_exists('validate_csrf_token')
        && validate_csrf_token((string) ($_POST['csrf'] ?? $_POST['csrf_token'] ?? ''));
};

$ayStu = function_exists('ay_sql_student') ? ay_sql_student('s') : '1=1';
$ayP = static function (array $extra = []): array {
    return function_exists('ay_params_student') ? ay_params_student($extra) : $extra;
};

$messages = [];
$errors = [];
$action = (string) ($_REQUEST['action'] ?? 'list');

$saveClass = static function (array $data, int $id) use ($col, $schoolId): bool {
    $fields = [];
    if ($col('name')) {
        $fields['name'] = $data['name'];
    }
    if ($col('age_group')) {
        $fields['age_group'] = $data['age_group'] !== '' ? $data['age_group'] : null;
    }
    if ($id <= 0 && $col('school_id')) {
        $fields['school_id'] = $schoolId;
    }
    if ($id <= 0 && $col('fees') && array_key_exists('fees', $data)) {
        $fields['fees'] = $data['fees'];
    }
    if ($id > 0 && $col('updated_at')) {
        $fields['updated_at'] = date('Y-m-d H:i:s');
    }
    if ($fields === []) {
        return false;
    }
    if ($id > 0) {
        $set = [];
        $params = [];
        foreach ($fields as $k => $v) {
            $set[] = $k . ' = :' . $k;
            $params[':' . $k] = $v;
        }
        $params[':id'] = $id;
        return (bool) safe_db_run('UPDATE classes SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
    }
    $cols = [];
    $ph = [];
    $params = [];
    foreach ($fields as $k => $v) {
        $cols[] = $k;
        $ph[] = ':' . $k;
        $params[':' . $k] = $v;
    }
    return (bool) safe_db_run(
        'INSERT INTO classes (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')',
        $params
    );
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$csrfOk()) {
        $errors[] = 'Please reload the page and try again.';
    } elseif ($action === 'add') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $age = trim((string) ($_POST['age_group'] ?? ''));
        if ($age !== '' && !array_key_exists($age, $ages)) {
            $age = '';
        }
        if ($name === '') {
            $errors[] = 'Write the class name.';
        } else {
            $dup = safe_db_get_one('SELECT id FROM classes WHERE name = :n LIMIT 1', [':n' => $name]);
            if ($dup) {
                $errors[] = 'That class is already on the list.';
            } elseif ($saveClass(['name' => $name, 'age_group' => $age, 'fees' => 0], 0)) {
                header('Location: ?added=1');
                exit;
            } else {
                $errors[] = 'Could not add class.';
            }
        }
    } elseif ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $age = trim((string) ($_POST['age_group'] ?? ''));
        if ($age !== '' && !array_key_exists($age, $ages)) {
            $age = '';
        }
        if ($id <= 0 || $name === '') {
            $errors[] = 'Class name is required.';
        } else {
            $dup = safe_db_get_one('SELECT id FROM classes WHERE name = :n AND id <> :id LIMIT 1', [':n' => $name, ':id' => $id]);
            if ($dup) {
                $errors[] = 'Another class already has that name.';
            } elseif ($saveClass(['name' => $name, 'age_group' => $age], $id)) {
                header('Location: ?saved=1');
                exit;
            } else {
                $errors[] = 'Could not save.';
            }
        }
    } elseif ($action === 'defaults') {
        $added = 0;
        foreach ($defaults as $d) {
            $exists = safe_db_get_one('SELECT id FROM classes WHERE name = :n LIMIT 1', [':n' => $d[0]]);
            if ($exists) {
                continue;
            }
            if ($saveClass(['name' => $d[0], 'age_group' => $d[1], 'fees' => 0], 0)) {
                $added++;
            }
        }
        header('Location: ?' . ($added > 0 ? 'added=1' : 'saved=1'));
        exit;
    }
}

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    $errors[] = 'Delete requires confirmation.';
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    $linked = 0;
    if (function_exists('table_exists') && table_exists('students')) {
        $r = safe_db_get_one('SELECT COUNT(*) AS c FROM students WHERE class_id = :id', [':id' => $deleteId]);
        $linked = (int) ($r['c'] ?? 0);
    }
    if ($linked > 0) {
        $errors[] = 'This class has ' . $linked . ' student' . ($linked === 1 ? '' : 's') . '. Move them first, then delete.';
    } elseif (safe_db_run('DELETE FROM classes WHERE id = :id', [':id' => $deleteId])) {
        header('Location: ?deleted=1');
        exit;
    } else {
        $errors[] = 'Could not delete.';
    }
}

if (!empty($_GET['added'])) {
    $messages[] = 'Class added.';
}
if (!empty($_GET['saved'])) {
    $messages[] = 'Saved.';
}
if (!empty($_GET['deleted'])) {
    $messages[] = 'Class removed.';
}

$editId = (int) ($_GET['id'] ?? 0);
$edit = $editId > 0 ? safe_db_get_one('SELECT * FROM classes WHERE id = :id LIMIT 1', [':id' => $editId]) : null;

$order = "CASE
  WHEN c.name LIKE 'Play%' THEN 1
  WHEN c.name LIKE 'Nurs%' THEN 2
  WHEN c.name LIKE 'L%' THEN 3
  WHEN c.name LIKE 'U%' THEN 4
  ELSE 9 END, c.name ASC";

$classes = safe_db_get_all("SELECT c.* FROM classes c ORDER BY {$order}") ?: [];

$counts = [];
if (function_exists('table_exists') && table_exists('students') && $classes !== []) {
    $rows = safe_db_get_all(
        "SELECT s.class_id, COUNT(*) AS c
         FROM students s
         WHERE LOWER(COALESCE(s.status,'active')) IN ('active','pending') AND {$ayStu}
         GROUP BY s.class_id",
        $ayP()
    ) ?: [];
    foreach ($rows as $r) {
        $counts[(int) ($r['class_id'] ?? 0)] = (int) ($r['c'] ?? 0);
    }
}

$ayLabel = function_exists('ay_display_short') ? ay_display_short() : '';
$money = static function ($v): string {
    return function_exists('format_money') ? format_money($v) : ('₹ ' . number_format((float) $v, 0));
};

require_once __DIR__ . '/../header.php';
?>
<style>
.cls-add { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px; margin-bottom:12px; }
.cls-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:10px; }
.cls-card.edit { border-color:#93c5fd; }
.cls-name { font-weight:800; color:#1e3a5f; font-size:1.05rem; }
.cls-meta { font-size:.85rem; color:#64748b; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Classes</h1>
    <p class="text-muted mb-0">Playgroup, Nursery, LKG, UKG. Yearly fees are on <a href="<?php echo $esc($feesUrl); ?>">Fees Setup</a>.</p>
  </div>
</div>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo $esc($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo $esc($er); ?></div><?php endforeach; ?>

<?php if ($classes === []): ?>
  <form method="post" class="mb-3">
    <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
    <input type="hidden" name="action" value="defaults">
    <button class="btn btn-outline-success" type="submit">Add Playgroup, Nursery, L.K.G. and U.K.G.</button>
  </form>
<?php endif; ?>

<form method="post" class="cls-add">
  <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
  <input type="hidden" name="action" value="add">
  <div class="d-flex flex-wrap gap-2">
    <input class="form-control" name="name" required maxlength="80" placeholder="Class name, e.g. Nursery" style="flex:1; min-width:180px">
    <select name="age_group" class="form-select" style="max-width:180px">
      <option value="">Age</option>
      <?php foreach ($ages as $val => $label): ?>
        <option value="<?php echo $esc($val); ?>"><?php echo $esc($label); ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-success" type="submit">Add</button>
  </div>
</form>

<?php if ($classes === []): ?>
  <div class="text-muted">No classes yet. Use the button above or type a name and press Add.</div>
<?php endif; ?>

<?php foreach ($classes as $c):
    $id = (int) $c['id'];
    $isEdit = $edit && (int) $edit['id'] === $id;
    $kids = $counts[$id] ?? 0;
    $age = (string) ($c['age_group'] ?? '');
    $fee = (float) ($c['fees'] ?? 0);
?>
  <div class="cls-card<?php echo $isEdit ? ' edit' : ''; ?>">
    <?php if ($isEdit): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <div class="d-flex flex-wrap gap-2 mb-2">
          <input class="form-control" name="name" required value="<?php echo $esc((string) $c['name']); ?>" style="flex:1; min-width:160px">
          <select name="age_group" class="form-select" style="max-width:180px">
            <option value="">Age</option>
            <?php foreach ($ages as $val => $label): ?>
              <option value="<?php echo $esc($val); ?>" <?php echo $age === $val ? 'selected' : ''; ?>><?php echo $esc($label); ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary" type="submit">Save</button>
          <a class="btn btn-outline-secondary" href="?">Cancel</a>
        </div>
      </form>
    <?php else: ?>
      <div class="d-flex justify-content-between gap-2 flex-wrap">
        <div>
          <div class="cls-name"><?php echo $esc((string) $c['name']); ?></div>
          <div class="cls-meta">
            <?php echo $age !== '' ? $esc($ages[$age] ?? $age) : 'Age not set'; ?>
            · <?php echo (int) $kids; ?> child<?php echo $kids === 1 ? '' : 'ren'; ?> <?php echo $esc($ayLabel); ?>
            <?php if ($col('fees')): ?> · <?php echo $esc($money($fee)); ?>/year<?php endif; ?>
          </div>
        </div>
        <div class="d-flex gap-1 flex-wrap">
          <a class="btn btn-sm btn-outline-primary" href="?id=<?php echo $id; ?>">Edit</a>
          <?php echo render_secure_delete_button($id, '×', 'Remove this class?', 'btn btn-sm btn-link text-danger text-decoration-none'); ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../footer.php'; ?>
