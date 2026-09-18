<?php
/**
 * Session math captcha for public forms (no Google keys).
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('simple_captcha_ensure')) {
    /**
     * @return array{a:int,b:int,sum:int}
     */
    function simple_captcha_ensure(bool $force = false): array
    {
        if ($force || !isset($_SESSION['sc_a'], $_SESSION['sc_b'], $_SESSION['sc_sum'])) {
            $a = random_int(1, 9);
            $b = random_int(1, 9);
            $_SESSION['sc_a'] = $a;
            $_SESSION['sc_b'] = $b;
            $_SESSION['sc_sum'] = $a + $b;
        }
        return [
            'a' => (int) $_SESSION['sc_a'],
            'b' => (int) $_SESSION['sc_b'],
            'sum' => (int) $_SESSION['sc_sum'],
        ];
    }
}

if (!function_exists('simple_captcha_ok')) {
    function simple_captcha_ok(string $answer): bool
    {
        if (!isset($_SESSION['sc_sum'])) {
            return false;
        }
        $ok = ((int) trim($answer) === (int) $_SESSION['sc_sum']);
        unset($_SESSION['sc_a'], $_SESSION['sc_b'], $_SESSION['sc_sum']);
        return $ok;
    }
}

if (!function_exists('simple_captcha_render_img')) {
    function simple_captcha_render_img(int $a, int $b): void
    {
        $text = $a . ' + ' . $b . ' = ?';
        $w = 220;
        $h = 64;
        if (!function_exists('imagecreatetruecolor')) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $text;
            return;
        }
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 255, 248, 232);
        $ink = imagecolorallocate($im, 30, 58, 95);
        $line = imagecolorallocate($im, 255, 182, 193);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);
        for ($i = 0; $i < 6; $i++) {
            imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $line);
        }
        imagestring($im, 5, 28, 22, $text, $ink);
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        imagepng($im);
        imagedestroy($im);
    }
}
