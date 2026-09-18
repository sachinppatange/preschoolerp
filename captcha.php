<?php
/**
 * Public captcha image for feedback.php
 */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/simple_captcha.php';
$force = isset($_GET['r']);
$c = simple_captcha_ensure($force);
simple_captcha_render_img($c['a'], $c['b']);
