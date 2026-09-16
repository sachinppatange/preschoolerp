<?php
/**
 * includes/flash.php
 *
 * Simple universal flash messaging helpers.
 * - set_flash($type, $message) to queue a message (type: success, error, info, warning)
 * - get_flashes() returns queued messages and clears them (used by templates)
 *
 * Stored in $_SESSION['_flash'] as array of ['type'=>'success','msg'=>'...']
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Queue a flash message.
 * @param string $type
 * @param string $message
 */
function set_flash(string $type, string $message): void {
    if (!in_array($type, ['success','error','info','warning'], true)) {
        $type = 'info';
    }
    if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        $_SESSION['_flash'] = [];
    }
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $message];
}

/**
 * Get and clear queued flashes.
 * @return array
 */
function get_flashes(): array {
    if (empty($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        return [];
    }
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $f;
}