<?php
/**
 * reception/view_admission_bulk_log.php
 * Safe viewer for admission_bulk_error logs (owner + APP_DEBUG only).
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (file_exists(__DIR__ . '/../includes/config.php')) {
    require_once __DIR__ . '/../includes/config.php';
}
require_once __DIR__ . '/../includes/auth.php';
require_owner_auth();

if (!defined('APP_DEBUG') || !APP_DEBUG) {
    http_response_code(404);
    exit('Not found');
}

$sysLog = sys_get_temp_dir() . '/admission_bulk_error.log';
$projLog = __DIR__ . '/../tmp/admission_bulk_error.log';

function tail_file(string $file, int $lines = 200): string
{
    if (!is_file($file) || !is_readable($file)) {
        return '';
    }
    $f = fopen($file, 'rb');
    if (!$f) {
        return '';
    }
    $buffer = '';
    $chunk = 4096;
    fseek($f, 0, SEEK_END);
    $pos = ftell($f);
    $lineCnt = 0;
    while ($pos > 0 && $lineCnt <= $lines) {
        $read = ($pos >= $chunk) ? $chunk : $pos;
        $pos -= $read;
        fseek($f, $pos);
        $buffer = fread($f, $read) . $buffer;
        $lineCnt = substr_count($buffer, "\n");
    }
    fclose($f);
    $parts = explode("\n", $buffer);
    return implode("\n", array_slice($parts, -$lines));
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admission Bulk Error Log</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>body{font-family:monospace;padding:1rem;background:#111;color:#eee}pre{white-space:pre-wrap}</style>
</head>
<body>
<h1>Admission Bulk Error Log</h1>
<h2>System temp: <?php echo htmlspecialchars($sysLog); ?></h2>
<pre><?php echo htmlspecialchars(tail_file($sysLog)); ?></pre>
<h2>Project tmp: <?php echo htmlspecialchars($projLog); ?></h2>
<pre><?php echo htmlspecialchars(tail_file($projLog)); ?></pre>
</body>
</html>
