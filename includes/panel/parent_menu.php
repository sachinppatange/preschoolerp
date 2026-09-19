<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function parent_panel_menu(): array
{
    $base = panel_base_url('parent');

    return [
        ['section' => 'Overview', 'items' => [
            ['url' => $base . '/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'match' => 'dashboard'],
            ['url' => $base . '/children.php', 'icon' => 'bi-people', 'label' => 'My Children', 'match' => 'children'],
            ['url' => $base . '/id_card.php', 'icon' => 'bi-person-badge', 'label' => 'ID card', 'match' => 'id_card'],
        ]],
        ['section' => 'School Life', 'items' => [
            ['url' => $base . '/attendance.php', 'icon' => 'bi-person-check', 'label' => 'Attendance', 'match' => 'attendance'],
            ['url' => $base . '/homeworks.php', 'icon' => 'bi-journal-text', 'label' => 'Homeworks', 'match' => 'homeworks'],
            ['url' => $base . '/notices.php', 'icon' => 'bi-megaphone', 'label' => 'Notices', 'match' => 'notices'],
            ['url' => $base . '/events.php', 'icon' => 'bi-calendar-event', 'label' => 'Events', 'match' => 'events'],
            ['url' => $base . '/gallery.php', 'icon' => 'bi-images', 'label' => 'Gallery', 'match' => 'gallery'],
        ]],
        ['section' => 'Fees', 'items' => [
            ['url' => $base . '/fees.php', 'icon' => 'bi-cash-stack', 'label' => 'Fees', 'match' => 'fees'],
        ]],
        ['section' => 'Account', 'items' => [
            ['url' => $base . '/profile.php', 'icon' => 'bi-gear', 'label' => 'Profile', 'match' => 'profile'],
        ]],
    ];
}
