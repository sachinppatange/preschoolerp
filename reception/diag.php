<?php
// reception/diag.php  (temporary - remove after debugging)
echo "<h3>PHP sys_get_temp_dir()</h3><pre>" . htmlspecialchars(sys_get_temp_dir()) . "</pre>";

$projLog = __DIR__ . '/../tmp/parents_bulk_error.log';
$sysLog  = sys_get_temp_dir() . '/parents_bulk_error.log';

echo "<h3>Project log path</h3><pre>" . htmlspecialchars($projLog) . "</pre>";
if (is_file($projLog)) {
    echo "<h4>Project log (last 2000 chars)</h4><pre>" . htmlspecialchars(substr(file_get_contents($projLog), -2000)) . "</pre>";
} else {
    echo "<div style='color:#b00'>Project log not found.</div>";
}

echo "<h3>System log path</h3><pre>" . htmlspecialchars($sysLog) . "</pre>";
if (is_file($sysLog)) {
    echo "<h4>System log (last 2000 chars)</h4><pre>" . htmlspecialchars(substr(file_get_contents($sysLog), -2000)) . "</pre>";
} else {
    echo "<div style='color:#b00'>System log not found.</div>";
}