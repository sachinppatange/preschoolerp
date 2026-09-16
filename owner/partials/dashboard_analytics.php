<?php
declare(strict_types=1);
/** Dashboard analytics — simple unified card layout. Expects vars from dashboard.php. */
?>
<div class="dc-page">

  <div class="dc-ay-bar">
    <div class="dc-ay-chip d-none d-md-inline-flex"><i class="bi bi-calendar2-range"></i> <?php echo e(ay_display_long($ay)); ?> · Updated <?php echo e($lastUpdated); ?></div>
    <div class="dc-ay-mobile d-md-none">
      <?php if (function_exists('render_dashboard_academic_year_dropdown')) {
          render_dashboard_academic_year_dropdown();
      } ?>
      <span class="dc-ay-mobile-meta">Updated <?php echo e($lastUpdated); ?></span>
    </div>
  </div>

  <section class="dc-section">
    <h2 class="dc-section-title">A.Y. Overview — <?php echo e(ay_display_short($ay)); ?></h2>
    <div class="dc-grid dc-grid-4">
      <div class="dc-card">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--pink"><i class="bi bi-cash-stack"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Fee Collection</span>
            <span class="dc-card-value"><?php echo e(format_money($metrics['yearCollection'])); ?></span>
            <span class="dc-card-meta"><?php echo (int) $metrics['paymentsYear']; ?> payments</span>
          </div>
        </div>
        <div class="dc-card-chart"><canvas id="sparkCollection"></canvas></div>
      </div>
      <div class="dc-card">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--rose"><i class="bi bi-wallet2"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Expenses</span>
            <span class="dc-card-value"><?php echo e(format_money($metrics['yearExpenses'])); ?></span>
            <span class="dc-card-meta">This month <?php echo e(format_money($metrics['monthExpenses'])); ?></span>
          </div>
        </div>
        <div class="dc-card-chart"><canvas id="sparkExpenses"></canvas></div>
      </div>
      <div class="dc-card">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--blue"><i class="bi bi-person-plus-fill"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Admissions</span>
            <span class="dc-card-value"><?php echo (int) $metrics['admissionsYear']; ?></span>
            <span class="dc-card-meta"><?php echo (int) $metrics['activeStudents']; ?> active students</span>
          </div>
        </div>
        <div class="dc-card-chart"><canvas id="sparkAdmissions"></canvas></div>
      </div>
      <div class="dc-card">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--amber"><i class="bi bi-chat-left-text-fill"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Enquiries</span>
            <span class="dc-card-value"><?php echo (int) $metrics['enquiriesTotal']; ?></span>
            <span class="dc-card-meta"><?php echo (int) $metrics['enquiriesNew']; ?> new</span>
          </div>
        </div>
        <div class="dc-card-chart"><canvas id="sparkEnquiries"></canvas></div>
      </div>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">Today at Preschool</h2>
    <div class="dc-grid dc-grid-3">
      <div class="dc-card">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--amber"><i class="bi bi-cake2-fill"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Birthdays Today</span>
            <span class="dc-card-value"><?php echo (int) $birthdays['count']; ?></span>
            <span class="dc-card-meta"><?php echo e($todayLabel); ?></span>
          </div>
        </div>
        <?php if (!empty($birthdays['students'])): ?>
          <ul class="dc-birthday-list">
            <?php foreach ($birthdays['students'] as $bd):
                $bn = trim(($bd['first_name'] ?? '') . ' ' . ($bd['last_name'] ?? ''));
                $age = (int) ($bd['age_years'] ?? 0);
            ?>
              <li><span class="name"><?php echo e($bn); ?></span><span class="meta"><?php echo e((string) ($bd['class_name'] ?? '')); ?><?php echo $age > 0 ? ' · ' . $age . ' yrs' : ''; ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <span class="dc-card-meta">No birthdays today</span>
        <?php endif; ?>
      </div>
      <div class="dc-card">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--green"><i class="bi bi-person-check-fill"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Today's Attendance</span>
            <span class="dc-card-value"><?php echo e(number_format((float) $attendanceToday['pct'], 1)); ?>%</span>
            <span class="dc-card-meta"><?php echo (int) $attendanceToday['marked']; ?>/<?php echo (int) $attendanceToday['total_active']; ?> marked</span>
          </div>
        </div>
        <div class="dc-att-row">
          <div class="dc-att-pill present"><?php echo (int) $attendanceToday['present']; ?> Present</div>
          <div class="dc-att-pill absent"><?php echo (int) $attendanceToday['absent']; ?> Absent</div>
          <div class="dc-att-pill late"><?php echo (int) $attendanceToday['late']; ?> Late</div>
        </div>
        <a href="../teacher/attendance_mark.php" class="dc-card-btn">Mark Attendance</a>
      </div>
      <div class="dc-card">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--pink"><i class="bi bi-funnel-fill"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Inquiry → Admission</span>
            <span class="dc-card-value"><?php echo e(number_format((float) $funnel['conversion'], 1)); ?>%</span>
            <span class="dc-card-meta"><?php echo (int) $funnel['enquiries']; ?> enquiries · <?php echo (int) $funnel['admissions']; ?> admissions</span>
          </div>
        </div>
        <div class="dc-card-chart"><canvas id="chartFunnel"></canvas></div>
      </div>
    </div>
  </section>

  <section class="dc-section">
    <div class="dc-section-head">
      <h2 class="dc-section-title">Year Comparison</h2>
      <a href="<?php echo e($ownerBase); ?>/academic_year_hub.php?tab=compare" class="dc-section-link">Full compare</a>
    </div>
    <p class="dc-section-sub"><?php echo e(ay_display_short($ay)); ?> vs <?php echo $prevAy ? e(ay_display_short($prevAy)) : 'Last A.Y.'; ?></p>
    <div class="dc-grid dc-grid-6">
      <?php foreach ($compareRows as $cr):
          $curVal = (float) ($statsCur[$cr['key']] ?? 0);
          $prevVal = $statsPrev ? (float) ($statsPrev[$cr['key']] ?? 0) : 0;
          $chg = dash_pct_change($curVal, $prevVal);
          $curDisp = $cr['fmt'] === 'money' ? format_money($curVal) : (string) (int) $curVal;
          $prevDisp = $prevAy ? ($cr['fmt'] === 'money' ? format_money($prevVal) : (string) (int) $prevVal) : '—';
          $icon = $compareIcons[$cr['key']] ?? 'bi-bar-chart';
      ?>
        <div class="dc-card">
          <div class="dc-card-top">
            <div class="dc-card-icon dc-card-icon--green"><i class="bi <?php echo e($icon); ?>"></i></div>
            <div class="dc-card-info">
              <span class="dc-card-label"><?php echo e($cr['label']); ?></span>
              <span class="dc-card-value"><?php echo e($curDisp); ?></span>
              <span class="dc-card-meta">Last: <?php echo e($prevDisp); ?> · <span class="<?php echo e($chg['class']); ?>"><?php echo e($chg['text']); ?></span></span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">Financial Summary</h2>
    <div class="dc-grid dc-grid-4">
      <a href="<?php echo e($ownerBase); ?>/daily_collection.php" class="dc-card dc-card--link">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--pink"><i class="bi bi-cash-coin"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Fees Collected</span>
            <span class="dc-card-value"><?php echo e(format_money($metrics['yearCollection'])); ?></span>
            <span class="dc-card-meta"><?php echo (int) $metrics['paymentsYear']; ?> payments</span>
          </div>
        </div>
      </a>
      <a href="<?php echo e($ownerBase); ?>/pending_fees.php" class="dc-card dc-card--link">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--amber"><i class="bi bi-hourglass-split"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Pending Fees</span>
            <span class="dc-card-value dc-card-value--warn"><?php echo e(format_money($metrics['totalPending'])); ?></span>
            <span class="dc-card-meta"><?php echo (int) $metrics['activeStudents']; ?> students</span>
          </div>
        </div>
      </a>
      <a href="<?php echo e($ownerBase); ?>/expense.php" class="dc-card dc-card--link">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--rose"><i class="bi bi-credit-card-fill"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Total Expenses</span>
            <span class="dc-card-value"><?php echo e(format_money($metrics['yearExpenses'])); ?></span>
            <span class="dc-card-meta"><?php echo e($periodMonth); ?></span>
          </div>
        </div>
      </a>
      <div class="dc-card">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--sky"><i class="bi bi-piggy-bank-fill"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Net Balance</span>
            <span class="dc-card-value <?php echo $netYear >= 0 ? 'dc-card-value--ok' : 'dc-card-value--bad'; ?>"><?php echo e(format_money($netYear)); ?></span>
            <span class="dc-card-meta">Collection − Expenses</span>
          </div>
        </div>
        <?php
        $incPct = $metrics['yearCollection'] + $metrics['yearExpenses'] > 0
            ? round(($metrics['yearCollection'] / max(0.01, $metrics['yearCollection'] + $metrics['yearExpenses'])) * 100)
            : 50;
        ?>
        <div class="odash-net-bar mt-2"><span class="inc" style="width:<?php echo $incPct; ?>%"></span><span class="exp" style="width:<?php echo 100 - $incPct; ?>%"></span></div>
      </div>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">Charts</h2>
    <div class="dc-grid dc-grid-2">
      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Monthly Fee Collection</span>
          <a href="<?php echo e($ownerBase); ?>/monthly_summary.php" class="dc-section-link">Details</a>
        </div>
        <div class="dc-chart-wrap dc-chart-wrap--lg"><canvas id="chartAyCompare"></canvas></div>
      </div>
      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Paid vs Pending</span>
          <a href="<?php echo e($ownerBase); ?>/pending_fees.php" class="dc-section-link">View</a>
        </div>
        <div class="dc-chart-wrap"><canvas id="chartPaidPending"></canvas></div>
      </div>
      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Students by Class</span>
          <a href="<?php echo e($ownerBase); ?>/class_setup.php" class="dc-section-link">Classes</a>
        </div>
        <div class="dc-chart-wrap"><canvas id="chartClasses"></canvas></div>
      </div>
      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Collection vs Expenses</span>
          <a href="<?php echo e($ownerBase); ?>/expense.php" class="dc-section-link">Expenses</a>
        </div>
        <div class="dc-chart-wrap"><canvas id="chartNetMonthly"></canvas></div>
      </div>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">Outstanding &amp; Pipeline</h2>
    <div class="dc-grid dc-grid-2">
      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Fees Outstanding</span>
          <a href="<?php echo e($ownerBase); ?>/pending_fees.php" class="dc-section-link">View all</a>
        </div>
        <p class="dc-card-meta mb-2">Total <strong><?php echo e(format_money($metrics['totalPending'])); ?></strong></p>
        <?php
        $buckets = [
            ['lbl' => '1–15 Days', 'amt' => $aging['b1'], 'cls' => 'seg-mint', 'dot' => 'dot-mint'],
            ['lbl' => '16–30 Days', 'amt' => $aging['b2'], 'cls' => 'seg-accent', 'dot' => 'dot-accent'],
            ['lbl' => '31–60 Days', 'amt' => $aging['b3'], 'cls' => 'seg-sun', 'dot' => 'dot-sun'],
            ['lbl' => '60+ Days', 'amt' => $aging['b4'], 'cls' => 'seg-danger', 'dot' => 'dot-danger'],
        ];
        ?>
        <div class="dc-seg-bar odash-seg-bar">
          <?php foreach ($buckets as $b):
              $pct = max(2, round(($b['amt'] / $agingTotal) * 100, 1));
          ?>
            <div class="seg <?php echo e($b['cls']); ?>" style="width:<?php echo $pct; ?>%"></div>
          <?php endforeach; ?>
        </div>
        <div class="dc-seg-legend">
          <?php foreach ($buckets as $b): ?>
            <div class="dc-seg-item"><span class="dot <?php echo e($b['dot']); ?>"></span><span class="lbl"><?php echo e($b['lbl']); ?></span><span class="amt"><?php echo e(format_money($b['amt'])); ?></span></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Inquiry → Admission Funnel</span>
          <a href="<?php echo e($ownerBase); ?>/enquiry_list.php" class="dc-section-link">Enquiries</a>
        </div>
        <p class="dc-card-meta mb-2">Conversion <strong><?php echo e(number_format((float) $funnel['conversion'], 1)); ?>%</strong></p>
        <?php
        $pipe = [
            ['lbl' => 'Total Enquiries', 'val' => $funnel['enquiries'], 'cls' => 'seg-primary', 'dot' => 'dot-primary'],
            ['lbl' => 'New Enquiries', 'val' => $funnel['new_enquiries'], 'cls' => 'seg-accent', 'dot' => 'dot-accent'],
            ['lbl' => 'Admissions', 'val' => $funnel['admissions'], 'cls' => 'seg-mint', 'dot' => 'dot-mint'],
            ['lbl' => 'Active Students', 'val' => $metrics['activeStudents'], 'cls' => 'seg-sun', 'dot' => 'dot-sun'],
        ];
        $pipeMax = max(1, ...array_map(static fn ($p) => (int) $p['val'], $pipe));
        ?>
        <div class="dc-seg-bar odash-seg-bar">
          <?php foreach ($pipe as $p):
              $pct = max(2, round(((int) $p['val'] / $pipeMax) * 100, 1));
          ?>
            <div class="seg <?php echo e($p['cls']); ?>" style="width:<?php echo $pct; ?>%"></div>
          <?php endforeach; ?>
        </div>
        <div class="dc-seg-legend">
          <?php foreach ($pipe as $p): ?>
            <div class="dc-seg-item"><span class="dot <?php echo e($p['dot']); ?>"></span><span class="lbl"><?php echo e($p['lbl']); ?></span><span class="amt"><?php echo (int) $p['val']; ?></span></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <?php if (!empty($feeReminders)): ?>
  <section class="dc-section">
    <div class="dc-section-head">
      <h2 class="dc-section-title">Fee Reminders <span class="badge bg-danger ms-1" style="font-size:0.65rem">2+ months</span></h2>
      <a href="<?php echo e($ownerBase); ?>/pending_fees.php" class="dc-section-link">All pending</a>
    </div>
    <div class="dc-card dc-card--panel">
      <div class="d-md-none odash-mobile-list">
        <?php foreach ($feeReminders as $fr):
            $fn = trim(($fr['first_name'] ?? '') . ' ' . ($fr['last_name'] ?? ''));
            $days = (int) ($fr['age_days'] ?? 0);
            $pending = (float) ($fr['pending'] ?? 0);
            $waUrl = dash_whatsapp_reminder_url($fr, $pending);
        ?>
          <div class="odash-mobile-item overdue">
            <div class="odash-mobile-item-top">
              <strong><?php echo e($fn); ?></strong>
              <span class="text-danger fw-bold"><?php echo e(format_money($pending)); ?></span>
            </div>
            <div class="odash-mobile-item-meta">
              <span><?php echo e((string) ($fr['class_name'] ?? '—')); ?></span>
              <span class="odash-badge-due danger"><?php echo $days; ?> days</span>
            </div>
            <?php if ($waUrl !== ''): ?>
              <a href="<?php echo e($waUrl); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success odash-wa-btn w-100 mt-2"><i class="bi bi-whatsapp"></i> Remind</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="table-responsive d-none d-md-block">
        <table class="table table-sm odash-table mb-0">
          <thead><tr><th>Student</th><th>Class</th><th class="text-end">Pending</th><th>Overdue</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($feeReminders as $fr):
                $fn = trim(($fr['first_name'] ?? '') . ' ' . ($fr['last_name'] ?? ''));
                $days = (int) ($fr['age_days'] ?? 0);
                $pending = (float) ($fr['pending'] ?? 0);
                $waUrl = dash_whatsapp_reminder_url($fr, $pending);
            ?>
              <tr class="odash-row-overdue">
                <td class="fw-semibold"><?php echo e($fn); ?></td>
                <td><?php echo e((string) ($fr['class_name'] ?? '—')); ?></td>
                <td class="text-end fw-bold text-danger"><?php echo e(format_money($pending)); ?></td>
                <td><span class="odash-badge-due danger"><?php echo $days; ?> days</span></td>
                <td class="text-end">
                  <?php if ($waUrl !== ''): ?>
                    <a href="<?php echo e($waUrl); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success odash-wa-btn"><i class="bi bi-whatsapp"></i></a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="dc-section">
    <h2 class="dc-section-title">Classes</h2>
    <div class="dc-grid dc-grid-4">
      <?php
      $classIcons = ['bi-book', 'bi-mortarboard', 'bi-pencil', 'bi-stars'];
      foreach ($topClasses as $i => $tc): ?>
        <div class="dc-card">
          <div class="dc-card-top">
            <div class="dc-card-icon dc-card-icon--pink"><i class="bi <?php echo e($classIcons[$i] ?? 'bi-book'); ?>"></i></div>
            <div class="dc-card-info">
              <span class="dc-card-label"><?php echo e((string) ($tc['name'] ?? '—')); ?></span>
              <span class="dc-card-value"><?php echo (int) ($tc['cnt'] ?? 0); ?></span>
              <span class="dc-card-meta">students</span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (count($topClasses) < 4): ?>
        <div class="dc-card">
          <div class="dc-card-top">
            <div class="dc-card-icon dc-card-icon--blue"><i class="bi bi-person-plus"></i></div>
            <div class="dc-card-info">
              <span class="dc-card-label">Admissions</span>
              <span class="dc-card-value"><?php echo (int) $metrics['admissionsYear']; ?></span>
              <span class="dc-card-meta"><?php echo e(ay_display_short($ay)); ?></span>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">Pending &amp; Enquiries</h2>
    <div class="dc-grid dc-grid-2">
      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Pending Fees — Top Students</span>
          <a href="<?php echo e($ownerBase); ?>/pending_fees.php" class="dc-section-link">View all</a>
        </div>
        <div class="d-md-none odash-mobile-list">
          <?php if (empty($dueStudents)): ?>
            <p class="text-muted text-center py-3 mb-0">No pending fees</p>
          <?php else: foreach ($dueStudents as $ds):
              $nm = trim(($ds['first_name'] ?? '') . ' ' . ($ds['middle_name'] ?? '') . ' ' . ($ds['last_name'] ?? ''));
              $days = (int) ($ds['age_days'] ?? 0);
              $badge = $days <= 15 ? 'ok' : ($days <= 30 ? 'warn' : ($days <= 60 ? 'orange' : 'danger'));
          ?>
            <div class="odash-mobile-item<?php echo $days >= 60 ? ' overdue' : ''; ?>">
              <div class="odash-mobile-item-top">
                <strong><?php echo e($nm); ?></strong>
                <span class="text-danger fw-bold"><?php echo e(format_money((float) ($ds['pending'] ?? 0))); ?></span>
              </div>
              <div class="odash-mobile-item-meta">
                <span><?php echo e((string) ($ds['class_name'] ?? '—')); ?></span>
                <span class="odash-badge-due <?php echo e($badge); ?>"><?php echo $days; ?> days</span>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <div class="table-responsive d-none d-md-block">
          <table class="table table-sm odash-table mb-0">
            <thead><tr><th>Student</th><th>Class</th><th class="text-end">Pending</th><th>Due</th></tr></thead>
            <tbody>
              <?php if (empty($dueStudents)): ?>
                <tr><td colspan="4" class="text-muted text-center py-3">No pending fees</td></tr>
              <?php else: foreach ($dueStudents as $ds):
                  $nm = trim(($ds['first_name'] ?? '') . ' ' . ($ds['middle_name'] ?? '') . ' ' . ($ds['last_name'] ?? ''));
                  $days = (int) ($ds['age_days'] ?? 0);
                  $badge = $days <= 15 ? 'ok' : ($days <= 30 ? 'warn' : ($days <= 60 ? 'orange' : 'danger'));
              ?>
                <tr>
                  <td class="fw-semibold"><?php echo e($nm); ?></td>
                  <td><?php echo e((string) ($ds['class_name'] ?? '—')); ?></td>
                  <td class="text-end fw-bold text-danger"><?php echo e(format_money((float) ($ds['pending'] ?? 0))); ?></td>
                  <td><span class="odash-badge-due <?php echo e($badge); ?>"><?php echo $days; ?>d</span></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Recent Enquiries</span>
          <a href="<?php echo e($ownerBase); ?>/enquiry_list.php" class="dc-section-link">View all</a>
        </div>
        <div class="d-md-none odash-mobile-list">
          <?php if (empty($recentEnquiries)): ?>
            <p class="text-muted text-center py-3 mb-0">No enquiries</p>
          <?php else: foreach ($recentEnquiries as $en): ?>
            <div class="odash-mobile-item">
              <div class="odash-mobile-item-top">
                <strong><?php echo e((string) ($en['name'] ?? '—')); ?></strong>
                <span class="badge bg-secondary"><?php echo e((string) ($en['status'] ?? 'new')); ?></span>
              </div>
              <div class="odash-mobile-item-meta">
                <span><?php echo e((string) ($en['phone'] ?? '—')); ?></span>
                <span class="text-muted"><?php echo e(substr((string) ($en['created_at'] ?? ''), 0, 10)); ?></span>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <div class="table-responsive d-none d-md-block">
          <table class="table table-sm odash-table mb-0">
            <thead><tr><th>Name</th><th>Phone</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
              <?php if (empty($recentEnquiries)): ?>
                <tr><td colspan="4" class="text-muted text-center py-3">No enquiries</td></tr>
              <?php else: foreach ($recentEnquiries as $en): ?>
                <tr>
                  <td><?php echo e((string) ($en['name'] ?? '—')); ?></td>
                  <td><?php echo e((string) ($en['phone'] ?? '—')); ?></td>
                  <td><span class="badge bg-secondary"><?php echo e((string) ($en['status'] ?? 'new')); ?></span></td>
                  <td class="small text-muted"><?php echo e(substr((string) ($en['created_at'] ?? ''), 0, 10)); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">Enquiry Activity — Last 30 Days</h2>
    <div class="dc-card dc-card--panel">
      <div class="odash-heatmap">
        <?php foreach ($activityDays as $ad):
            $c = (int) ($ad['count'] ?? 0);
            $lvl = $c === 0 ? '' : ($c === 1 ? 'l1' : ($c <= 2 ? 'l2' : ($c <= 4 ? 'l3' : 'l4')));
        ?>
          <div class="cell <?php echo e($lvl); ?>" title="<?php echo e($ad['date'] ?? ''); ?>: <?php echo $c; ?>"></div>
        <?php endforeach; ?>
      </div>
      <span class="dc-card-meta">Darker = more enquiries</span>
    </div>
  </section>

</div>
