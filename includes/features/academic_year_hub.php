<?php
/**
 * New school year: next-class map + move continuing children (June–May).
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$pageTitle = (string) ($cfg['page_title'] ?? 'New school year');
$page_title = $pageTitle;
$messages = [];
$errors = [];

if (file_exists(__DIR__ . '/../csrf.php')) {
    require_once __DIR__ . '/../csrf.php';
}
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

$classes = table_exists('classes') ? (safe_db_get_all('SELECT id, name, fees FROM classes ORDER BY id ASC') ?: []) : [];
$years = ay_list();
$cfgAy = ay_admin_config();
$promoMap = ay_promotion_map();

$classNameById = [];
foreach ($classes as $c) {
    $classNameById[(int) ($c['id'] ?? 0)] = (string) ($c['name'] ?? '');
}

$csrfOk = static function () use ($csrf): bool {
    $token = (string) ($_POST['csrf'] ?? $_POST['csrf_token'] ?? '');
    if (function_exists('validate_csrf_token')) {
        return validate_csrf_token($token);
    }

    return $csrf !== '' && hash_equals($csrf, $token);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$csrfOk()) {
        $errors[] = 'Please reload the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_settings') {
            $locked = [];
            foreach (ay_list() as $y) {
                if (!empty($_POST['lock_' . str_replace('-', '_', $y)])) {
                    $locked[] = $y;
                }
            }
            $map = [];
            $graduate = [];
            foreach ($classes as $c) {
                $cid = (int) ($c['id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                $raw = (string) ($_POST['promote_' . $cid] ?? 'graduate');
                if ($raw === '' || $raw === 'graduate') {
                    $map[(string) $cid] = null;
                    $graduate[] = $cid;
                } else {
                    $map[(string) $cid] = (int) $raw;
                }
            }
            if (ay_admin_save([
                'locked_years' => $locked,
                'promotion_map' => $map,
                'graduate_class_ids' => $graduate,
            ])) {
                $messages[] = 'Saved. Next year, children will move as shown below.';
                $cfgAy = ay_admin_config();
                $promoMap = ay_promotion_map();
            } else {
                $errors[] = 'Could not save. Try again.';
            }
        }

        if ($action === 'run_rollover') {
            $fromAy = trim((string) ($_POST['from_ay'] ?? ''));
            $toAy = trim((string) ($_POST['to_ay'] ?? ''));
            $continuing = isset($_POST['continuing']) && is_array($_POST['continuing'])
                ? array_map('intval', $_POST['continuing']) : [];
            $lockFrom = !empty($_POST['lock_from_year']);
            $userId = function_exists('auth_user_id') ? (int) (auth_user_id() ?? 0) : 0;

            if (!ay_is_valid($fromAy) || !ay_is_valid($toAy)) {
                $errors[] = 'Pick a valid school year.';
            } elseif (ay_next_label($fromAy) !== $toAy) {
                $errors[] = 'The next year must follow this one (example: 2025-26 → 2026-27).';
            } elseif ($continuing === []) {
                $errors[] = 'Tick the children who will come next year.';
            } else {
                $res = ay_execute_rollover($fromAy, $toAy, $continuing, $lockFrom, $userId);
                if (!empty($res['errors'])) {
                    $errors = array_merge($errors, $res['errors']);
                } else {
                    $messages[] = sprintf(
                        'Done. %d children moved to the next class. %d finished school. %d were marked as left.',
                        (int) ($res['promoted'] ?? 0),
                        (int) ($res['graduated'] ?? 0),
                        (int) ($res['alumni'] ?? 0)
                    );
                    $_SESSION[AY_SESSION_KEY] = $toAy;
                    $cfgAy = ay_admin_config();
                }
            }
        }
    }
}

$fromAy = trim((string) ($_GET['from_ay'] ?? $_POST['from_ay'] ?? ''));
if (!ay_is_valid($fromAy)) {
    $fromAy = '';
    foreach (ay_list() as $y) {
        if (ay_next_label($y) === ay_current()) {
            $fromAy = $y;
            break;
        }
    }
    if ($fromAy === '') {
        $list = ay_list();
        $fromAy = $list[1] ?? ($list[0] ?? ay_current());
    }
}
$toAy = ay_next_label($fromAy) ?? ay_current();
$candidates = ay_rollover_candidates($fromAy);

$ownerBase = function_exists('site_url') ? rtrim(site_url('/owner'), '/') : '/owner';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => $ownerBase . '/dashboard.php'],
    ['label' => 'New school year'],
];
require_once __DIR__ . '/../header.php';

$nextLabel = static function (int $cid) use ($promoMap, $classNameById): string {
    $next = $promoMap[(string) $cid] ?? null;
    if ($next) {
        return $classNameById[$next] ?? 'Next class';
    }

    return 'Finished school';
};
?>

<p class="text-muted mb-4">School year is <strong>June to May</strong>. Use this page only when the year is over and children move up. New children still join from Admission — not from here.</p>

<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

<div class="card metric-card mb-4">
  <div class="card-body">
    <h2 class="h5 fw-bold mb-1">1. Where does each class go?</h2>
    <p class="small text-muted mb-3">Example: Nursery → LKG → UKG → finished school. Save this once. Then tick children below.</p>
    <?php if ($classes === []): ?>
      <p class="mb-0">Add classes first in <a href="<?php echo e($ownerBase); ?>/class_setup.php">Class setup</a>.</p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="save_settings">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-3">
            <thead><tr><th>This class</th><th>Next year they go to</th></tr></thead>
            <tbody>
              <?php foreach ($classes as $c):
                  $cid = (int) ($c['id'] ?? 0);
                  $cur = $promoMap[(string) $cid] ?? null;
                  ?>
                <tr>
                  <td class="fw-semibold"><?php echo e((string) $c['name']); ?></td>
                  <td>
                    <select name="promote_<?php echo $cid; ?>" class="form-select">
                      <option value="graduate"<?php echo $cur === null ? ' selected' : ''; ?>>Finished school (left)</option>
                      <?php foreach ($classes as $c2):
                          $tid = (int) ($c2['id'] ?? 0);
                          if ($tid === $cid) {
                              continue;
                          }
                          ?>
                        <option value="<?php echo $tid; ?>"<?php echo $cur === $tid ? ' selected' : ''; ?>><?php echo e((string) $c2['name']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <h3 class="h6 fw-bold mb-2">Stop changes in old years</h3>
        <p class="small text-muted">Tick a year so fees and student records cannot be edited. Leave the current year unticked.</p>
        <div class="d-flex flex-wrap gap-3 mb-3">
          <?php foreach ($years as $y): $f = 'lock_' . str_replace('-', '_', $y); ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="<?php echo e($f); ?>" id="<?php echo e($f); ?>" value="1"<?php echo in_array($y, $cfgAy['locked_years'], true) ? ' checked' : ''; ?>>
              <label class="form-check-label" for="<?php echo e($f); ?>"><?php echo e(ay_display_short($y)); ?><?php echo $y === ay_current() ? ' (this year)' : ''; ?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary">Save class path</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card metric-card mb-4">
  <div class="card-body">
    <h2 class="h5 fw-bold mb-1">2. Move children to the next year</h2>
    <p class="small text-muted mb-3">Tick who will study next year. Untick who left. Ticked children go to the next class above. Unticked children are marked as left.</p>

    <form method="get" class="row g-2 mb-3">
      <div class="col-md-5">
        <label class="form-label">Year that is finishing</label>
        <select name="from_ay" class="form-select" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
            <option value="<?php echo e($y); ?>"<?php echo $y === $fromAy ? ' selected' : ''; ?>><?php echo e(ay_display_short($y)); ?><?php echo ay_is_locked($y) ? ' (locked)' : ''; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">They move into</label>
        <input type="text" class="form-control" value="<?php echo e(ay_display_short($toAy)); ?>" readonly>
      </div>
    </form>

    <?php if (ay_is_locked($fromAy)): ?>
      <div class="alert alert-warning mb-0">That year is locked. Untick it in step 1, save, then try again.</div>
    <?php elseif ($candidates === []): ?>
      <p class="mb-0 text-muted">No children in <?php echo e(ay_display_short($fromAy)); ?>.</p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="run_rollover">
        <input type="hidden" name="from_ay" value="<?php echo e($fromAy); ?>">
        <input type="hidden" name="to_ay" value="<?php echo e($toAy); ?>">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <span class="fw-semibold"><?php echo count($candidates); ?> children</span>
          <div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="aySelectAll">Tick all</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="aySelectNone">Untick all</button>
          </div>
        </div>

        <div class="table-responsive border rounded mb-3" style="max-height:420px;overflow:auto">
          <table class="table table-sm table-hover mb-0">
            <thead class="table-light sticky-top">
              <tr>
                <th style="width:44px">Stay?</th>
                <th>Child</th>
                <th>Now</th>
                <th>Next year</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($candidates as $st):
                  $sid = (int) ($st['id'] ?? 0);
                  $cid = (int) ($st['class_id'] ?? 0);
                  $name = trim(($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
                  ?>
                <tr>
                  <td><input type="checkbox" class="form-check-input ay-continue-cb" name="continuing[]" value="<?php echo $sid; ?>" checked></td>
                  <td><?php echo e($name); ?></td>
                  <td><?php echo e((string) ($st['class_name'] ?? '—')); ?></td>
                  <td class="small text-muted"><?php echo e($nextLabel($cid)); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="lock_from_year" id="lockFrom" value="1" checked>
          <label class="form-check-label" for="lockFrom">After this, lock <?php echo e(ay_display_short($fromAy)); ?> so old records are not changed</label>
        </div>

        <button type="submit" class="btn btn-success" onclick="return confirm('Move ticked children to <?php echo e(ay_display_short($toAy)); ?>? Unticked children will be marked as left.');">
          Move to <?php echo e(ay_display_short($toAy)); ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($cfgAy['rollover_log'])): ?>
  <div class="card metric-card mb-4">
    <div class="card-body">
      <h2 class="h6 fw-bold mb-2">Last times you did this</h2>
      <ul class="list-unstyled small mb-0">
        <?php foreach (array_slice($cfgAy['rollover_log'], 0, 5) as $log): ?>
          <li class="mb-2">
            <?php echo e(ay_display_short((string) ($log['from'] ?? ''))); ?>
            →
            <?php echo e(ay_display_short((string) ($log['to'] ?? ''))); ?>
            <span class="text-muted">
              · moved <?php echo (int) ($log['promoted'] ?? 0); ?>
              · finished <?php echo (int) ($log['graduated'] ?? 0); ?>
              <?php if (!empty($log['at'])): ?> · <?php echo e((string) $log['at']); ?><?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<p class="small text-muted mb-0">Need numbers for a year? Use <a href="<?php echo e($ownerBase); ?>/year_end_report.php?ay=<?php echo urlencode($fromAy); ?>">Year summary</a>.</p>

<script>
document.getElementById('aySelectAll')?.addEventListener('click', () => document.querySelectorAll('.ay-continue-cb').forEach(c => c.checked = true));
document.getElementById('aySelectNone')?.addEventListener('click', () => document.querySelectorAll('.ay-continue-cb').forEach(c => c.checked = false));
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
