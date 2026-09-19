<?php
/**
 * owner/contact_details.php — phone, address, hours, map, social (public website).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
require_once __DIR__ . '/../includes/cms/helpers.php';

$pageTitle = 'Phone, map & hours';
$page_title = $pageTitle;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

if (!function_exists('sanitize_map_embed')) {
    function sanitize_map_embed(string $html): string
    {
        $html = preg_replace('#<\s*(script|style).*?>.*?<\s*/\s*\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('/\son\w+=(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $clean = strip_tags($html, '<iframe><p><br><strong><em><a>');
        if (!str_contains(strtolower($clean), '<iframe')) {
            return trim($clean);
        }
        return preg_replace_callback('#<iframe\b([^>]*)>(.*?)</iframe>#is', static function ($m) {
            $attrStr = $m[1] ?? '';
            preg_match_all('/([\w:\-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>]+))/i', $attrStr, $matches, PREG_SET_ORDER);
            $attrs = [];
            foreach ($matches as $mm) {
                $k = strtolower((string) ($mm[1] ?? ''));
                $v = (string) ($mm[2] ?? ($mm[3] ?? ($mm[4] ?? '')));
                if ($k === 'src' && preg_match('#^https?://#i', $v) && !str_contains(strtolower($v), 'javascript:')) {
                    $attrs['src'] = $v;
                } elseif (in_array($k, ['width', 'height', 'allowfullscreen', 'loading', 'style', 'frameborder', 'referrerpolicy'], true)) {
                    $attrs[$k] = $v;
                }
            }
            if (empty($attrs['src'])) {
                return '';
            }
            $out = '';
            foreach ($attrs as $k => $v) {
                $out .= ' ' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '"';
            }
            return '<iframe' . $out . '></iframe>';
        }, $clean) ?? '';
    }
}

$plainText = static function (string $raw): string {
    $t = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t) ?? $t;
    $t = preg_replace('/<\/\s*(p|div|h[1-6]|li)\s*>/i', "\n", $t) ?? $t;
    $t = strip_tags($t);
    $t = preg_replace('/^\s*Address\s*:\s*/im', '', $t) ?? $t;
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace("/[ \t]+/", ' ', $t) ?? $t;
    $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
    return trim($t);
};

$mapSrc = static function (string $html): string {
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $html) && !str_contains($html, '<')) {
        return $html;
    }
    if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
        return trim($m[1]);
    }
    return '';
};

$mapToEmbed = static function (string $raw) use ($mapSrc): string {
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $src = $mapSrc($raw);
    if ($src !== '' && preg_match('#^https?://#i', $src)) {
        return sanitize_map_embed('<iframe src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" width="600" height="450" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>');
    }
    return sanitize_map_embed($raw);
};

$digits10 = static function (string $raw): string {
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    return strlen($d) >= 10 ? substr($d, -10) : $d;
};

$school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: [];
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_contact') {
    if (function_exists('validate_csrf_token') && !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } else {
        $address = $plainText((string) ($_POST['address'] ?? ''));
        $mapEmbed = $mapToEmbed((string) ($_POST['map_embed'] ?? ''));
        $phone = trim((string) ($_POST['contact_phone'] ?? ''));
        $whatsapp = trim((string) ($_POST['contact_whatsapp'] ?? ''));
        $email = trim((string) ($_POST['contact_email'] ?? ''));
        $hours = trim((string) ($_POST['opening_hours'] ?? ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email, or leave it blank.';
        }
        $phoneDigits = $digits10($phone);
        if ($phone !== '' && strlen($phoneDigits) < 10) {
            $errors[] = 'Phone should be a 10-digit number.';
        }
        $waDigits = $digits10($whatsapp);
        if ($whatsapp !== '' && strlen($waDigits) < 10 && !preg_match('#^https?://#i', $whatsapp)) {
            $errors[] = 'WhatsApp should be a 10-digit number.';
        }

        $social = cms_decode_json_field($school['social_links'] ?? null);
        foreach (['facebook', 'instagram', 'youtube', 'google_map'] as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($v === '') {
                unset($social[$k]);
            } else {
                $social[$k] = $v;
            }
        }
        if ($waDigits !== '' && strlen($waDigits) >= 10) {
            $social['whatsapp'] = 'https://wa.me/91' . $waDigits;
            $whatsapp = $waDigits;
        } elseif ($whatsapp === '') {
            unset($social['whatsapp']);
        }

        if ($errors === []) {
            $params = [
                ':address' => $address,
                ':map' => $mapEmbed,
                ':phone' => $phoneDigits !== '' ? $phoneDigits : $phone,
                ':whatsapp' => $whatsapp,
                ':email' => $email,
                ':hours' => $hours,
                ':social' => json_encode($social, JSON_UNESCAPED_UNICODE),
            ];
            $ok = false;
            if ($school !== []) {
                $ok = (bool) safe_db_run(
                    'UPDATE schools SET address = :address, map_embed = :map, contact_phone = :phone,
                     contact_whatsapp = :whatsapp, contact_email = :email, opening_hours = :hours, social_links = :social, updated_at = NOW()
                     WHERE id = 1',
                    $params
                );
            } else {
                $defaultName = defined('APP_NAME') ? (string) APP_NAME : 'Preschool';
                $params[':name'] = $defaultName;
                $ok = (bool) safe_db_run(
                    'INSERT INTO schools (id, name, address, map_embed, contact_phone, contact_whatsapp, contact_email, opening_hours, social_links, created_at, updated_at)
                     VALUES (1, :name, :address, :map, :phone, :whatsapp, :email, :hours, :social, NOW(), NOW())',
                    $params
                );
            }
            if ($ok) {
                $success = 'Saved. Parents will see this on the website.';
                $school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: $school;
            } else {
                $errors[] = 'Could not save. Try again.';
            }
        }
    }
}

$addressVal = $plainText((string) ($school['address'] ?? ''));
$mapVal = (string) ($school['map_embed'] ?? '');
$mapInput = $mapSrc($mapVal);
$phoneVal = (string) ($school['contact_phone'] ?? '');
$waVal = (string) ($school['contact_whatsapp'] ?? '');
$emailVal = (string) ($school['contact_email'] ?? '');
$hoursVal = (string) ($school['opening_hours'] ?? '');
$social = cms_decode_json_field($school['social_links'] ?? null);
if ($waVal === '' && !empty($social['whatsapp'])) {
    $waVal = $digits10((string) $social['whatsapp']);
}
$publicLoc = function_exists('site_url') ? site_url('/#location') : '/#location';

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.cd-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:16px 18px; margin-bottom:14px; }
.cd-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:16px 18px; margin-bottom:12px; }
.cd-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin-bottom:10px; }
.cd-map { border-radius:14px; overflow:hidden; background:#e2e8f0; }
.cd-map iframe { width:100%; height:220px; border:0; display:block; }
.cd-bar { position:sticky; bottom:0; background:#fff; border-top:1px solid #dbe7fb; padding:10px 0; z-index:2; }
</style>

<div class="cd-hero d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <div class="fw-bold" style="font-size:1.15rem">Phone, map &amp; hours</div>
    <div class="text-muted">How parents reach the school from the public website.</div>
  </div>
  <a class="btn btn-outline-primary" href="<?php echo e($publicLoc); ?>" target="_blank" rel="noopener">See on website</a>
</div>

<?php if ($success !== ''): ?><div class="alert alert-success py-2"><?php echo e($success); ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<form method="post">
  <input type="hidden" name="action" value="save_contact">
  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">

  <div class="cd-card">
    <div class="cd-sec">Call &amp; visit</div>
    <label class="form-label">School address</label>
    <textarea name="address" class="form-control mb-3" rows="3" placeholder="Building, road, city, pin"><?php echo e($addressVal); ?></textarea>
    <div class="row g-2">
      <div class="col-md-4">
        <label class="form-label">Phone</label>
        <input name="contact_phone" class="form-control" inputmode="tel" value="<?php echo e($phoneVal); ?>" placeholder="10-digit number">
      </div>
      <div class="col-md-4">
        <label class="form-label">WhatsApp</label>
        <input name="contact_whatsapp" class="form-control" inputmode="tel" value="<?php echo e($waVal); ?>" placeholder="Same or another 10-digit number">
      </div>
      <div class="col-md-4">
        <label class="form-label">Email</label>
        <input name="contact_email" type="email" class="form-control" value="<?php echo e($emailVal); ?>" placeholder="office@school.in">
      </div>
    </div>
    <label class="form-label mt-3">When the office is open</label>
    <input name="opening_hours" class="form-control" value="<?php echo e($hoursVal); ?>" placeholder="Mon–Sat 9:00 am – 1:00 pm">
  </div>

  <div class="cd-card">
    <div class="cd-sec">Map</div>
    <label class="form-label">Google Maps link</label>
    <input name="map_embed" class="form-control" value="<?php echo e($mapInput); ?>" placeholder="Paste Share → Embed map link, or a maps.google.com URL">
    <div class="form-text mb-2">On Google Maps: Share → Embed a map → copy the link inside the box. The map shows on the website after Save.</div>
    <?php if (trim($mapVal) !== ''): ?>
      <div class="cd-map"><?php echo sanitize_map_embed($mapVal); ?></div>
    <?php endif; ?>
  </div>

  <div class="cd-card">
    <div class="cd-sec">Social pages</div>
    <div class="form-text mb-2">Leave blank if you do not use that page. Full links, like https://instagram.com/yourschool</div>
    <label class="form-label">Instagram</label>
    <input name="instagram" class="form-control mb-2" value="<?php echo e((string) ($social['instagram'] ?? '')); ?>" placeholder="https://instagram.com/…">
    <label class="form-label">Facebook</label>
    <input name="facebook" class="form-control mb-2" value="<?php echo e((string) ($social['facebook'] ?? '')); ?>" placeholder="https://facebook.com/…">
    <label class="form-label">YouTube</label>
    <input name="youtube" class="form-control mb-2" value="<?php echo e((string) ($social['youtube'] ?? '')); ?>" placeholder="https://youtube.com/…">
    <label class="form-label">Open in Google Maps (button)</label>
    <input name="google_map" class="form-control" value="<?php echo e((string) ($social['google_map'] ?? '')); ?>" placeholder="https://maps.google.com/…">
  </div>

  <div class="cd-bar">
    <button class="btn btn-success" type="submit">Save</button>
  </div>
</form>
<?php
require_once __DIR__ . '/../includes/footer.php';
