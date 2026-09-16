<?php
/**
 * owner/reports_simple.php
 *
 * Comprehensive reports page for owner/admin.
 * - Uses project includes when available (includes/config.php, includes/db.php, includes/functions.php)
 * - Reports drawn from complaints and feedbacks tables
 * - Filters: date range, search, source, status/type, granularity (daily/weekly/monthly/yearly)
 * - Sections: totals, time-series, complaints by status, feedbacks by type, top sources, top reporters,
 *   recent combined records, keyword summary
 * - Export per-section CSV via GET?action=export_section&section=<name>
 *
 * Expected tables:
 *  - complaints (id, name, phone, email, message, source, created_at, status, updated_by, updated_at)
 *  - feedbacks  (id, name, phone, email, message, type, source, created_at)
 *
 * Place at: /pioneerplayschool01/owner/reports_simple.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

/* optional project includes */
/* fallback stub for static analysis */
if (!function_exists('db_connect')) {
    function db_connect() { return null; }
}

/* simple auth guard (adapt to your project auth) */
/* debug flag - set DEV_SHOW_ERRORS = true in includes/config.php to see exceptions */
if (!function_exists('db_get_one')) {
    function db_get_one(string $sql, array $params = []) {
        if (is_callable('db_fetch_one')) {
            try { return call_user_func('db_fetch_one', $sql, $params); } catch (Throwable $e) {}
        }
        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) return null;
        try { $stmt = $pdo->prepare($sql); $stmt->execute($params); $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row === false ? null : $row; }
        catch (Throwable $e) { if ($GLOBALS['DEBUG'] ?? false) error_log('db_get_one: ' . $e->getMessage() . ' SQL:' . $sql); return null; }
    }
}
if (!function_exists('db_get_all')) {
    function db_get_all(string $sql, array $params = []): array {
        if (is_callable('db_fetch_all')) {
            try { return call_user_func('db_fetch_all', $sql, $params) ?: []; } catch (Throwable $e) {}
        }
        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) return [];
        try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { if ($GLOBALS['DEBUG'] ?? false) error_log('db_get_all: ' . $e->getMessage() . ' SQL:' . $sql); return []; }
    }
}
if (!function_exists('db_run')) {
    function db_run(string $sql, array $params = []): bool {
        if (is_callable('db_execute')) {
            try { return (bool) call_user_func('db_execute', $sql, $params); } catch (Throwable $e) {}
        }
        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) return false;
        try { $stmt = $pdo->prepare($sql); return (bool)$stmt->execute($params); }
        catch (Throwable $e) { if ($GLOBALS['DEBUG'] ?? false) error_log('db_run: ' . $e->getMessage() . ' SQL:' . $sql); return false; }
    }
}

/* small helpers */

function table_exists(string $name): bool {
    $r = db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
    return !empty($r['cnt']);
}

/* Ensure base tables exist */
if (!table_exists('complaints') && !table_exists('feedbacks')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<div class="container py-5"><div class="alert alert-danger">Required tables <strong>complaints</strong> and/or <strong>feedbacks</strong> are missing. Please create them.</div></div>';
    exit;
}

/* ----------------------------
   Inputs / Filters
   ---------------------------- */
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));
$q    = trim((string)($_GET['q'] ?? ''));
$gran = trim((string)($_GET['gran'] ?? 'daily')); // daily, weekly, monthly, yearly
$sourceFilter = trim((string)($_GET['source'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? ''));

if ($from === '') $from = date('Y-m-d', strtotime('-29 days'));
if ($to === '')   $to   = date('Y-m-d');

$from_ts = $from . ' 00:00:00';
$to_ts   = $to . ' 23:59:59';

/* validate simple dates */
if (!DateTime::createFromFormat('Y-m-d', $from) || !DateTime::createFromFormat('Y-m-d', $to)) {
    $errors[] = 'Invalid date format (use YYYY-MM-DD).';
}

/* build where clauses */
$complWhere = ['created_at >= :from', 'created_at <= :to'];
$complParams = [':from'=>$from_ts, ':to'=>$to_ts];
if ($q !== '') { $complWhere[] = '(name LIKE :q OR phone LIKE :q OR email LIKE :q OR message LIKE :q)'; $complParams[':q']='%'.$q.'%'; }
if ($sourceFilter !== '') { $complWhere[] = 'source = :source'; $complParams[':source'] = $sourceFilter; }
if ($statusFilter !== '') { $complWhere[] = 'status = :status'; $complParams[':status'] = $statusFilter; }
$complWhereSql = implode(' AND ', $complWhere);

$feedWhere = ['created_at >= :from', 'created_at <= :to'];
$feedParams = [':from'=>$from_ts, ':to'=>$to_ts];
if ($q !== '') { $feedWhere[] = '(name LIKE :q OR phone LIKE :q OR email LIKE :q OR message LIKE :q)'; $feedParams[':q']='%'.$q.'%'; }
if ($sourceFilter !== '') { $feedWhere[] = 'source = :source'; $feedParams[':source'] = $sourceFilter; }
if ($typeFilter !== '') { $feedWhere[] = 'type = :type'; $feedParams[':type'] = $typeFilter; }
$feedWhereSql = implode(' AND ', $feedWhere);

/* ----------------------------
   Aggregates and breakdowns
   ---------------------------- */
$totals = ['complaints' => 0, 'feedbacks' => 0];
try {
    $c = db_get_one("SELECT COUNT(*) AS c FROM complaints WHERE $complWhereSql", $complParams);
    $f = db_get_one("SELECT COUNT(*) AS c FROM feedbacks WHERE $feedWhereSql", $feedParams);
    $totals['complaints'] = intval($c['c'] ?? 0);
    $totals['feedbacks']  = intval($f['c'] ?? 0);
} catch (Throwable $e) {
    $errors[] = 'Failed to fetch totals.'; if ($DEBUG) $errors[] = $e->getMessage();
}

$complaints_by_status = db_get_all("SELECT status, COUNT(*) AS cnt FROM complaints WHERE $complWhereSql GROUP BY status", $complParams);
$feedbacks_by_type = db_get_all("SELECT type, COUNT(*) AS cnt FROM feedbacks WHERE $feedWhereSql GROUP BY type", $feedParams);

/* top sources combined */
$top_sources = [];
try {
    $sql = "
      SELECT source, SUM(cnt) AS total FROM (
        SELECT COALESCE(source,'(none)') AS source, COUNT(*) AS cnt FROM complaints WHERE $complWhereSql GROUP BY source
        UNION ALL
        SELECT COALESCE(source,'(none)') AS source, COUNT(*) AS cnt FROM feedbacks WHERE $feedWhereSql GROUP BY source
      ) x
      GROUP BY source
      ORDER BY total DESC
      LIMIT 10
    ";
    $top_sources = db_get_all($sql, array_merge($complParams, $feedParams));
} catch (Throwable $e) { if ($DEBUG) error_log('top_sources: '.$e->getMessage()); }

/* top reporters */
$top_reporters = db_get_all("SELECT name, COUNT(*) AS cnt FROM (SELECT name FROM complaints WHERE $complWhereSql UNION ALL SELECT name FROM feedbacks WHERE $feedWhereSql) x GROUP BY name ORDER BY cnt DESC LIMIT 10", array_merge($complParams, $feedParams));

/* recent combined */
$recent = db_get_all("(SELECT id,'complaint' AS kind,name,phone,email,message,source,created_at FROM complaints WHERE $complWhereSql)
                      UNION ALL
                      (SELECT id,'feedback' AS kind,name,phone,email,message,source,created_at FROM feedbacks WHERE $feedWhereSql)
                      ORDER BY created_at DESC LIMIT 100", array_merge($complParams, $feedParams));

/* keywords (simple) */
$keyword_top = [];
try {
    $msgs = db_get_all("SELECT message FROM complaints WHERE $complWhereSql UNION ALL SELECT message FROM feedbacks WHERE $feedWhereSql LIMIT 500", array_merge($complParams, $feedParams));
    $wc = [];
    foreach ($msgs as $m) {
        $txt = strtolower(strip_tags($m['message'] ?? ''));
        $words = preg_split('/[^a-z0-9]+/i', $txt);
        foreach ($words as $w) {
            $w = trim($w);
            if ($w === '' || strlen($w) < 3) continue;
            if (in_array($w, ['the','and','for','with','this','that','your','from','have','been','not','are','but','you'])) continue;
            $wc[$w] = ($wc[$w] ?? 0) + 1;
        }
    }
    arsort($wc);
    $keyword_top = array_slice($wc, 0, 20, true);
} catch (Throwable $e) { if ($DEBUG) error_log('keywords: '.$e->getMessage()); }

/* ----------------------------
   Time-series generation (per granularity)
   ---------------------------- */
$series = ['labels'=>[], 'complaints'=>[], 'feedbacks'=>[]];
try {
    $start = new DateTime($from);
    $end = new DateTime($to);
    $interval_spec = 'P1D';
    $fmt = 'Y-m-d';
    if ($gran === 'weekly') { $interval_spec = 'P7D'; $fmt = 'o-\WW'; } // ISO week-year
    if ($gran === 'monthly') { $interval_spec = 'P1M'; $fmt = 'Y-m'; }
    if ($gran === 'yearly') { $interval_spec = 'P1Y'; $fmt = 'Y'; }

    $interval = new DateInterval($interval_spec);
    $d = clone $start;
    while ($d <= $end) {
        $label = $d->format($fmt);
        $start_bin = $d->format('Y-m-d 00:00:00');
        // compute bin end
        $end_bin_dt = (clone $d)->add($interval)->sub(new DateInterval('PT1S'));
        $end_bin = $end_bin_dt->format('Y-m-d 23:59:59');

        $c = db_get_one("SELECT COUNT(*) AS c FROM complaints WHERE created_at BETWEEN :s AND :e" . ($q ? " AND (name LIKE :q OR phone LIKE :q OR email LIKE :q OR message LIKE :q)" : ""), array_merge([':s'=>$start_bin,':e'=>$end_bin], $q?[':q'=>'%'.$q.'%']:[]));
        $f = db_get_one("SELECT COUNT(*) AS c FROM feedbacks WHERE created_at BETWEEN :s AND :e" . ($q ? " AND (name LIKE :q OR phone LIKE :q OR email LIKE :q OR message LIKE :q)" : ""), array_merge([':s'=>$start_bin,':e'=>$end_bin], $q?[':q'=>'%'.$q.'%']:[]));

        $series['labels'][] = $label;
        $series['complaints'][] = intval($c['c'] ?? 0);
        $series['feedbacks'][] = intval($f['c'] ?? 0);

        $d->add($interval);
    }
} catch (Throwable $e) { if ($DEBUG) error_log('series: '.$e->getMessage()); }

/* ----------------------------
   Per-section CSV export
   ---------------------------- */
if (($act = $_GET['action'] ?? '') === 'export_section') {
    $section = $_GET['section'] ?? '';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reports_' . preg_replace('/[^a-z0-9_]+/i','_', $section) . '_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');

    if ($section === 'totals') {
        fputcsv($out, ['Metric','Value']);
        fputcsv($out, ['Complaints', $totals['complaints']]);
        fputcsv($out, ['Feedbacks', $totals['feedbacks']]);
    } elseif ($section === 'by_status') {
        fputcsv($out, ['Status','Count']);
        foreach ($complaints_by_status as $r) fputcsv($out, [$r['status'] ?? '(none)', $r['cnt']]);
    } elseif ($section === 'by_type') {
        fputcsv($out, ['Type','Count']);
        foreach ($feedbacks_by_type as $r) fputcsv($out, [$r['type'] ?? '(none)', $r['cnt']]);
    } elseif ($section === 'top_sources') {
        fputcsv($out, ['Source','Total']);
        foreach ($top_sources as $r) fputcsv($out, [$r['source'], $r['total']]);
    } elseif ($section === 'series') {
        fputcsv($out, ['Label','Complaints','Feedbacks']);
        for ($i=0;$i<count($series['labels']);$i++) fputcsv($out, [$series['labels'][$i], $series['complaints'][$i], $series['feedbacks'][$i]]);
    } elseif ($section === 'recent') {
        fputcsv($out, ['Kind','ID','Name','Phone','Email','Source','Created At','Message']);
        foreach ($recent as $r) fputcsv($out, [$r['kind'],$r['id'],$r['name'],$r['phone'],$r['email'],$r['source'],$r['created_at'], preg_replace("/\r\n|\r|\n/"," ", $r['message'])]);
    } elseif ($section === 'keywords') {
        fputcsv($out, ['Keyword','Count']);
        foreach ($keyword_top as $k=>$v) fputcsv($out, [$k,$v]);
    } else {
        fputcsv($out, ['error','unknown section']);
    }

    fclose($out);
    exit;
}

/* ----------------------------
   Render HTML
   ---------------------------- */
$pageTitle = 'Reports (उभा) - Full';
require_once __DIR__ . '/../includes/header.php';
?>

  <?php if (!empty($errors)): foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?php echo e($err); ?></div>
  <?php endforeach; endif; ?>

  <!-- Filters -->
  <div class="card report-section p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-2"><label class="form-label">From</label><input name="from" type="date" class="form-control" value="<?php echo e($from); ?>"></div>
      <div class="col-md-2"><label class="form-label">To</label><input name="to" type="date" class="form-control" value="<?php echo e($to); ?>"></div>
      <div class="col-md-2"><label class="form-label">Granularity</label>
        <select name="gran" class="form-select">
          <option value="daily" <?php if($gran==='daily') echo 'selected'; ?>>Daily</option>
          <option value="weekly" <?php if($gran==='weekly') echo 'selected'; ?>>Weekly</option>
          <option value="monthly" <?php if($gran==='monthly') echo 'selected'; ?>>Monthly</option>
          <option value="yearly" <?php if($gran==='yearly') echo 'selected'; ?>>Yearly</option>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">Source</label><input name="source" class="form-control" value="<?php echo e($sourceFilter); ?>"></div>
      <div class="col-md-2"><label class="form-label">Status/Type</label><input name="status" class="form-control" value="<?php echo e($statusFilter ?: $typeFilter); ?>"></div>
      <div class="col-md-2"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="name/phone/email/message"></div>
      <div class="col-12 text-end"><button class="btn btn-primary">Apply</button></div>
    </form>
  </div>

  <!-- Metrics -->
  <div class="report-section">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5>Quick metrics</h5>
      <div>
        <a class="btn btn-sm btn-outline-primary" href="?action=export_section&section=totals&<?php echo http_build_query($_GET); ?>">Export Totals</a>
      </div>
    </div>
    <div class="metrics-grid mb-2">
      <div class="metric">
        <small class="small-muted">Complaints</small>
        <h2><?php echo e(number_format($totals['complaints'])); ?></h2>
        <div class="small-muted">Range: <?php echo e($from); ?> → <?php echo e($to); ?></div>
      </div>
      <div class="metric">
        <small class="small-muted">Feedbacks</small>
        <h2><?php echo e(number_format($totals['feedbacks'])); ?></h2>
      </div>
      <div class="metric">
        <small class="small-muted">Top Source</small>
        <h2><?php echo e(!empty($top_sources[0]['source']) ? e($top_sources[0]['source']) : '—'); ?></h2>
        <div class="small-muted"><?php echo e(!empty($top_sources[0]['total']) ? (int)$top_sources[0]['total'] : '0'); ?></div>
      </div>
      <div class="metric">
        <small class="small-muted">Top Reporter</small>
        <h2><?php echo e(!empty($top_reporters[0]['name']) ? e($top_reporters[0]['name']) : '—'); ?></h2>
        <div class="small-muted"><?php echo e(!empty($top_reporters[0]['cnt']) ? (int)$top_reporters[0]['cnt'] : '0'); ?></div>
      </div>
    </div>
  </div>

  <!-- Time-series chart -->
  <div class="report-section card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5>Time series (<?php echo e(ucfirst($gran)); ?>)</h5>
      <a class="btn btn-sm btn-outline-primary" href="?action=export_section&section=series&<?php echo http_build_query($_GET); ?>">Export Series</a>
    </div>
    <canvas id="tsChart" height="120"></canvas>
    <script>
      (function(){
        const labels = <?php echo json_encode($series['labels']); ?>;
        const dataC = <?php echo json_encode($series['complaints']); ?>;
        const dataF = <?php echo json_encode($series['feedbacks']); ?>;
        const ctx = document.getElementById('tsChart').getContext('2d');
        new Chart(ctx, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [
              { label: 'Complaints', data: dataC, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.08)', tension:0.25 },
              { label: 'Feedbacks', data: dataF, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.08)', tension:0.25 }
            ]
          },
          options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true } }
          }
        });
      })();
    </script>
  </div>

  <div class="row">
    <div class="col-md-6 report-section">
      <div class="card p-3">
        <div class="d-flex justify-content-between">
          <h5>Complaints by status</h5>
          <a class="btn btn-sm btn-outline-primary" href="?action=export_section&section=by_status&<?php echo http_build_query($_GET); ?>">Export</a>
        </div>
        <table class="table table-sm mt-2">
          <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
          <tbody>
            <?php if (!empty($complaints_by_status)): foreach ($complaints_by_status as $r): ?>
              <tr><td><?php echo e($r['status'] ?: '(none)'); ?></td><td class="text-end"><?php echo e((int)$r['cnt']); ?></td></tr>
            <?php endforeach; else: ?>
              <tr><td colspan="2" class="text-muted">No data</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="col-md-6 report-section">
      <div class="card p-3">
        <div class="d-flex justify-content-between">
          <h5>Feedbacks by type</h5>
          <a class="btn btn-sm btn-outline-primary" href="?action=export_section&section=by_type&<?php echo http_build_query($_GET); ?>">Export</a>
        </div>
        <table class="table table-sm mt-2">
          <thead><tr><th>Type</th><th class="text-end">Count</th></tr></thead>
          <tbody>
            <?php if (!empty($feedbacks_by_type)): foreach ($feedbacks_by_type as $r): ?>
              <tr><td><?php echo e($r['type'] ?: '(none)'); ?></td><td class="text-end"><?php echo e((int)$r['cnt']); ?></td></tr>
            <?php endforeach; else: ?>
              <tr><td colspan="2" class="text-muted">No data</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="report-section card p-3">
    <div class="d-flex justify-content-between">
      <h5>Top sources (combined)</h5>
      <a class="btn btn-sm btn-outline-primary" href="?action=export_section&section=top_sources&<?php echo http_build_query($_GET); ?>">Export</a>
    </div>
    <table class="table table-sm mt-2">
      <thead><tr><th>Source</th><th class="text-end">Total</th></tr></thead>
      <tbody>
        <?php if (!empty($top_sources)): foreach ($top_sources as $r): ?>
          <tr><td><?php echo e($r['source'] ?: '(none)'); ?></td><td class="text-end"><?php echo e((int)$r['total']); ?></td></tr>
        <?php endforeach; else: ?>
          <tr><td colspan="2" class="text-muted">No sources</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="report-section card p-3">
    <div class="d-flex justify-content-between">
      <h5>Top keywords (simple)</h5>
      <a class="btn btn-sm btn-outline-primary" href="?action=export_section&section=keywords&<?php echo http_build_query($_GET); ?>">Export</a>
    </div>
    <div class="mt-2">
      <?php if (!empty($keyword_top)): ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
          <?php foreach ($keyword_top as $k=>$v): ?>
            <div class="badge bg-light text-dark border"><?php echo e($k); ?> &middot; <?php echo e($v); ?></div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="text-muted">No keywords</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="report-section card p-3">
    <div class="d-flex justify-content-between">
      <h5>Recent combined records (latest 100)</h5>
      <a class="btn btn-sm btn-outline-primary" href="?action=export_section&section=recent&<?php echo http_build_query($_GET); ?>">Export Recent</a>
    </div>
    <div style="max-height:360px; overflow:auto" class="mt-2">
      <table class="table table-sm mb-0">
        <thead><tr><th>Kind</th><th>ID</th><th>Name</th><th>Contact</th><th>Source</th><th>Created At</th></tr></thead>
        <tbody>
          <?php if (!empty($recent)): foreach ($recent as $r): ?>
            <tr>
              <td><?php echo e(ucfirst($r['kind'])); ?></td>
              <td><?php echo e((int)$r['id']); ?></td>
              <td><?php echo e($r['name']); ?></td>
              <td class="small text-muted"><?php echo e(($r['phone'] ?? '') . ($r['email'] ? ' • ' . $r['email'] : '')); ?></td>
              <td><?php echo e($r['source'] ?? ''); ?></td>
              <td><?php echo e($r['created_at'] ?? ''); ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-muted">No recent records</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="text-muted small mt-3">
    Note: Exports reflect current filters. For large datasets consider running aggregated queries directly in your database.
  </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
