<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function reception_panel_menu(): array
{
    $base = panel_base_url('reception');

    return [
        ['section' => 'Overview', 'items' => [
            ['url' => $base . '/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'match' => 'dashboard'],
        ]],
        ['section' => 'Admissions', 'items' => [
            ['url' => $base . '/admission.php', 'icon' => 'bi-person-plus', 'label' => 'Admission', 'match' => 'admission'],
            ['url' => $base . '/students_list.php', 'icon' => 'bi-people', 'label' => 'Students', 'match' => 'students'],
            ['url' => $base . '/enquiry_list.php', 'icon' => 'bi-chat-left-text', 'label' => 'Enquiries', 'match' => 'enquiry'],
        ]],
        ['section' => 'Daily Work', 'items' => [
            ['url' => $base . '/pending_tasks.php', 'icon' => 'bi-list-check', 'label' => 'Tasks', 'match' => 'pending_tasks'],
            ['url' => $base . '/pending_alerts.php', 'icon' => 'bi-bell', 'label' => 'Alerts', 'match' => 'pending_alerts'],
            ['url' => $base . '/notices_publish.php', 'icon' => 'bi-megaphone', 'label' => 'Notices', 'match' => 'notices'],
            ['url' => $base . '/news_events.php', 'icon' => 'bi-newspaper', 'label' => 'News & Events', 'match' => 'news_events'],
        ]],
        ['section' => 'Account', 'items' => [
            ['url' => $base . '/profile.php', 'icon' => 'bi-gear', 'label' => 'Profile', 'match' => 'profile'],
        ]],
    ];
}
