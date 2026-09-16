<?php
/**
 * owner/dashboard.php — Professional analytics dashboard (reference-style).
 * Layout: Graphs first → Cards & tables → A.Y. compare strip.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

$ownerBase = function_exists('site_url') ? rtrim(site_url('/owner'), '/') : '/owner';
$view = ($_GET['view'] ?? 'analytics') === 'quicklinks' ? 'quicklinks' : 'analytics';
$skip_panel_ay_banner = true;

$hasStudents = table_exists('students');
$hasFees = table_exists('fees_records');
$hasEnquiries = table_exists('enquiries');
$hasClasses = table_exists('classes');
$hasExpenses = table_exists('expenses');

$ay = ay_selected();
$prevAy = ay_previous($ay);
$ayParams = [':panel_ay' => $ay];
$ayRange = ay_range();
$ayDateParams = [
    ':panel_ay_start' => $ayRange['start'],
    ':panel_ay_end' => $ayRange['end'] . ' 23:59:59',
];
$hasAyCol = ay_students_have_column();
$feesAyJoin = $hasAyCol ? ' INNER JOIN students ay_s ON ay_s.id = fr.student_id AND ay_s.academic_year = :panel_ay ' : '';
$prevFeesAyJoin = ($hasAyCol && $prevAy) ? ' INNER JOIN students ay_s ON ay_s.id = fr.student_id AND ay_s.academic_year = :prev_ay ' : '';
$lastUpdated = date('d-m-Y h:i A');
$monthLabels = ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'May'];

function dash_pct_change(float $cur, float $prev): array
{
    if ($prev <= 0.01) {
        return ['text' => '—', 'class' => ''];
    }
    $p = (($cur - $prev) / $prev) * 100;

    return [
        'text' => ($p >= 0 ? '+' : '') . number_format($p, 1) . '%',
        'class' => $p >= 0 ? 'up' : 'down',
    ];
}

function dash_url(string $view): string
{
    return '?view=' . urlencode($view);
}

/** Map YYYY-MM rows to 12-month A.Y. index (Jun=0 … May=11). */
function dash_align_ay_months(array $rows, string $amountKey = 'amt'): array
{
    $data = array_fill(0, 12, 0.0);
    foreach ($rows as $r) {
        $ym = (string) ($r['ym'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
            continue;
        }
        $mo = (int) $m[2];
        $idx = $mo >= 6 ? $mo - 6 : $mo + 6;
        if ($idx >= 0 && $idx < 12) {
            $data[$idx] = round((float) ($r[$amountKey] ?? 0), 2);
        }
    }

    return $data;
}

function dash_spark_slice(array $data): array
{
    $curMonth = (int) date('n');
    $idx = $curMonth >= 6 ? $curMonth - 6 : $curMonth + 6;

    return array_slice($data, 0, min($idx + 1, 12));
}

$metrics = [
    'activeStudents' => 0,
    'yearCollection' => 0.0,
    'monthCollection' => 0.0,
    'prevMonthCollection' => 0.0,
    'totalPending' => 0.0,
    'yearExpenses' => 0.0,
    'monthExpenses' => 0.0,
    'prevMonthExpenses' => 0.0,
    'admissionsMonth' => 0,
    'enquiriesNew' => 0,
    'enquiriesTotal' => 0,
    'totalPaid' => 0.0,
    'paymentsYear' => 0,
    'admissionsYear' => 0,
];

$statsCur = ay_compute_stats($ay);
$statsPrev = $prevAy ? ay_compute_stats($prevAy) : null;

$aging = ['b1' => 0.0, 'b2' => 0.0, 'b3' => 0.0, 'b4' => 0.0];
$monthlyCollection = array_fill(0, 12, 0.0);
$monthlyCollectionPrev = array_fill(0, 12, 0.0);
$monthlyExpenses = array_fill(0, 12, 0.0);
$monthlyAdmissions = array_fill(0, 12, 0.0);
$monthlyEnquiries = array_fill(0, 12, 0.0);
$classLabels = [];
$classData = [];
$topClasses = [];
$dueStudents = [];
$activityDays = [];
$recentEnquiries = [];

if ($hasStudents && $hasAyCol) {
    $metrics['activeStudents'] = (int) ($statsCur['active_students'] ?? 0);
    $r = safe_db_get_one(
        "SELECT COUNT(*) AS c FROM students WHERE academic_year = :panel_ay AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())",
        $ayParams
    );
    $metrics['admissionsMonth'] = (int) ($r['c'] ?? 0);
    $metrics['admissionsYear'] = (int) ($statsCur['admissions'] ?? 0);

    $arows = safe_db_get_all(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS amt
         FROM students WHERE academic_year = :panel_ay AND created_at BETWEEN :panel_ay_start AND :panel_ay_end
         GROUP BY ym ORDER BY ym",
        array_merge($ayParams, $ayDateParams)
    ) ?: [];
    $monthlyAdmissions = dash_align_ay_months($arows, 'amt');
}

if ($hasFees) {
    $metrics['yearCollection'] = (float) ($statsCur['collected'] ?? 0);

    $r = safe_db_get_one(
        "SELECT COUNT(*) AS c FROM fees_records fr {$feesAyJoin}
         WHERE fr.paid_amount > 0 AND COALESCE(fr.collected_at, fr.created_at) BETWEEN :panel_ay_start AND :panel_ay_end",
        array_merge($hasAyCol ? $ayParams : [], $ayDateParams)
    );
    $metrics['paymentsYear'] = (int) ($r['c'] ?? 0);

    $r = safe_db_get_one(
        "SELECT COALESCE(SUM(fr.paid_amount),0) AS a FROM fees_records fr {$feesAyJoin}
         WHERE fr.paid_amount > 0 AND MONTH(COALESCE(fr.collected_at, fr.created_at))=MONTH(CURDATE()) AND YEAR(COALESCE(fr.collected_at, fr.created_at))=YEAR(CURDATE())",
        $hasAyCol ? $ayParams : []
    );
    $metrics['monthCollection'] = (float) ($r['a'] ?? 0);

    $r = safe_db_get_one(
        "SELECT COALESCE(SUM(fr.paid_amount),0) AS a FROM fees_records fr {$feesAyJoin}
         WHERE fr.paid_amount > 0 AND MONTH(COALESCE(fr.collected_at, fr.created_at))=MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(COALESCE(fr.collected_at, fr.created_at))=YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))",
        $hasAyCol ? $ayParams : []
    );
    $metrics['prevMonthCollection'] = (float) ($r['a'] ?? 0);

    $mrows = safe_db_get_all(
        "SELECT DATE_FORMAT(COALESCE(fr.collected_at, fr.created_at), '%Y-%m') AS ym,
                COALESCE(SUM(fr.paid_amount),0) AS amt
         FROM fees_records fr {$feesAyJoin}
         WHERE fr.paid_amount > 0 AND COALESCE(fr.collected_at, fr.created_at) BETWEEN :panel_ay_start AND :panel_ay_end
         GROUP BY ym ORDER BY ym",
        array_merge($hasAyCol ? $ayParams : [], $ayDateParams)
    ) ?: [];
    $monthlyCollection = dash_align_ay_months($mrows);

    if ($prevAy && $hasAyCol) {
        $prevRange = ay_to_range($prevAy);
        if ($prevRange) {
            $prows = safe_db_get_all(
                "SELECT DATE_FORMAT(COALESCE(fr.collected_at, fr.created_at), '%Y-%m') AS ym,
                        COALESCE(SUM(fr.paid_amount),0) AS amt
                 FROM fees_records fr {$prevFeesAyJoin}
                 WHERE fr.paid_amount > 0 AND COALESCE(fr.collected_at, fr.created_at) BETWEEN :prev_start AND :prev_end
                 GROUP BY ym ORDER BY ym",
                [
                    ':prev_ay' => $prevAy,
                    ':prev_start' => $prevRange['start'],
                    ':prev_end' => $prevRange['end'] . ' 23:59:59',
                ]
            ) ?: [];
            $monthlyCollectionPrev = dash_align_ay_months($prows);
        }
    }

    if ($hasStudents && $hasAyCol) {
        $metrics['totalPending'] = (float) ($statsCur['pending'] ?? 0);
        $metrics['totalPaid'] = $metrics['yearCollection'];

        $rows = safe_db_get_all(
            "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.father_phone, s.mother_phone,
                    COALESCE(c.name,'') AS class_name,
                    GREATEST(COALESCE(s.total_fees,0) - COALESCE(fr_sum.paid_sum,0),0) AS pending,
                    DATEDIFF(CURDATE(), COALESCE(s.admission_date, DATE(s.created_at))) AS age_days
             FROM students s
             LEFT JOIN (SELECT student_id, SUM(paid_amount) AS paid_sum FROM fees_records GROUP BY student_id) fr_sum ON fr_sum.student_id = s.id
             LEFT JOIN classes c ON c.id = s.class_id
             WHERE s.academic_year = :panel_ay AND s.status = 'active'
             HAVING pending > 0
             ORDER BY pending DESC LIMIT 12",
            $ayParams
        ) ?: [];
        foreach ($rows as $row) {
            $pending = (float) ($row['pending'] ?? 0);
            $days = (int) ($row['age_days'] ?? 0);
            if ($days <= 15) {
                $aging['b1'] += $pending;
            } elseif ($days <= 30) {
                $aging['b2'] += $pending;
            } elseif ($days <= 60) {
                $aging['b3'] += $pending;
            } else {
                $aging['b4'] += $pending;
            }
            $dueStudents[] = $row;
        }
    }
}

if ($hasClasses && $hasStudents && $hasAyCol) {
    $crows = safe_db_get_all(
        "SELECT c.name, COUNT(s.id) AS cnt FROM classes c
         LEFT JOIN students s ON s.class_id = c.id AND s.academic_year = :panel_ay AND s.status = 'active'
         GROUP BY c.id, c.name ORDER BY cnt DESC",
        $ayParams
    ) ?: [];
    foreach ($crows as $cr) {
        $classLabels[] = (string) ($cr['name'] ?? '');
        $classData[] = (int) ($cr['cnt'] ?? 0);
    }
    $topClasses = array_slice($crows, 0, 4);
}

if ($hasExpenses) {
    $metrics['yearExpenses'] = (float) ($statsCur['expenses'] ?? 0);

    $r = safe_db_get_one(
        "SELECT COALESCE(SUM(amount),0) AS s FROM expenses WHERE MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE()) AND expense_date BETWEEN :panel_ay_start AND :panel_ay_end",
        [':panel_ay_start' => $ayRange['start'], ':panel_ay_end' => $ayRange['end']]
    );
    $metrics['monthExpenses'] = (float) ($r['s'] ?? 0);
    $r = safe_db_get_one(
        "SELECT COALESCE(SUM(amount),0) AS s FROM expenses WHERE MONTH(expense_date)=MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(expense_date)=YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))",
        []
    );
    $metrics['prevMonthExpenses'] = (float) ($r['s'] ?? 0);

    $erows = safe_db_get_all(
        "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS amt
         FROM expenses WHERE expense_date BETWEEN :panel_ay_start AND :panel_ay_end
         GROUP BY ym ORDER BY ym",
        [':panel_ay_start' => $ayRange['start'], ':panel_ay_end' => $ayRange['end']]
    ) ?: [];
    $monthlyExpenses = dash_align_ay_months($erows);
}

if ($hasEnquiries) {
    $metrics['enquiriesNew'] = (int) safe_db_get_one(
        "SELECT COUNT(*) AS c FROM enquiries WHERE status = 'new' AND created_at BETWEEN :panel_ay_start AND :panel_ay_end",
        $ayDateParams
    )['c'] ?? 0;
    $metrics['enquiriesTotal'] = (int) ($statsCur['enquiries'] ?? 0);
    $recentEnquiries = safe_db_get_all(
        "SELECT id, name, phone, message, created_at, status FROM enquiries WHERE created_at BETWEEN :panel_ay_start AND :panel_ay_end ORDER BY created_at DESC LIMIT 8",
        $ayDateParams
    ) ?: [];
    $eqrows = safe_db_get_all(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS amt FROM enquiries
         WHERE created_at BETWEEN :panel_ay_start AND :panel_ay_end GROUP BY ym",
        $ayDateParams
    ) ?: [];
    $monthlyEnquiries = dash_align_ay_months($eqrows, 'amt');

    $arows = safe_db_get_all(
        "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM enquiries
         WHERE created_at BETWEEN :panel_ay_start AND :panel_ay_end
           AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
         GROUP BY d",
        $ayDateParams
    ) ?: [];
    $amap = [];
    foreach ($arows as $a) {
        $amap[$a['d'] ?? ''] = (int) ($a['c'] ?? 0);
    }
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $activityDays[] = ['date' => $d, 'count' => $amap[$d] ?? 0];
    }
}

$agingTotal = max(0.01, $aging['b1'] + $aging['b2'] + $aging['b3'] + $aging['b4']);
$collPct = dash_pct_change($metrics['monthCollection'], $metrics['prevMonthCollection']);
$expPct = dash_pct_change($metrics['monthExpenses'], $metrics['prevMonthExpenses']);
$netMonth = $metrics['monthCollection'] - $metrics['monthExpenses'];
$netYear = $metrics['yearCollection'] - $metrics['yearExpenses'];

$compareRows = [
    ['key' => 'active_students', 'label' => 'Active Students', 'fmt' => 'int'],
    ['key' => 'admissions', 'label' => 'Total Admissions', 'fmt' => 'int'],
    ['key' => 'collected', 'label' => 'Fees Collected', 'fmt' => 'money'],
    ['key' => 'pending', 'label' => 'Pending Fees', 'fmt' => 'money'],
    ['key' => 'expenses', 'label' => 'Expenses', 'fmt' => 'money'],
    ['key' => 'enquiries', 'label' => 'Enquiries', 'fmt' => 'int'],
];

// Full 12-month A.Y. series for charts (not sliced to today)
$sparkCollection = $monthlyCollection;
$sparkExpenses = $monthlyExpenses;
$sparkAdmissions = $monthlyAdmissions;
$sparkEnquiries = $monthlyEnquiries;

$birthdays = dash_birthdays_today($ay);
$attendanceToday = dash_attendance_today($ay);
$funnel = dash_admission_funnel($metrics['enquiriesTotal'], $metrics['enquiriesNew'], $metrics['admissionsYear']);
$feeReminders = dash_fee_reminder_students($ay, 60, 8);
$chartTheme = dash_chart_theme();

$compareIcons = [
    'active_students' => 'bi-people-fill',
    'admissions' => 'bi-person-plus-fill',
    'collected' => 'bi-cash-stack',
    'pending' => 'bi-hourglass-split',
    'expenses' => 'bi-wallet2',
    'enquiries' => 'bi-chat-dots-fill',
];
$periodMonth = date('M Y');
$todayLabel = date('d M Y');

$page_title = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<link href="<?php echo htmlspecialchars(rtrim(defined('BASE_URL') ? BASE_URL : '/', '/')); ?>/assets/css/owner-dashboard.css" rel="stylesheet">
<link href="<?php echo htmlspecialchars(rtrim(defined('BASE_URL') ? BASE_URL : '/', '/')); ?>/assets/css/dashboard-cards.css" rel="stylesheet">

<div class="odash-toolbar">
  <div class="odash-tabs">
    <a href="<?php echo e(dash_url('analytics')); ?>" class="<?php echo $view === 'analytics' ? 'active' : ''; ?>"><i class="bi bi-graph-up me-1"></i>Analytics</a>
    <a href="<?php echo e(dash_url('quicklinks')); ?>" class="<?php echo $view === 'quicklinks' ? 'active' : ''; ?>"><i class="bi bi-grid me-1"></i>Quick Links</a>
  </div>
  <div class="odash-toolbar-right">
    <span class="odash-updated d-none d-lg-inline">Updated <strong><?php echo e($lastUpdated); ?></strong></span>
    <a href="dashboard.php?view=<?php echo e($view); ?>" class="odash-refresh text-decoration-none"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
  </div>
</div>

<div class="odash-analytics<?php echo $view === 'quicklinks' ? ' hide' : ''; ?>">
<?php require __DIR__ . '/partials/dashboard_analytics.php'; ?>
</div>


<div class="odash-quick-grid<?php echo $view === 'quicklinks' ? ' show' : ''; ?>">
  <div class="row g-2">
    <?php
    $quick = [
        [$ownerBase . '/students_list.php', 'bi-people', 'Students'],
        [$ownerBase . '/pending_fees.php', 'bi-clock-history', 'Pending Fees'],
        [$ownerBase . '/daily_collection.php', 'bi-cash-stack', 'Daily Collection'],
        [$ownerBase . '/enquiry_list.php', 'bi-chat-left-text', 'Enquiries'],
        [$ownerBase . '/academic_year_hub.php', 'bi-calendar2-range', 'Year Rollover'],
        [$ownerBase . '/year_end_report.php', 'bi-file-earmark-pdf', 'Year-end PDF'],
        ['../reception/admission.php', 'bi-person-plus', 'New Admission'],
        ['../accounts/fees_collection.php', 'bi-cash-coin', 'Collect Fees'],
        [$ownerBase . '/teacher_hub.php', 'bi-grid', 'Teacher Hub'],
        [$ownerBase . '/parent_portal.php', 'bi-eye', 'Parent Hub'],
        [$ownerBase . '/class_setup.php', 'bi-book', 'Class Setup'],
        [$ownerBase . '/staff_manage.php', 'bi-person-badge', 'Staff'],
    ];
    foreach ($quick as $q): ?>
      <div class="col-6 col-md-4 col-lg-3">
        <a href="<?php echo e($q[0]); ?>" class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1">
          <i class="bi <?php echo e($q[1]); ?> fs-4"></i>
          <span class="small fw-semibold"><?php echo e($q[2]); ?></span>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (typeof Chart === 'undefined') return;

  const monthLabels = <?php echo json_encode($monthLabels); ?>;
  const collection = <?php echo json_encode($monthlyCollection); ?>;
  const expenses = <?php echo json_encode($monthlyExpenses); ?>;
  const classLabels = <?php echo json_encode($classLabels); ?>;
  const classData = <?php echo json_encode($classData); ?>;
  const paid = <?php echo json_encode(round($metrics['totalPaid'], 2)); ?>;
  const pendingAmt = <?php echo json_encode(round($metrics['totalPending'], 2)); ?>;
  const sparkData = {
    collection: <?php echo json_encode($sparkCollection); ?>,
    expenses: <?php echo json_encode($sparkExpenses); ?>,
    admissions: <?php echo json_encode($sparkAdmissions); ?>,
    enquiries: <?php echo json_encode($sparkEnquiries); ?>
  };
  const theme = <?php echo json_encode($chartTheme); ?>;
  const primary = 'rgba(' + theme.primaryRgb + ',0.88)';
  const accent = 'rgba(' + theme.accentRgb + ',0.88)';
  const danger = theme.danger;
  const refGreen = '#4db6ac';
  const refGreenSoft = 'rgba(77, 182, 172, 0.85)';

  const baseOpts = {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 400 }
  };

  function mkChart(id, cfg) {
    const el = document.getElementById(id);
    if (!el) return null;
    return new Chart(el, cfg);
  }

  function spark(id, data, color) {
    if (!data || !data.length) return;
    mkChart(id, {
      type: 'bar',
      data: { labels: monthLabels, datasets: [{ data, backgroundColor: color, borderRadius: 2, barPercentage: 0.9, categoryPercentage: 0.95 }] },
      options: Object.assign({}, baseOpts, {
        plugins: { legend: { display: false }, tooltip: { enabled: true } },
        scales: { x: { display: false }, y: { display: false, beginAtZero: true } },
        layout: { padding: { top: 4, bottom: 0, left: 0, right: 0 } }
      })
    });
  }
  spark('sparkCollection', sparkData.collection, refGreenSoft);
  spark('sparkExpenses', sparkData.expenses, refGreenSoft);
  spark('sparkAdmissions', sparkData.admissions, refGreenSoft);
  spark('sparkEnquiries', sparkData.enquiries, refGreenSoft);

  mkChart('chartFunnel', {
    type: 'bar',
    data: {
      labels: ['E', 'N', 'A'],
      datasets: [{ data: <?php echo json_encode([$funnel['enquiries'], $funnel['new_enquiries'], $funnel['admissions']]); ?>, backgroundColor: refGreenSoft, borderRadius: 2, barPercentage: 0.85 }]
    },
    options: Object.assign({}, baseOpts, {
      plugins: { legend: { display: false } },
      scales: { x: { display: false }, y: { display: false, beginAtZero: true } }
    })
  });

  mkChart('chartAyCompare', {
    type: 'bar',
    data: {
      labels: monthLabels,
      datasets: [{ label: 'Collection', data: collection, backgroundColor: refGreenSoft, borderRadius: 4, maxBarThickness: 32 }]
    },
    options: Object.assign({}, baseOpts, {
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return '₹' + (c.parsed.y || 0).toLocaleString('en-IN'); } } } },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11, weight: '600' } } },
        y: { beginAtZero: true, ticks: { callback: function (v) { return '₹' + v.toLocaleString('en-IN'); } } }
      }
    })
  });

  if (classLabels.length) {
    mkChart('chartClasses', {
      type: 'doughnut',
      data: { labels: classLabels, datasets: [{ data: classData, backgroundColor: [theme.primary, theme.accent, theme.mint, theme.lavender, theme.sun, theme.danger], borderWidth: 0 }] },
      options: Object.assign({}, baseOpts, {
        cutout: '58%',
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 8, font: { size: 10, weight: '600' } } } }
      })
    });
  }

  mkChart('chartNetMonthly', {
    type: 'line',
    data: {
      labels: monthLabels,
      datasets: [
        { label: 'Collection', data: collection, borderColor: theme.primary, backgroundColor: 'rgba(' + theme.primaryRgb + ',0.15)', fill: true, tension: 0.35, pointRadius: 3 },
        { label: 'Expenses', data: expenses, borderColor: theme.danger, backgroundColor: 'rgba(232,77,111,0.1)', fill: true, tension: 0.35, pointRadius: 3 }
      ]
    },
    options: Object.assign({}, baseOpts, {
      plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11, weight: '600' } } } },
      scales: { y: { beginAtZero: true } }
    })
  });

  mkChart('chartPaidPending', {
    type: 'doughnut',
    data: { labels: ['Paid', 'Pending'], datasets: [{ data: [paid, pendingAmt], backgroundColor: [theme.primary, theme.sun], borderWidth: 0 }] },
    options: Object.assign({}, baseOpts, {
      cutout: '55%',
      plugins: { legend: { position: 'bottom', labels: { font: { size: 11, weight: '600' } } } }
    })
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
