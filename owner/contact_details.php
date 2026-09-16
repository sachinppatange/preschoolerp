<?php
/**
 * owner/contact_details.php
 *
 * Edit Contact Details & Map for the school (id = 1).
 * Fixed:
 * - Regex character class issue (escaped hyphen) to avoid preg_match_all compilation error.
 * - Robust iframe attribute parsing (guards for no matches).
 * - Uses UPDATE when school row exists; INSERT with required defaults only when missing,
 *   to avoid "Field 'name' doesn't have a default value" errors.
 *
 * Place this file at:
 * /Applications/XAMPP/xamppfiles/htdocs/pioneerplayschool/owner/contact_details.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

session_start();

function app_base(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/pioneerplayschool/owner/contact_details.php';
    $ownerDir = rtrim(dirname($script), '/');
    $base = rtrim(dirname($ownerDir), '/'); // /pioneerplayschool
    if ($base === '' || $base === '.') $base = '';
    return $base;
}
function site_url(string $path = ''): string
{
    $base = app_base();
    if ($path === '') return $base ?: '/';
    $p = ($path[0] === '/') ? $path : ('/' . ltrim($path, '/'));
    return ($base === '' ? $p : $base . $p);
}

if (!function_exists('db_get_one')) {
    function db_get_one(string $sql, array $params = []) {
        if (is_callable('db_fetch_one')) return call_user_func('db_fetch_one', $sql, $params);
        $pdo = ensure_pdo();
        if ($pdo instanceof \PDO) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        }
        if (is_callable('db_query')) return call_user_func('db_query', $sql, $params);
        return null;
    }
}

if (!function_exists('db_run')) {
    function db_run(string $sql, array $params = []): bool {
        if (is_callable('db_execute')) return (bool) call_user_func('db_execute', $sql, $params);
        $pdo = ensure_pdo();
        if ($pdo instanceof \PDO) {
            $stmt = $pdo->prepare($sql);
            return (bool) $stmt->execute($params);
        }
        return false;
    }
}

/* -------------------------
   Sanitizer for map embed
   Allow only <iframe>, <p>, <br>, <strong>, <em>, <a>
   and strip any script tags or on* attributes.
   ------------------------- */
if (!function_exists('sanitize_map_embed')) {
    function sanitize_map_embed(string $html): string {
        // Strip script/style
        $html = preg_replace('#<\s*(script|style).*?>.*?<\s*/\s*\1\s*>#is', '', $html);

        // Remove event handler attributes like onclick, onload...
        $html = preg_replace('/\son\w+=(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

        // Allow only a small set of tags
        $allowed = '<iframe><p><br><strong><em><a>';
        $clean = strip_tags($html, $allowed);

        // For iframe, ensure src uses https or http and doesn't contain javascript:
        // Replace any iframes with cleaned attributes subset.
        if (strpos($clean, '<iframe') !== false) {
            // Parse iframes and rebuild safe ones
            $clean = preg_replace_callback('#<iframe\b([^>]*)>(.*?)</iframe>#is', function($m) {
                $attrStr = $m[1] ?? '';
                // collect src, width, height, style, allowfullscreen, loading, frameborder, referrerpolicy
                // Note: escaped hyphen in character class to avoid "invalid range" issues.
                $matches = [];
                preg_match_all('/([\w:\-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>]+))/i', $attrStr, $matches, PREG_SET_ORDER);
                $attrs = [];
                if (is_array($matches) && count($matches) > 0) {
                    foreach ($matches as $mm) {
                        $k = strtolower($mm[1] ?? '');
                        $v = $mm[2] ?? ($mm[3] ?? ($mm[4] ?? ''));
                        if ($k === 'src') {
                            // allow only http(s) src
                            if (preg_match('#^https?://#i', $v)) $attrs['src'] = $v;
                        } elseif (in_array($k, ['width','height','allowfullscreen','loading','style','frameborder','referrerpolicy'])) {
                            $attrs[$k] = $v;
                        }
                    }
                }
                if (empty($attrs['src'])) return ''; // drop unsafe iframe
                $outAttrs = '';
                foreach ($attrs as $k=>$v) {
                    $outAttrs .= ' ' . e($k) . '="' . e($v) . '"';
                }
                return '<iframe' . $outAttrs . '></iframe>';
            }, $clean);
        }

        return $clean;
    }
}

/* -------------------------
   Load current school values (id = 1)
   ------------------------- */
$school = db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]) ?: [];

/* -------------------------
   Handle form submit
   ------------------------- */
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_contact') {
    $address = trim((string)($_POST['address'] ?? ''));
    $map_embed_raw = trim((string)($_POST['map_embed'] ?? ''));
    $contact_phone = trim((string)($_POST['contact_phone'] ?? ''));
    $contact_whatsapp = trim((string)($_POST['contact_whatsapp'] ?? ''));
    $contact_email = trim((string)($_POST['contact_email'] ?? ''));
    $opening_hours = trim((string)($_POST['opening_hours'] ?? ''));

    // Social links: prefer valid JSON textarea if provided
    $social_json_input = trim((string)($_POST['social_json'] ?? ''));
    $social_obj = [];
    if ($social_json_input !== '') {
        $decoded = json_decode($social_json_input, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $social_obj = $decoded;
        } else {
            $errors[] = 'Social links JSON is invalid. Please correct or use individual fields below.';
        }
    } else {
        // Build from individual fields
        $social_obj = [];
        foreach (['facebook','instagram','youtube','twitter','linkedin'] as $k) {
            $val = trim((string)($_POST[$k] ?? ''));
            if ($val !== '') $social_obj[$k] = $val;
        }
    }

    // Basic validation
    if ($contact_email !== '' && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Contact email is not a valid email address.';
    }
    if ($contact_phone !== '' && !preg_match('/^[\d+\-\s()]{6,}$/', $contact_phone)) {
        $errors[] = 'Contact phone looks invalid.';
    }
    if ($contact_whatsapp !== '' && !preg_match('/^[\d+\-\s()]{6,}$/', $contact_whatsapp)) {
        // allow empty or similar phone format
        $errors[] = 'WhatsApp number looks invalid.';
    }

    if (empty($errors)) {
        // Save sanitized map embed to DB (we store sanitized version)
        $map_sanitized = sanitize_map_embed($map_embed_raw);

        // If school row exists -> UPDATE, else INSERT with required defaults
        if (!empty($school)) {
            $sql = "UPDATE schools SET address = :address, map_embed = :map, contact_phone = :phone,
                    contact_whatsapp = :whatsapp, contact_email = :email, opening_hours = :hours, social_links = :social, updated_at = NOW()
                    WHERE id = 1";
            $params = [
                ':address' => $address,
                ':map' => $map_sanitized,
                ':phone' => $contact_phone,
                ':whatsapp' => $contact_whatsapp,
                ':email' => $contact_email,
                ':hours' => $opening_hours,
                ':social' => json_encode($social_obj),
            ];
            $ok = db_run($sql, $params);
        } else {
            // Provide minimal required columns to avoid SQL strict errors (e.g., name may be NOT NULL)
            $default_name = 'Pioneer Play School';
            $sql = "INSERT INTO schools (id, name, address, map_embed, contact_phone, contact_whatsapp, contact_email, opening_hours, social_links, created_at, updated_at)
                    VALUES (1, :name, :address, :map, :phone, :whatsapp, :email, :hours, :social, NOW(), NOW())";
            $params = [
                ':name' => $default_name,
                ':address' => $address,
                ':map' => $map_sanitized,
                ':phone' => $contact_phone,
                ':whatsapp' => $contact_whatsapp,
                ':email' => $contact_email,
                ':hours' => $opening_hours,
                ':social' => json_encode($social_obj),
            ];
            $ok = db_run($sql, $params);
        }

        if ($ok) {
            $success = 'Contact details saved successfully.';
            // reload
            $school = db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]) ?: $school;
        } else {
            $errors[] = 'Database error while saving contact details.';
        }
    }
}

/* Prepare values for form display */
$address_val = $school['address'] ?? '';
$map_embed_val = $school['map_embed'] ?? '';
$contact_phone_val = $school['contact_phone'] ?? '';
$contact_whatsapp_val = $school['contact_whatsapp'] ?? '';
$contact_email_val = $school['contact_email'] ?? '';
$opening_hours_val = $school['opening_hours'] ?? '';
$social_links_val = $school['social_links'] ?? '';
$social_arr = [];
if (!empty($social_links_val)) {
    $tmp = json_decode((string)$social_links_val, true);
    if (is_array($tmp)) $social_arr = $tmp;
}
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Owner — Contact Details</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Contact Details & Map</h2>
    <div>
      <a class="btn btn-outline-secondary" href="<?php echo e(site_url('/owner/dashboard.php')); ?>">Dashboard</a>
      <a class="btn btn-outline-danger" href="<?php echo e(site_url('/owner/logout.php')); ?>">Logout</a>
    </div>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success"><?php echo e($success); ?></div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="card p-3">
    <input type="hidden" name="action" value="save_contact">

    <div class="mb-3">
      <label class="form-label">Address</label>
      <textarea name="address" class="form-control" rows="2"><?php echo e($address_val); ?></textarea>
    </div>

    <div class="mb-3">
      <label class="form-label">Map Embed (iframe HTML)</label>
      <textarea name="map_embed" class="form-control" rows="4" placeholder="Paste Google Maps iframe embed code"><?php echo e($map_embed_val); ?></textarea>
      <div class="form-text">We sanitize the embed for safety; preview appears below after saving.</div>
    </div>

    <div class="row g-2 mb-3">
      <div class="col-md-4">
        <label class="form-label">Contact Phone</label>
        <input name="contact_phone" class="form-control" value="<?php echo e($contact_phone_val); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">WhatsApp</label>
        <input name="contact_whatsapp" class="form-control" value="<?php echo e($contact_whatsapp_val); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Contact Email</label>
        <input name="contact_email" class="form-control" value="<?php echo e($contact_email_val); ?>">
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label">Opening Hours</label>
      <input name="opening_hours" class="form-control" value="<?php echo e($opening_hours_val); ?>">
    </div>

    <hr>

    <h5>Social Links</h5>
    <p class="small text-muted">You can paste a JSON object here or fill the individual fields below. JSON takes precedence.</p>
    <div class="mb-3">
      <label class="form-label">Social Links (JSON)</label>
      <textarea name="social_json" class="form-control" rows="4"><?php echo e($social_links_val); ?></textarea>
    </div>

    <div class="row g-2 mb-3">
      <div class="col-md-4"><label class="form-label">Facebook</label><input name="facebook" class="form-control" value="<?php echo e($social_arr['facebook'] ?? ''); ?>"></div>
      <div class="col-md-4"><label class="form-label">Instagram</label><input name="instagram" class="form-control" value="<?php echo e($social_arr['instagram'] ?? ''); ?>"></div>
      <div class="col-md-4"><label class="form-label">YouTube</label><input name="youtube" class="form-control" value="<?php echo e($social_arr['youtube'] ?? ''); ?>"></div>
    </div>

    <div class="mb-3">
      <button class="btn btn-primary">Save Contact Details</button>
      <a class="btn btn-secondary" href="<?php echo e(site_url('/owner/dashboard.php')); ?>">Back</a>
    </div>
  </form>

  <!-- Map preview (sanitized) -->
  <?php if (!empty($map_embed_val)): ?>
    <div class="card mt-4 p-3">
      <h5 class="mb-3">Map Preview</h5>
      <div class="ratio ratio-16x9">
        <?php
          // Render sanitized HTML (it is already sanitized on save)
          echo $map_embed_val;
        ?>
      </div>
    </div>
  <?php endif; ?>

</div>
</body>
</html>