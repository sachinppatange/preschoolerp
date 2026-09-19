<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function teacher_panel_menu(): array
{
    $base = panel_base_url('teacher');

    return [
        ['section' => 'Overview', 'items' => [
            ['url' => $base . '/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'match' => 'dashboard'],
        ]],
        ['section' => 'My Classes', 'items' => [
            ['url' => $base . '/my_classes.php', 'icon' => 'bi-journal-bookmark', 'label' => 'My Classes', 'match' => 'my_classes'],
            ['url' => $base . '/attendance_mark.php', 'icon' => 'bi-person-check', 'label' => 'Attendance', 'match' => 'attendance'],
            ['url' => $base . '/homeworks.php', 'icon' => 'bi-journal-text', 'label' => 'Homeworks', 'match' => 'homeworks'],
            ['url' => $base . '/student_remarks.php', 'icon' => 'bi-chat-square-text', 'label' => 'Remarks', 'match' => 'remarks'],
            ['url' => $base . '/class_photo_upload.php', 'icon' => 'bi-camera', 'label' => 'Class Photos', 'match' => 'class_photo'],
        ]],
        ['section' => 'Schedule', 'items' => [
            ['url' => $base . '/timetable.php', 'icon' => 'bi-calendar-week', 'label' => 'Timetable', 'match' => 'timetable'],
            ['url' => $base . '/tasks.php', 'icon' => 'bi-check2-square', 'label' => 'To-do', 'match' => 'tasks'],
            ['url' => $base . '/notices.php', 'icon' => 'bi-megaphone', 'label' => 'Notices', 'match' => 'notices'],
        ]],
        ['section' => 'Account', 'items' => [
            ['url' => $base . '/profile.php', 'icon' => 'bi-gear', 'label' => 'Profile', 'match' => 'profile'],
        ]],
    ];
}
