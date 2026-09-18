<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function accounts_panel_menu(): array
{
    $base = panel_base_url('accounts');

    return [
        ['section' => 'Overview', 'items' => [
            ['url' => $base . '/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'match' => 'dashboard'],
        ]],
        ['section' => 'Fees Collection', 'items' => [
            ['url' => $base . '/fees_collection.php', 'icon' => 'bi-cash-coin', 'label' => 'Collect Fees', 'match' => 'fees_collection'],
            ['url' => $base . '/pending_fees.php', 'icon' => 'bi-clock-history', 'label' => 'Pending Fees', 'match' => 'pending_fees'],
            ['url' => $base . '/daily_collection.php', 'icon' => 'bi-calendar-day', 'label' => 'Daily Collection', 'match' => 'daily_collection'],
            ['url' => $base . '/invoices.php', 'icon' => 'bi-receipt', 'label' => 'Invoices', 'match' => 'invoices'],
        ]],
        ['section' => 'Finance', 'items' => [
            ['url' => $base . '/expenses.php', 'icon' => 'bi-graph-down-arrow', 'label' => 'Expenses', 'match' => 'expenses'],
            ['url' => $base . '/monthly_summary.php', 'icon' => 'bi-calendar-check', 'label' => 'Monthly Summary', 'match' => 'monthly_summary'],
            ['url' => $base . '/reports.php', 'icon' => 'bi-bar-chart-line', 'label' => 'Reports', 'match' => 'reports'],
        ]],
        ['section' => 'Account', 'items' => [
            ['url' => $base . '/profile.php', 'icon' => 'bi-gear', 'label' => 'Profile', 'match' => 'profile'],
        ]],
    ];
}
