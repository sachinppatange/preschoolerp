<?php
/**
 * Shared helpers for owner CMS / website content features.
 */
declare(strict_types=1);

/**
 * @return array<mixed>
 */
function cms_decode_json_field(mixed $val): array
{
    if ($val === null || $val === '') {
        return [];
    }
    if (is_array($val)) {
        return $val;
    }
    $decoded = json_decode((string) $val, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return $decoded;
    }
    return [];
}

/**
 * Filesystem path to assets/uploads (creates if needed).
 */
function cms_upload_dir(): string|false
{
    $appRoot = realpath(dirname(__DIR__, 2));
    if ($appRoot === false) {
        $appRoot = dirname(__DIR__, 2);
    }
    $dir = $appRoot . '/assets/uploads';

    if (is_dir($dir) && is_writable($dir)) {
        return $dir;
    }
    if (!is_dir($dir) && @mkdir($dir, 0775, true)) {
        @chmod($dir, 0775);
        return $dir;
    }
    return is_dir($dir) && is_writable($dir) ? $dir : false;
}

function cms_public_upload_url(string $filename): string
{
    return rtrim(site_url('/assets/uploads/' . ltrim($filename, '/')), '/');
}

function cms_resolve_image_url(string $path): string
{
    if (function_exists('resolve_image_url')) {
        return resolve_image_url($path);
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return site_url('/' . ltrim($path, '/'));
}

/**
 * Map a public URL back to filesystem path under app root.
 */
function cms_fs_path_from_url(string $url): string
{
    $base = site_url('');
    $relative = (str_starts_with($url, $base))
        ? substr($url, strlen($base))
        : ltrim($url, '/');
    $appRoot = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
    return $appRoot . '/' . ltrim($relative, '/');
}
