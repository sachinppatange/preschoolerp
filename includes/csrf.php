<?php
/**
 * includes/csrf.php
 *
 * Robust CSRF helpers.
 * - Safe to include multiple times (guards with function_exists / early return).
 * - Starts session if not already started.
 * - get_csrf_token(): return current token (creates if missing)
 * - validate_csrf_token($token): validate token (does NOT consume token)
 * - consume_csrf_token(): remove current token (for single-use workflows)
 * - regenerate_csrf_token(): force new token
 *
 * Install: replace your existing includes/csrf.php with this file.
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

/*
 If the project already defines core CSRF helpers, skip redeclaring them below.
 Secure-delete helpers live in includes/functions.php.
*/

/**
 * Create and/or return the CSRF token stored in session.
 * @return string
 */
if (!function_exists('get_csrf_token')) {
    function get_csrf_token(): string {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            // Prefer secure random_bytes, fallback to openssl, then less-secure mt_rand
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Throwable $e1) {
                try {
                    $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
                } catch (Throwable $e2) {
                    $_SESSION['csrf_token'] = bin2hex(md5(uniqid((string)microtime(true), true)));
                }
            }
            $_SESSION['csrf_token_time'] = time();
        }
        return (string) $_SESSION['csrf_token'];
    }
}

/**
 * Validate token (does NOT consume token).
 * Returns true if token matches the session token.
 * @param mixed $token
 * @return bool
 */
if (!function_exists('validate_csrf_token')) {
    function validate_csrf_token($token): bool {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) return false;
        if (!is_string($token) && !is_numeric($token)) return false;
        // Use hash_equals when available to avoid timing attacks
        return (function_exists('hash_equals'))
            ? hash_equals($_SESSION['csrf_token'], (string)$token)
            : ($_SESSION['csrf_token'] === (string)$token);
    }
}

/**
 * Consume (unset) the current token. Use this if you want single-use tokens.
 * @return void
 */
if (!function_exists('consume_csrf_token')) {
    function consume_csrf_token(): void {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (isset($_SESSION['csrf_token'])) {
            unset($_SESSION['csrf_token']);
            unset($_SESSION['csrf_token_time']);
        }
    }
}

/**
 * Regenerate token (force new token). Returns new token.
 * @return string
 */
if (!function_exists('regenerate_csrf_token')) {
    function regenerate_csrf_token(): string {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            try {
                $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
            } catch (Throwable $e2) {
                $_SESSION['csrf_token'] = bin2hex(md5(uniqid((string)microtime(true), true)));
            }
        }
        $_SESSION['csrf_token_time'] = time();
        return (string) $_SESSION['csrf_token'];
    }
}