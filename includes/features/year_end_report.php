<?php
/**
 * Year-end printable report (browser Print → PDF).
 */
declare(strict_types=1);

$ay = trim((string) ($_GET['ay'] ?? ay_selected()));
if (!ay_is_valid($ay)) {
    $ay = ay_selected();
}
$stats = ay_compute_stats($ay);
$range = ay_to_range($ay);
$appName = defined('APP_NAME') ? APP_NAME : 'Preschool';
$generated = date('d M Y, h:i A');

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Year-end Report — <?php echo htmlspecialchars(ay_display_short($ay)); ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { font-size: 14px; color: #222; }
    .report-header { border-bottom: 3px solid #2d6a3e; padding-bottom: 1rem; margin-bottom: 1.5rem; }
    .metric-box { border: 1px solid #ddd; border-radius: 8px; padding: 1rem; text-align: center; }
    .metric-box .val { font-size: 1.5rem; font-weight: 700; }
    @media print {
      .no-print { display: none !important; }
      body { margin: 0; }
    }
  </style>
</head>
<body class="p-4">
  <div class="no-print mb-3 d-flex gap-2">
    <button class="btn btn-success" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save as PDF</button>
    <button class="btn btn-outline-secondary" onclick="window.close()">Close</button>
  </div>

  <div class="report-header">
    <h2 class="mb-1"><?php echo htmlspecialchars($appName); ?></h2>
    <h4 class="text-success mb-0">Year-end Report — <?php echo htmlspecialchars(ay_display_long($ay)); ?></h4>
    <?php if ($range): ?>
      <div class="text-muted small">Period: <?php echo htmlspecialchars($range['start']); ?> to <?php echo htmlspecialchars($range['end']); ?></div>
    <?php endif; ?>
    <div class="text-muted small">Generated: <?php echo htmlspecialchars($generated); ?></div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-3"><div class="metric-box"><div class="text-muted small">Active Students</div><div class="val"><?php echo (int) $stats['active_students']; ?></div></div></div>
    <div class="col-md-3"><div class="metric-box"><div class="text-muted small">Total Admissions</div><div class="val"><?php echo (int) $stats['admissions']; ?></div></div></div>
    <div class="col-md-3"><div class="metric-box"><div class="text-muted small text-success">Fees Collected</div><div class="val text-success"><?php echo htmlspecialchars(format_money($stats['collected'])); ?></div></div></div>
    <div class="col-md-3"><div class="metric-box"><div class="text-muted small text-danger">Pending Fees</div><div class="val text-danger"><?php echo htmlspecialchars(format_money($stats['pending'])); ?></div></div></div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-4"><div class="metric-box"><div class="text-muted small">Expenses</div><div class="val"><?php echo htmlspecialchars(format_money($stats['expenses'])); ?></div></div></div>
    <div class="col-md-4"><div class="metric-box"><div class="text-muted small">Enquiries</div><div class="val"><?php echo (int) $stats['enquiries']; ?></div></div></div>
    <div class="col-md-4"><div class="metric-box"><div class="text-muted small">Alumni (this year)</div><div class="val"><?php echo (int) $stats['alumni']; ?></div></div></div>
  </div>

  <?php if (!empty($stats['by_class'])): ?>
    <h5 class="fw-bold mb-2">Students by Class</h5>
    <table class="table table-bordered table-sm">
      <thead class="table-light"><tr><th>Class</th><th class="text-end">Active Students</th></tr></thead>
      <tbody>
        <?php foreach ($stats['by_class'] as $bc): ?>
          <tr><td><?php echo htmlspecialchars($bc['name']); ?></td><td class="text-end"><?php echo (int) $bc['count']; ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="fw-bold"><td>Total</td><td class="text-end"><?php echo (int) $stats['active_students']; ?></td></tr></tfoot>
    </table>
  <?php endif; ?>

  <div class="mt-4 pt-3 border-top small text-muted text-center">
    <?php echo htmlspecialchars($appName); ?> — Preschool Management System · Confidential
  </div>
</body>
</html>
