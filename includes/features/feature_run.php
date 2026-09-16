<?php
/**
 * includes/features/feature_run.php
 *
 * Load a shared panel feature module with role-specific config.
 *
 * Wrapper usage:
 *   require_once __DIR__ . '/../includes/features/feature_run.php';
 *   feature_run('parents_children', ['panel' => 'owner']);
 */
declare(strict_types=1);

/**
 * @param array<string,mixed> $config
 */
function feature_run(string $name, array $config = []): void
{
    $name = preg_replace('/[^a-z0-9_]/', '', strtolower($name));
    if ($name === '') {
        throw new InvalidArgumentException('Invalid feature name.');
    }

    $file = __DIR__ . '/' . $name . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('Feature not found: ' . $name);
    }

    $defaults = [
        'panel' => 'owner',
        'feature' => $name,
    ];

    $GLOBALS['FEATURE_CONFIG'] = array_merge($defaults, $config);
    require $file;
}

/**
 * @return array<string,mixed>
 */
function feature_config(): array
{
    return $GLOBALS['FEATURE_CONFIG'] ?? [];
}

function feature_panel(): string
{
    $cfg = feature_config();
    return (string) ($cfg['panel'] ?? 'owner');
}

function feature_cfg(string $key, mixed $default = null): mixed
{
    $cfg = feature_config();
    return $cfg[$key] ?? $default;
}
