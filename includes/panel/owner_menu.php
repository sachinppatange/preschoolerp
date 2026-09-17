<?php
/**
 * Owner panel — unified Preschool Management System navigation.
 * Owner can access Reception, Accounts, Teacher and Parent modules from one login.
 *
 * @return array<int, array{section: string, items: array<int, array{url: string, icon: string, label: string, match?: string}>}>
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function owner_panel_menu(): array
{
    $owner = panel_base_url('owner');
    $reception = panel_base_url('reception');
    $accounts = panel_base_url('accounts');
    $teacher = panel_base_url('teacher');
    $parent = panel_base_url('parent');

    return [
        [
            'section' => 'Overview',
            'items' => [
                ['url' => $owner . '/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'match' => 'dashboard'],
            ],
        ],
        [
            'section' => 'Reception & Admissions',
            'items' => [
                ['url' => $reception . '/admission.php', 'icon' => 'bi-person-plus-fill', 'label' => 'New Admission', 'match' => 'admission'],
                ['url' => $owner . '/students_list.php', 'icon' => 'bi-people-fill', 'label' => 'Students', 'match' => 'students_list'],
                ['url' => $owner . '/enquiry_list.php', 'icon' => 'bi-chat-left-text', 'label' => 'Enquiries', 'match' => 'enquiry'],
                ['url' => $owner . '/pending_tasks.php', 'icon' => 'bi-list-check', 'label' => 'Reception Tasks', 'match' => 'pending_tasks'],
            ],
        ],
        [
            'section' => 'Accounts & Finance',
            'items' => [
                ['url' => $accounts . '/fees_collection.php', 'icon' => 'bi-cash-coin', 'label' => 'Collect Fees', 'match' => 'fees_collection'],
                ['url' => $accounts . '/collect_payment.php', 'icon' => 'bi-wallet2', 'label' => 'Collect Payment', 'match' => 'collect_payment'],
                ['url' => $owner . '/pending_fees.php', 'icon' => 'bi-clock-history', 'label' => 'Pending Fees', 'match' => 'pending_fees'],
                ['url' => $owner . '/daily_collection.php', 'icon' => 'bi-calendar-day', 'label' => 'Daily Collection', 'match' => 'daily_collection'],
                ['url' => $accounts . '/invoices.php', 'icon' => 'bi-receipt-cutoff', 'label' => 'Invoices', 'match' => 'invoices'],
                ['url' => $owner . '/expense.php', 'icon' => 'bi-graph-down-arrow', 'label' => 'Expenses', 'match' => 'expense'],
                ['url' => $owner . '/monthly_summary.php', 'icon' => 'bi-calendar-check', 'label' => 'Monthly Summary', 'match' => 'monthly_summary'],
                ['url' => $owner . '/fees_setup.php', 'icon' => 'bi-sliders', 'label' => 'Fees Setup', 'match' => 'fees_setup'],
                ['url' => $accounts . '/fees_collectionbulk.php', 'icon' => 'bi-file-earmark-arrow-up', 'label' => 'Bulk Fee Collection', 'match' => 'fees_collectionbulk'],
                ['url' => $accounts . '/reports.php', 'icon' => 'bi-bar-chart-line', 'label' => 'Accounts Reports', 'match' => 'reports'],
            ],
        ],
        [
            'section' => 'Teacher & Academics',
            'items' => [
                ['url' => $owner . '/class_setup.php', 'icon' => 'bi-book', 'label' => 'Class Setup', 'match' => 'class_setup'],
                ['url' => $owner . '/teacher_assign.php', 'icon' => 'bi-person-workspace', 'label' => 'Assign Teachers', 'match' => 'teacher_assign'],
                ['url' => $owner . '/teacher_hub.php', 'icon' => 'bi-grid-3x3-gap', 'label' => 'Teacher Hub', 'match' => 'teacher_hub'],
                ['url' => $teacher . '/my_classes.php', 'icon' => 'bi-journal-bookmark', 'label' => 'My Classes', 'match' => 'my_classes'],
                ['url' => $teacher . '/attendance_mark.php', 'icon' => 'bi-person-check', 'label' => 'Mark Attendance', 'match' => 'attendance_mark'],
                ['url' => $owner . '/attendance.php', 'icon' => 'bi-clipboard-data', 'label' => 'Attendance Records', 'match' => 'attendance'],
                ['url' => $teacher . '/homeworks.php', 'icon' => 'bi-journal-text', 'label' => 'Homeworks', 'match' => 'homeworks'],
                ['url' => $teacher . '/student_remarks.php', 'icon' => 'bi-chat-square-text', 'label' => 'Student Remarks', 'match' => 'student_remarks'],
                ['url' => $teacher . '/class_photo_upload.php', 'icon' => 'bi-camera', 'label' => 'Class Photos', 'match' => 'class_photo'],
                ['url' => $teacher . '/timetable.php', 'icon' => 'bi-calendar-week', 'label' => 'Timetable', 'match' => 'timetable'],
                ['url' => $teacher . '/tasks.php', 'icon' => 'bi-kanban', 'label' => 'Teacher Tasks', 'match' => 'tasks'],
                ['url' => $teacher . '/notices.php', 'icon' => 'bi-megaphone', 'label' => 'Teacher Notices', 'match' => 'notices'],
                ['url' => $owner . '/sessions.php', 'icon' => 'bi-journal-bookmark-fill', 'label' => 'Sessions', 'match' => 'sessions'],
            ],
        ],
        [
            'section' => 'Parent Portal (View)',
            'items' => [
                ['url' => $owner . '/parent_portal.php', 'icon' => 'bi-eye', 'label' => 'Parent Hub', 'match' => 'parent_portal'],
                ['url' => $parent . '/children.php', 'icon' => 'bi-people', 'label' => 'My Children', 'match' => 'children'],
                ['url' => $parent . '/attendance.php', 'icon' => 'bi-calendar2-check', 'label' => 'Child Attendance', 'match' => 'attendance'],
                ['url' => $parent . '/homeworks.php', 'icon' => 'bi-journal', 'label' => 'Child Homework', 'match' => 'homeworks'],
                ['url' => $parent . '/fees.php', 'icon' => 'bi-cash-stack', 'label' => 'Child Fees', 'match' => 'fees'],
                ['url' => $parent . '/receipt.php', 'icon' => 'bi-receipt', 'label' => 'Fee Receipts', 'match' => 'receipt'],
                ['url' => $parent . '/notices.php', 'icon' => 'bi-bell', 'label' => 'School Notices', 'match' => 'notices'],
                ['url' => $parent . '/events.php', 'icon' => 'bi-calendar-event', 'label' => 'Events', 'match' => 'events'],
                ['url' => $parent . '/gallery.php', 'icon' => 'bi-images', 'label' => 'Gallery', 'match' => 'gallery'],
            ],
        ],
        [
            'section' => 'Communication',
            'items' => [
                ['url' => $owner . '/notices_publish.php', 'icon' => 'bi-megaphone-fill', 'label' => 'Publish Notices', 'match' => 'notices_publish'],
                ['url' => $owner . '/news_events.php', 'icon' => 'bi-newspaper', 'label' => 'News & Events', 'match' => 'news_events'],
                ['url' => $owner . '/feedbacks.php', 'icon' => 'bi-chat-dots', 'label' => 'Feedbacks', 'match' => 'feedbacks'],
                ['url' => $owner . '/complaints.php', 'icon' => 'bi-exclamation-triangle', 'label' => 'Complaints', 'match' => 'complaints'],
                ['url' => $owner . '/pending_alerts.php', 'icon' => 'bi-bell-fill', 'label' => 'Alerts', 'match' => 'pending_alerts'],
                ['url' => $owner . '/pending_notifications.php', 'icon' => 'bi-bell', 'label' => 'Notifications', 'match' => 'pending_notifications'],
            ],
        ],
        [
            'section' => 'Website CMS',
            'items' => [
                ['url' => $owner . '/content_about.php', 'icon' => 'bi-file-earmark-text', 'label' => 'About Page', 'match' => 'content_about'],
                ['url' => $owner . '/school_profile.php', 'icon' => 'bi-building', 'label' => 'School Profile', 'match' => 'school_profile'],
                ['url' => $owner . '/content_facilities.php', 'icon' => 'bi-houses', 'label' => 'Facilities', 'match' => 'content_facilities'],
                ['url' => $owner . '/content_photos.php', 'icon' => 'bi-images', 'label' => 'Gallery CMS', 'match' => 'content_photos'],
                ['url' => $owner . '/content_testimonialsfaq.php', 'icon' => 'bi-chat-quote', 'label' => 'Testimonials & FAQ', 'match' => 'content_testimonials'],
                ['url' => $owner . '/contact_details.php', 'icon' => 'bi-telephone', 'label' => 'Contact Details', 'match' => 'contact_details'],
                ['url' => $owner . '/popup_settings.php', 'icon' => 'bi-window-stack', 'label' => 'Popup Settings', 'match' => 'popup_settings'],
            ],
        ],
        [
            'section' => 'Academic Year',
            'items' => [
                ['url' => $owner . '/academic_year_hub.php', 'icon' => 'bi-calendar2-range', 'label' => 'Year Hub (Rollover)', 'match' => 'academic_year_hub'],
                ['url' => $owner . '/year_end_report.php', 'icon' => 'bi-file-earmark-pdf', 'label' => 'Year-end PDF', 'match' => 'year_end_report'],
            ],
        ],
        [
            'section' => 'Settings & Admin',
            'items' => [
                ['url' => $owner . '/otp_settings.php', 'icon' => 'bi-shield-lock', 'label' => 'OTP Settings', 'match' => 'otp_settings'],
                ['url' => $owner . '/staff_manage.php', 'icon' => 'bi-person-badge', 'label' => 'Staff & Users', 'match' => 'staff_manage'],
                ['url' => $owner . '/reports_simple.php', 'icon' => 'bi-pie-chart', 'label' => 'Reports', 'match' => 'reports_simple'],
                ['url' => $owner . '/profile.php', 'icon' => 'bi-gear', 'label' => 'My Profile', 'match' => 'profile'],
            ],
        ],
    ];
}
