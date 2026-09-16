<?php
/**
 * Academic Year Hub — rollover wizard, compare, lock & role settings.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$pageTitle = (string) ($cfg['page_title'] ?? 'Academic Year Hub');
$tab = (string) ($_GET['tab'] ?? 'rollover');
$messages = [];
$errors = [];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$classes = table_exists('classes') ? (safe_db_get_all('SELECT id, name, fees FROM classes ORDER BY id ASC') ?: []) : [];
$years = ay_list();
$cfgAy = ay_admin_config();
$promoMap = ay_promotion_map();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $token)) {
        $errors[] = 'Invalid security token. Please retry.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_settings') {
            $locked = [];
            foreach (ay_list() as $y) {
                if (!empty($_POST['lock_' . str_replace('-', '_', $y)])) {
                    $locked[] = $y;
                }
            }
            $roleDefaults = [];
            foreach (['owner', 'accounts', 'reception', 'teacher'] as $role) {
                $val = trim((string) ($_POST['role_default_' . $role] ?? 'current'));
                $roleDefaults[$role] = ($val === 'current' || ay_is_valid($val)) ? $val : 'current';
            }
            $map = [];
            foreach ($classes as $c) {
                $cid = (int) ($c['id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                $raw = $_POST['promote_' . $cid] ?? '';
                if ($raw === '' || $raw === 'graduate') {
                    $map[(string) $cid] = null;
                } else {
                    $map[(string) $cid] = (int) $raw;
                }
            }
            $graduate = [];
            foreach ($classes as $c) {
                $cid = (int) ($c['id'] ?? 0);
                if ($cid > 0 && !empty($_POST['graduate_' . $cid])) {
                    $graduate[] = $cid;
                }
            }
            if (ay_admin_save([
                'locked_years' => $locked,
                'role_defaults' => $roleDefaults,
                'promotion_map' => $map,
                'graduate_class_ids' => $graduate,
            ])) {
                $messages[] = 'Academic year settings saved.';
                $cfgAy = ay_admin_config();
                $promoMap = ay_promotion_map();
            } else {
                $errors[] = 'Failed to save settings.';
            }
            $tab = 'settings';
        }

        if ($action === 'run_rollover') {
            $fromAy = trim((string) ($_POST['from_ay'] ?? ''));
            $toAy = trim((string) ($_POST['to_ay'] ?? ''));
            $continuing = isset($_POST['continuing']) && is_array($_POST['continuing'])
                ? array_map('intval', $_POST['continuing']) : [];
            $lockFrom = !empty($_POST['lock_from_year']);
            $userId = function_exists('auth_user_id') ? (int) (auth_user_id() ?? 0) : 0;

            if (!ay_is_valid($fromAy) || !ay_is_valid($toAy)) {
                $errors[] = 'Select valid academic years.';
            } elseif (ay_next_label($fromAy) !== $toAy) {
                $errors[] = 'Target year must be the next year after source (e.g. 2025-26 → 2026-27).';
            } elseif (empty($continuing)) {
                $errors[] = 'Select at least one student who will continue (re-admit) in the new year.';
            } else {
                $res = ay_execute_rollover($fromAy, $toAy, $continuing, $lockFrom, $userId);
                if (!empty($res['errors'])) {
                    $errors = array_merge($errors, $res['errors']);
                } else {
                    $messages[] = sprintf(
                        'Rollover complete — Promoted: %d, Graduated: %d, Marked alumni: %d.',
                        $res['promoted'],
                        $res['graduated'],
                        $res['alumni']
                    );
                    $_SESSION[AY_SESSION_KEY] = $toAy;
                }
            }
            $tab = 'rollover';
        }
    }
}

$fromAy = trim((string) ($_GET['from_ay'] ?? ''));
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

$compareA = ay_is_valid((string) ($_GET['year_a'] ?? '')) ? (string) $_GET['year_a'] : ($years[1] ?? ay_current());
$compareB = ay_is_valid((string) ($_GET['year_b'] ?? '')) ? (string) $_GET['year_b'] : ($years[0] ?? ay_current());
$statsA = ay_compute_stats($compareA);
$statsB = ay_compute_stats($compareB);

$ownerBase = function_exists('site_url') ? rtrim(site_url('/owner'), '/') : '/owner';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => $ownerBase . '/dashboard.php'],
    ['label' => 'Academic Year Hub'],
];
require_once __DIR__ . '/../header.php';
?>

<ul class="nav nav-tabs mb-4">
  <li class="nav-item"><a class="nav-link<?php echo $tab === 'rollover' ? ' active' : ''; ?>" href="?tab=rollover"><i class="bi bi-arrow-up-circle me-1"></i>Rollover Wizard</a></li>
  <li class="nav-item"><a class="nav-link<?php echo $tab === 'compare' ? ' active' : ''; ?>" href="?tab=compare"><i class="bi bi-columns-gap me-1"></i>Compare Years</a></li>
  <li class="nav-item"><a class="nav-link<?php echo $tab === 'settings' ? ' active' : ''; ?>" href="?tab=settings"><i class="bi bi-gear me-1"></i>Lock &amp; Defaults</a></li>
</ul>

<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if ($tab === 'rollover'): ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card metric-card">
        <div class="card-body">
          <h5 class="fw-bold mb-2"><i class="bi bi-arrow-up-circle text-success me-2"></i>Academic Year Rollover</h5>
          <p class="small text-muted mb-3">फक्त <strong>जे नवीन वर्षात प्रवेश घेतील</strong> (re-admit) तेच students promote होतील. उरलेले active students <em>alumni</em> होतील. नवीन admissions वेगळ्या admission form वरून होतील.</p>

          <form method="get" class="row g-2 mb-3">
            <input type="hidden" name="tab" value="rollover">
            <div class="col-md-5">
              <label class="form-label small">From (closing year)</label>
              <select name="from_ay" class="form-select" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                  <option value="<?php echo e($y); ?>"<?php echo $y === $fromAy ? ' selected' : ''; ?>><?php echo e(ay_display_short($y)); ?><?php echo ay_is_locked($y) ? ' 🔒' : ''; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label small">To (new year)</label>
              <input type="text" class="form-control" value="<?php echo e(ay_display_short($toAy)); ?>" readonly>
            </div>
          </form>

          <?php if (ay_is_locked($fromAy)): ?>
            <div class="alert alert-warning">Source year is locked. Unlock it in Settings to run rollover.</div>
          <?php elseif (empty($candidates)): ?>
            <div class="panel-empty-state"><i class="bi bi-people d-block"></i><p class="mb-0">No active students in <?php echo e(ay_display_short($fromAy)); ?>.</p></div>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
              <input type="hidden" name="action" value="run_rollover">
              <input type="hidden" name="from_ay" value="<?php echo e($fromAy); ?>">
              <input type="hidden" name="to_ay" value="<?php echo e($toAy); ?>">

              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold">Continuing students (<?php echo count($candidates); ?>)</span>
                <div>
                  <button type="button" class="btn btn-sm btn-outline-primary" id="aySelectAll">Select all</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="aySelectNone">Clear</button>
                </div>
              </div>

              <div class="table-responsive border rounded mb-3" style="max-height:420px;overflow:auto">
                <table class="table table-sm table-hover mb-0">
                  <thead class="table-light sticky-top">
                    <tr><th style="width:40px"></th><th>Student</th><th>Class</th><th>Promote to</th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($candidates as $st):
                        $sid = (int) ($st['id'] ?? 0);
                        $cid = (int) ($st['class_id'] ?? 0);
                        $next = $promoMap[(string) $cid] ?? null;
                        $nextName = '—';
                        if ($next) {
                            foreach ($classes as $c) {
                                if ((int) $c['id'] === $next) {
                                    $nextName = (string) $c['name'];
                                    break;
                                }
                            }
                        } else {
                            $nextName = 'Graduate → Alumni';
                        }
                        $name = trim(($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
                    ?>
                      <tr>
                        <td><input type="checkbox" class="form-check-input ay-continue-cb" name="continuing[]" value="<?php echo $sid; ?>" checked></td>
                        <td><?php echo e($name); ?></td>
                        <td><?php echo e((string) ($st['class_name'] ?? '—')); ?></td>
                        <td class="small text-muted"><?php echo e($nextName); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="lock_from_year" id="lockFrom" value="1" checked>
                <label class="form-check-label" for="lockFrom">Lock <?php echo e(ay_display_short($fromAy)); ?> after rollover (recommended)</label>
              </div>

              <button type="submit" class="btn btn-success" onclick="return confirm('Run rollover? Only checked students will be promoted. Others become alumni.');">
                <i class="bi bi-play-fill me-1"></i>Run Rollover → <?php echo e(ay_display_short($toAy)); ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card metric-card mb-3">
        <div class="card-body">
          <h6 class="fw-bold">Year-end Report (PDF)</h6>
          <p class="small text-muted">Collection, pending fees, admissions — one click printable report.</p>
          <a class="btn btn-outline-primary w-100" href="<?php echo e($ownerBase); ?>/year_end_report.php?ay=<?php echo urlencode($fromAy); ?>" target="_blank">
            <i class="bi bi-file-earmark-pdf me-1"></i>Download / Print PDF
          </a>
        </div>
      </div>
      <?php if (!empty($cfgAy['rollover_log'])): ?>
        <div class="card metric-card">
          <div class="card-body">
            <h6 class="fw-bold mb-2">Recent rollovers</h6>
            <ul class="list-group list-group-flush small">
              <?php foreach (array_slice($cfgAy['rollover_log'], 0, 5) as $log): ?>
                <li class="list-group-item px-0">
                  <?php echo e((string) ($log['from'] ?? '')); ?> → <?php echo e((string) ($log['to'] ?? '')); ?>
                  <span class="text-muted d-block"><?php echo e((string) ($log['at'] ?? '')); ?> · P:<?php echo (int) ($log['promoted'] ?? 0); ?> G:<?php echo (int) ($log['graduated'] ?? 0); ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <script>
  document.getElementById('aySelectAll')?.addEventListener('click', () => document.querySelectorAll('.ay-continue-cb').forEach(c => c.checked = true));
  document.getElementById('aySelectNone')?.addEventListener('click', () => document.querySelectorAll('.ay-continue-cb').forEach(c => c.checked = false));
  </script>

<?php elseif ($tab === 'compare'): ?>
  <form method="get" class="row g-2 mb-4">
    <input type="hidden" name="tab" value="compare">
    <div class="col-md-4">
      <label class="form-label">Year A</label>
      <select name="year_a" class="form-select"><?php foreach ($years as $y): ?><option value="<?php echo e($y); ?>"<?php echo $y === $compareA ? ' selected' : ''; ?>><?php echo e(ay_display_short($y)); ?></option><?php endforeach; ?></select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Year B</label>
      <select name="year_b" class="form-select"><?php foreach ($years as $y): ?><option value="<?php echo e($y); ?>"<?php echo $y === $compareB ? ' selected' : ''; ?>><?php echo e(ay_display_short($y)); ?></option><?php endforeach; ?></select>
    </div>
    <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100">Compare</button></div>
  </form>

  <div class="row g-3">
    <?php foreach ([['label' => $compareA, 's' => $statsA], ['label' => $compareB, 's' => $statsB]] as $col): $s = $col['s']; ?>
      <div class="col-md-6">
        <div class="card metric-card h-100 border-primary border-opacity-25">
          <div class="card-body">
            <h5 class="fw-bold text-primary"><?php echo e(ay_display_short($col['label'])); ?></h5>
            <div class="row g-2 mt-2">
              <div class="col-6"><div class="small text-muted">Active Students</div><div class="fs-4 fw-bold"><?php echo (int) $s['active_students']; ?></div></div>
              <div class="col-6"><div class="small text-muted">Total Admissions</div><div class="fs-4 fw-bold"><?php echo (int) $s['admissions']; ?></div></div>
              <div class="col-6"><div class="small text-muted">Fees Collected</div><div class="fs-5 fw-bold text-success"><?php echo e(format_money($s['collected'])); ?></div></div>
              <div class="col-6"><div class="small text-muted">Pending Fees</div><div class="fs-5 fw-bold text-danger"><?php echo e(format_money($s['pending'])); ?></div></div>
              <div class="col-6"><div class="small text-muted">Expenses</div><div class="fs-6"><?php echo e(format_money($s['expenses'])); ?></div></div>
              <div class="col-6"><div class="small text-muted">Enquiries</div><div class="fs-6"><?php echo (int) $s['enquiries']; ?></div></div>
            </div>
            <?php if (!empty($s['by_class'])): ?>
              <hr>
              <div class="small fw-semibold mb-1">By class</div>
              <?php foreach ($s['by_class'] as $bc): ?>
                <div class="d-flex justify-content-between small"><span><?php echo e($bc['name']); ?></span><span><?php echo (int) $bc['count']; ?></span></div>
              <?php endforeach; ?>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-primary mt-3" href="<?php echo e($ownerBase); ?>/year_end_report.php?ay=<?php echo urlencode($col['label']); ?>" target="_blank">Year-end PDF</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php else: /* settings */ ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="save_settings">

    <div class="row g-4">
      <div class="col-lg-6">
        <div class="card metric-card h-100">
          <div class="card-body">
            <h6 class="fw-bold"><i class="bi bi-lock me-1"></i>Lock Past Years</h6>
            <p class="small text-muted">Locked years are read-only — no edit to students, fees or admission.</p>
            <?php foreach ($years as $y): $f = 'lock_' . str_replace('-', '_', $y); ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="<?php echo e($f); ?>" id="<?php echo e($f); ?>" value="1"<?php echo in_array($y, $cfgAy['locked_years'], true) ? ' checked' : ''; ?>>
                <label class="form-check-label" for="<?php echo e($f); ?>"><?php echo e(ay_display_short($y)); ?><?php echo $y === ay_current() ? ' (current)' : ''; ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card metric-card h-100">
          <div class="card-body">
            <h6 class="fw-bold"><i class="bi bi-person-badge me-1"></i>Default Year per Role</h6>
            <p class="small text-muted">जेव्हा user login करतो तेव्हा कोणते Academic Year default दिसेल.</p>
            <?php foreach (['owner' => 'Owner', 'accounts' => 'Accounts', 'reception' => 'Reception', 'teacher' => 'Teacher'] as $rk => $rl): ?>
              <div class="mb-2">
                <label class="form-label small"><?php echo e($rl); ?></label>
                <select name="role_default_<?php echo e($rk); ?>" class="form-select form-select-sm">
                  <option value="current"<?php echo ($cfgAy['role_defaults'][$rk] ?? 'current') === 'current' ? ' selected' : ''; ?>>Current year (auto)</option>
                  <?php foreach ($years as $y): ?>
                    <option value="<?php echo e($y); ?>"<?php echo ($cfgAy['role_defaults'][$rk] ?? '') === $y ? ' selected' : ''; ?>><?php echo e(ay_display_short($y)); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-12">
        <div class="card metric-card">
          <div class="card-body">
            <h6 class="fw-bold"><i class="bi bi-arrow-up-right me-1"></i>Class Promotion Map</h6>
            <p class="small text-muted">Rollover वेळी प्रत्येक class पुढील class मध्ये जाते. शेवटची class → Graduate (Alumni).</p>
            <div class="table-responsive">
              <table class="table table-sm">
                <thead><tr><th>Class</th><th>Promote to</th><th>Graduate class?</th></tr></thead>
                <tbody>
                  <?php foreach ($classes as $c):
                      $cid = (int) ($c['id'] ?? 0);
                      $cur = $promoMap[(string) $cid] ?? null;
                  ?>
                    <tr>
                      <td><?php echo e((string) $c['name']); ?></td>
                      <td>
                        <select name="promote_<?php echo $cid; ?>" class="form-select form-select-sm">
                          <option value="graduate"<?php echo $cur === null ? ' selected' : ''; ?>>— Graduate / Alumni —</option>
                          <?php foreach ($classes as $c2):
                              $tid = (int) ($c2['id'] ?? 0);
                              if ($tid === $cid) continue;
                          ?>
                            <option value="<?php echo $tid; ?>"<?php echo $cur === $tid ? ' selected' : ''; ?>><?php echo e((string) $c2['name']); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td class="text-center">
                        <input type="checkbox" class="form-check-input" name="graduate_<?php echo $cid; ?>" value="1"<?php echo in_array($cid, $cfgAy['graduate_class_ids'], true) ? ' checked' : ''; ?>>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Settings</button>
          </div>
        </div>
      </div>
    </div>
  </form>
<?php endif; ?>

<?php require_once __DIR__ . '/../footer.php'; ?>
