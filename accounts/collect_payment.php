<?php
/**
 * Old duplicate of Collect Fees. Send everyone to fees_collection.php
 * (or the receipt if an old bookmark used ?id=).
 */
declare(strict_types=1);

$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$studentId = isset($_GET['student_id']) && ctype_digit((string) $_GET['student_id']) ? (int) $_GET['student_id'] : 0;

if ($id > 0) {
    header('Location: receipt_print.php?id=' . $id, true, 301);
    exit;
}

$to = 'fees_collection.php';
if ($studentId > 0) {
    $to .= '?student_id=' . $studentId;
}
header('Location: ' . $to, true, 301);
exit;
