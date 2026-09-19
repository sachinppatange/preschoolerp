<?php
/**
 * index.php
 *
 * Public landing page for Pioneer Play School (complete updated version).
 *
 * - Uses school record (id=1) for content and social links (falls back to defaults).
 * - Keeps enquiry handling, news/events, gallery, testimonials, FAQs.
 * - Adds footer social "box" buttons and a compact bottom social bar (box-like buttons).
 *
 * Place at project root.
 */

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

/* Optional includes */
if (file_exists(__DIR__ . '/includes/config.php')) require_once __DIR__ . '/includes/config.php';
if (file_exists(__DIR__ . '/includes/db.php')) require_once __DIR__ . '/includes/db.php';
if (file_exists(__DIR__ . '/includes/functions.php')) require_once __DIR__ . '/includes/functions.php';

/* ---------------------------
   Helpers (guarded)
   --------------------------- */
if (!function_exists('ensure_pdo')) {
    function ensure_pdo(): ?\PDO {
        foreach (['pdo','db','dbh','DB'] as $g) {
            if (isset($GLOBALS[$g]) && $GLOBALS[$g] instanceof \PDO) { $GLOBALS['pdo'] = $GLOBALS[$g]; return $GLOBALS['pdo']; }
        }
        if (defined('DB_DSN')) {
            try {
                $user = defined('DB_USER') ? constant('DB_USER') : null;
                $pass = defined('DB_PASS') ? constant('DB_PASS') : null;
                $pdo = new \PDO(constant('DB_DSN'), $user, $pass, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            } catch (\PDOException $e) { return null; }
        }
        return null;
    }
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
        return null;
    }
}

if (!function_exists('db_get_all')) {
    function db_get_all(string $sql, array $params = []): array {
        if (is_callable('db_fetch_all')) return call_user_func('db_fetch_all', $sql, $params) ?: [];
        $pdo = ensure_pdo();
        if ($pdo instanceof \PDO) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        }
        return [];
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

if (!function_exists('e')) { function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }

if (!function_exists('sanitize_map_embed')) {
    function sanitize_map_embed(string $html): string {
        $html = preg_replace('#<\s*(script|style).*?>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = preg_replace('/\son\w+=(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $allowed = '<iframe><p><br><strong><em><a>';
        $clean = strip_tags($html, $allowed);
        if (strpos($clean, '<iframe') !== false) {
            $clean = preg_replace_callback('#<iframe\b([^>]*)>(.*?)</iframe>#is', function($m) {
                $attrStr = $m[1] ?? '';
                preg_match_all('/([\w:\-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>]+))/i', $attrStr, $matches, PREG_SET_ORDER);
                $attrs = [];
                foreach ($matches as $mm) {
                    $k = strtolower($mm[1] ?? '');
                    $v = $mm[2] ?? ($mm[3] ?? ($mm[4] ?? ''));
                    if ($k === 'src') {
                        if (preg_match('#^https?://#i', $v)) $attrs['src'] = $v;
                    } elseif (in_array($k, ['width','height','allowfullscreen','loading','style','frameborder','referrerpolicy'])) {
                        $attrs[$k] = $v;
                    }
                }
                if (empty($attrs['src'])) return '';
                $out = '';
                foreach ($attrs as $k=>$v) $out .= ' ' . e($k) . '="' . e($v) . '"';
                return '<iframe' . $out . '></iframe>';
            }, $clean);
        }
        return $clean;
    }
}

if (!function_exists('decode_json_field')) {
    function decode_json_field($val) {
        if ($val === null || $val === '') return [];
        if (is_array($val)) return $val;
        $decoded = json_decode((string)$val, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
    }
}

/* ---------------------------
   Load school (id = 1)
   --------------------------- */
$school = null;
try {
    $school = db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]);
} catch (Throwable $e) {
    $school = null;
}
if (!$school) {
    $school = [
        'id'=>1,
        'name'=>'Pioneer Play School',
        'tagline'=>'Where Little Minds Grow Big Dreams',
        'logo_path'=>'/assets/images/logo.png',
        'hero_image'=>'/assets/images/hero.jpg',
        'hero_title'=>'Where Little Minds Grow Big Dreams 🌈',
        'hero_subtitle'=>'Safe, joyful and smart learning for your child.',
        'hero_cta_text'=>'Admission Enquiry',
        'hero_cta_url'=>'/#enquiry',
        'short_about'=>'Safe, joyful and smart learning for your child.',
        'long_about'=>'<p>Pioneer Play School provides a nurturing environment where children learn through play and guided discovery.</p>',
        'why_choose_us'=>null,
        'classes_offered'=>null,
        'facilities'=>null,
        'gallery'=>null,
        'testimonials'=>null,
        'faqs'=>null,
        'map_embed'=>'',
        'contact_phone'=>'',
        'contact_email'=>'',
        'opening_hours'=>'',
        'social_links'=>null,
        'settings'=>null,
    ];
}

/* Decode structured fields */
$settings = decode_json_field($school['settings'] ?? null);
$why = decode_json_field($school['why_choose_us'] ?? null);
$classes = decode_json_field($school['classes_offered'] ?? null);
$facilities = decode_json_field($school['facilities'] ?? null);
$gallery = decode_json_field($school['gallery'] ?? null);
$testimonials = decode_json_field($school['testimonials'] ?? null);
$faqs = decode_json_field($school['faqs'] ?? null);
$social = decode_json_field($school['social_links'] ?? null);

$normHttp = static function (string $url, string $kind = ''): string {
    $url = trim($url);
    if ($url === '' || $url === '#') {
        return '';
    }
    if ($kind === 'whatsapp' && !preg_match('#^https?://#i', $url)) {
        $d = preg_replace('/\D+/', '', $url) ?? '';
        if (strlen($d) >= 10) {
            if (!str_starts_with($d, '91') && strlen($d) === 10) {
                $d = '91' . $d;
            }
            return 'https://wa.me/' . $d;
        }
    }
    if (!preg_match('#^https?://#i', $url)) {
        return 'https://' . ltrim($url, '/');
    }
    return $url;
};

if (!is_array($social)) {
    $social = [];
}
$waFromContact = trim((string) ($school['contact_whatsapp'] ?? ''));
if (empty($social['whatsapp']) && $waFromContact !== '') {
    $social['whatsapp'] = $waFromContact;
}
foreach (['whatsapp', 'instagram', 'facebook', 'youtube', 'google_map'] as $k) {
    $social[$k] = $normHttp((string) ($social[$k] ?? ''), $k);
}

$aboutPhotoRaw = trim((string) ($school['about_image'] ?? ''));
if ($aboutPhotoRaw === '') {
    $aboutPhotoRaw = trim((string) ($settings['about_image'] ?? ''));
}
$hasAboutPhoto = $aboutPhotoRaw !== '';
$about_image = $hasAboutPhoto ? resolve_image_url($aboutPhotoRaw) : '';

$whyList = [];
foreach (is_array($why) ? $why : [] as $item) {
    if (!is_array($item)) {
        continue;
    }
    $wt = trim((string) ($item['title'] ?? ''));
    $wd = trim((string) ($item['description'] ?? ''));
    if ($wt !== '' || $wd !== '') {
        $whyList[] = ['title' => $wt, 'description' => $wd];
    }
}

$facList = [];
foreach (is_array($facilities) ? $facilities : [] as $fac) {
    if (is_string($fac) && trim($fac) !== '') {
        $facList[] = ['title' => trim($fac), 'description' => ''];
        continue;
    }
    if (!is_array($fac)) {
        continue;
    }
    $ft = trim((string) ($fac['title'] ?? $fac['name'] ?? ''));
    $fd = trim((string) ($fac['description'] ?? $fac['desc'] ?? ''));
    if ($ft !== '' || $fd !== '') {
        $facList[] = ['title' => $ft !== '' ? $ft : 'Facility', 'description' => $fd];
    }
}

$classList = [];
foreach (is_array($classes) ? $classes : [] as $c) {
    if (!is_array($c)) {
        continue;
    }
    $cn = trim((string) ($c['name'] ?? ''));
    if ($cn === '') {
        continue;
    }
    $classList[] = [
        'name' => $cn,
        'age' => trim((string) ($c['age'] ?? '')),
        'fees' => $c['fees'] ?? '',
    ];
}

$galleryList = [];
foreach (is_array($gallery) ? $gallery : [] as $g) {
    $gp = is_array($g) ? trim((string) ($g['url'] ?? $g['path'] ?? $g['src'] ?? '')) : trim((string) $g);
    if ($gp !== '') {
        $galleryList[] = $gp;
    }
}

$testiList = [];
foreach (is_array($testimonials) ? $testimonials : [] as $t) {
    if (!is_array($t)) {
        continue;
    }
    $tn = trim((string) ($t['name'] ?? ''));
    $tq = trim((string) ($t['quote'] ?? $t['text'] ?? ''));
    if ($tn === '' && $tq === '') {
        continue;
    }
    $testiList[] = [
        'name' => $tn,
        'role' => trim((string) ($t['role'] ?? '')),
        'quote' => $tq,
        'photo' => trim((string) ($t['photo'] ?? $t['image'] ?? '')),
    ];
}

$faqList = [];
foreach (is_array($faqs) ? $faqs : [] as $f) {
    if (!is_array($f)) {
        continue;
    }
    $fq = trim((string) ($f['q'] ?? $f['question'] ?? ''));
    $fa = trim((string) ($f['a'] ?? $f['answer'] ?? ''));
    if ($fq === '' && $fa === '') {
        continue;
    }
    $faqList[] = ['q' => $fq, 'a' => $fa];
}

$plainAddr = static function (string $raw): string {
    $t = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t) ?? $t;
    $t = preg_replace('/<\/\s*(p|div)\s*>/i', "\n", $t) ?? $t;
    $t = strip_tags($t);
    $t = preg_replace('/^\s*Address\s*:\s*/im', '', $t) ?? $t;
    return trim(preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $t)) ?? $t);
};

$page_title = trim((string) ($school['name'] ?? 'Preschool'));
$tagline = trim((string) ($school['tagline'] ?? ''));
$logo = resolve_image_url((string) ($school['logo_path'] ?? '/assets/images/logo.png'));
$hero = resolve_image_url((string) ($school['hero_image'] ?? $school['logo_path'] ?? '/assets/images/hero.jpg'));
$hero_title = trim((string) ($school['hero_title'] ?? '')) ?: ($tagline !== '' ? $tagline : $page_title);
$hero_subtitle = trim((string) ($school['hero_subtitle'] ?? '')) ?: trim((string) ($school['short_about'] ?? ''));
$hero_cta_text = trim((string) ($school['hero_cta_text'] ?? '')) ?: 'Admission Enquiry';
$hero_cta_url = trim((string) ($school['hero_cta_url'] ?? '')) ?: '#enquiry';
if ($hero_cta_url !== '' && $hero_cta_url[0] !== '#' && !preg_match('#^https?://#i', $hero_cta_url) && function_exists('site_url')) {
    $hero_cta_url = site_url($hero_cta_url);
}
$short_about = trim((string) ($school['short_about'] ?? ''));
$long_about = trim((string) ($school['long_about'] ?? ''));
$aboutHtml = $long_about !== ''
    ? (strip_tags($long_about) === $long_about ? nl2br(e($long_about)) : $long_about)
    : nl2br(e($short_about));
$map_embed_safe = sanitize_map_embed((string) ($school['map_embed'] ?? ''));
$contact_phone = trim((string) ($school['contact_phone'] ?? ($settings['contact_phone'] ?? '')));
$contact_email = trim((string) ($school['contact_email'] ?? ($settings['contact_email'] ?? '')));
$opening_hours = trim((string) ($school['opening_hours'] ?? ($settings['opening_hours'] ?? '')));
$address = $plainAddr((string) ($school['address'] ?? ''));
$phoneDigits = preg_replace('/\D+/', '', $contact_phone) ?? '';
$telHref = strlen($phoneDigits) >= 10 ? ('tel:+91' . substr($phoneDigits, -10)) : ($contact_phone !== '' ? 'tel:' . $phoneDigits : '');
$hero_alt = $page_title . ' photo';

/* ---------------------------
   Enquiry form handling (unchanged)
   --------------------------- */
$enq_errors = [];
$enq_success = '';
if (!empty($_GET['enq']) && $_GET['enq'] === 'ok') {
    $enq_success = 'Thank you — your enquiry has been received. We will contact you soon.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['enquire_action'] ?? '') === 'submit_enquiry') {
    $name = trim((string)($_POST['name'] ?? ''));
    $age_group = trim((string)($_POST['age_group'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));

    if ($name === '') $enq_errors[] = 'Please enter parent/guardian name.';
    if ($phone === '' || strlen($phone) < 6) $enq_errors[] = 'Please enter a valid phone number.';

    if (empty($enq_errors)) {
        try {
            $desired_source = 'website_enquiry';
            $final_source = $desired_source;
            $maxLen = 0;
            $enumValues = null;

            try {
                $pdoTmp = ensure_pdo();
                if ($pdoTmp instanceof \PDO) {
                    $sth = $pdoTmp->prepare("
                        SELECT CHARACTER_MAXIMUM_LENGTH, COLUMN_TYPE
                        FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'enquiries'
                          AND COLUMN_NAME = 'source' LIMIT 1
                    ");
                    $sth->execute();
                    $col = $sth->fetch(\PDO::FETCH_ASSOC);
                    if ($col) {
                        if (!empty($col['CHARACTER_MAXIMUM_LENGTH'])) $maxLen = (int)$col['CHARACTER_MAXIMUM_LENGTH'];
                        elseif (!empty($col['COLUMN_TYPE']) && stripos($col['COLUMN_TYPE'], 'enum(') === 0) {
                            preg_match_all("/'([^']*)'/", $col['COLUMN_TYPE'], $m);
                            $enumValues = $m[1] ?? [];
                        }
                    }
                }
            } catch (\Throwable $ignore) {}

            if (is_array($enumValues)) {
                if (!in_array($desired_source, $enumValues, true)) $final_source = $enumValues[0] ?? substr($desired_source, 0, 50);
            } else {
                if ($maxLen > 0 && strlen($desired_source) > $maxLen) $final_source = substr($desired_source, 0, $maxLen);
                else $final_source = (strlen($desired_source) > 100) ? substr($desired_source, 0, 100) : $desired_source;
            }

            $assigned_to = null;
            if (!empty($settings['default_assignee']) && is_numeric($settings['default_assignee'])) {
                $assigned_to = (int)$settings['default_assignee'];
            } else {
                try {
                    $row = db_get_one("
                        SELECT u.id FROM users u
                        WHERE u.active = 1
                        ORDER BY (
                          SELECT COUNT(*) FROM enquiries e
                          WHERE e.assigned_to = u.id AND (e.status IS NULL OR e.status <> 'closed')
                        ) ASC
                        LIMIT 1
                    ");
                    if ($row && isset($row['id'])) $assigned_to = (int)$row['id'];
                } catch (Throwable $ignore) { $assigned_to = null; }
            }

            $full_message = ($age_group ? "Age group: {$age_group}\n" : '') . $message;

            if ($assigned_to !== null) {
                $sql = "INSERT INTO enquiries (school_id, name, phone, source, message, assigned_to, status, created_at, updated_at)
                        VALUES (:school_id, :name, :phone, :source, :message, :assigned_to, 'new', NOW(), NOW())";
                $params = [
                    ':school_id' => $school['id'] ?? 1,
                    ':name' => $name,
                    ':phone' => $phone,
                    ':source' => $final_source,
                    ':message' => $full_message,
                    ':assigned_to' => $assigned_to
                ];
            } else {
                $sql = "INSERT INTO enquiries (school_id, name, phone, source, message, assigned_to, status, created_at, updated_at)
                        VALUES (:school_id, :name, :phone, :source, :message, NULL, 'new', NOW(), NOW())";
                $params = [
                    ':school_id' => $school['id'] ?? 1,
                    ':name' => $name,
                    ':phone' => $phone,
                    ':source' => $final_source,
                    ':message' => $full_message
                ];
            }

            $ok = db_run($sql, $params);
            if ($ok) { $enq_success = 'Thank you — your enquiry has been received.'; $_POST = []; }
            else $enq_errors[] = 'Failed to submit enquiry. Please try again later.';
        } catch (Throwable $e) {
            error_log('Enquiry save error: ' . $e->getMessage());
            $enq_errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

/* ---------------------------
   Fetch news & events
   --------------------------- */
$news_items = [];
$events_items = [];
try {
    $news_items = db_get_all(
        "SELECT id, title, slug, excerpt, image_path, created_at FROM news_events WHERE type='news' AND is_published=1 ORDER BY created_at DESC LIMIT 6"
    );

    $events_items = db_get_all("
        SELECT id, title, slug, excerpt, image_path, start_date, end_date, location, created_at
        FROM news_events
        WHERE type = 'event' AND is_published = 1
        ORDER BY
          CASE WHEN start_date IS NOT NULL THEN start_date ELSE created_at END DESC
        LIMIT 6
    ");
} catch (Throwable $e) {
    $news_items = [];
    $events_items = [];
}


$homeUrl = function_exists('site_url') ? site_url('/') : '/';
$loginUrl = function_exists('site_url') ? site_url('/login.php') : '/login.php';
$cssBase = function_exists('site_url') ? rtrim(site_url(''), '/') : '';
$hasNews = $news_items !== [] || $events_items !== [];
$inr = static function ($n): string {
    if ($n === '' || $n === null) {
        return '';
    }
    $s = trim((string) $n);
    if ($s === '') {
        return '';
    }
    $digits = preg_replace('/[^\d.]/', '', $s) ?? '';
    if ($digits === '' || !is_numeric($digits)) {
        return $s;
    }
    $f = (float) $digits;
    if ($f <= 0) {
        return '';
    }
    return '₹ ' . number_format($f, abs($f - (int) $f) < 0.001 ? 0 : 2);
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo e($page_title); ?><?php echo $tagline !== '' ? ' — ' . e($tagline) : ''; ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="<?php echo e(strip_tags(substr($short_about !== '' ? $short_about : $long_about, 0, 160))); ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?php echo e($cssBase); ?>/assets/css/public-site.css?v=20260919b" rel="stylesheet">
</head>
<body class="public-site">

<nav class="navbar navbar-expand-lg fixed-top public-nav">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="<?php echo e($homeUrl); ?>">
      <img src="<?php echo e($logo); ?>" alt="" class="logo-img me-2" onerror="this.onerror=null;this.src='<?php echo e(resolve_image_url('assets/images/default-logo.png')); ?>'">
      <div class="d-none d-sm-block">
        <div><?php echo e($page_title); ?></div>
        <?php if ($tagline !== ''): ?><div class="small text-muted"><?php echo e($tagline); ?></div><?php endif; ?>
      </div>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainMenu" aria-label="Menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainMenu">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
        <?php if ($aboutHtml !== '' || $whyList !== []): ?><li class="nav-item"><a class="nav-link" href="#about">About</a></li><?php endif; ?>
        <?php if ($classList !== []): ?><li class="nav-item"><a class="nav-link" href="#classes">Classes</a></li><?php endif; ?>
        <?php if ($facList !== []): ?><li class="nav-item"><a class="nav-link" href="#facilities">Facilities</a></li><?php endif; ?>
        <?php if ($galleryList !== []): ?><li class="nav-item"><a class="nav-link" href="#gallery">Photos</a></li><?php endif; ?>
        <?php if ($hasNews): ?><li class="nav-item"><a class="nav-link" href="#news-events">News</a></li><?php endif; ?>
        <?php if ($faqList !== []): ?><li class="nav-item"><a class="nav-link" href="#faqs">FAQ</a></li><?php endif; ?>
        <li class="nav-item"><a class="nav-link btn-login ms-lg-1" href="<?php echo e($loginUrl); ?>">Login</a></li>
        <li class="nav-item"><a class="nav-link btn-enquiry ms-lg-1" href="#enquiry">Enquire</a></li>
      </ul>
    </div>
  </div>
</nav>
<div class="public-nav-spacer" aria-hidden="true"></div>

<section class="public-hero">
  <div class="container">
    <div class="row align-items-center g-4">
      <div class="col-lg-6 text-center text-lg-start">
        <?php if ($whyList !== []): ?>
          <div class="d-flex flex-wrap gap-2 mb-3 justify-content-center justify-content-lg-start">
            <?php foreach (array_slice($whyList, 0, 3) as $pill):
                $pl = $pill['title'] !== '' ? $pill['title'] : $pill['description'];
                ?>
              <span class="feature-pill pink"><?php echo e($pl); ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <h1 class="display-5 fw-bold"><?php echo e($hero_title); ?></h1>
        <?php if ($hero_subtitle !== ''): ?><p class="lead"><?php echo e($hero_subtitle); ?></p><?php endif; ?>
        <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-lg-start">
          <a href="<?php echo e($hero_cta_url); ?>" class="btn btn-cta btn-lg text-white"><?php echo e($hero_cta_text); ?></a>
          <?php if ($telHref !== ''): ?><a href="<?php echo e($telHref); ?>" class="btn btn-outline-secondary btn-lg">Call school</a><?php endif; ?>
        </div>
      </div>
      <div class="col-lg-6 text-center">
        <img src="<?php echo e($hero); ?>" class="img-fluid hero-img" alt="<?php echo e($hero_alt); ?>">
      </div>
    </div>
  </div>
</section>

<?php if ($aboutHtml !== '' || $whyList !== []): ?>
<section class="py-5" id="about">
  <div class="container">
    <h2 class="section-title text-center mb-4 d-block">About our school</h2>
    <div class="row align-items-center g-4">
      <?php if ($hasAboutPhoto): ?>
      <div class="col-md-6">
        <img src="<?php echo e($about_image); ?>" class="img-fluid about-img rounded-4 shadow" alt="">
      </div>
      <?php endif; ?>
      <div class="<?php echo $hasAboutPhoto ? 'col-md-6' : 'col-12 col-lg-8 mx-auto'; ?>">
        <?php if ($short_about !== '' && $long_about !== ''): ?>
          <p class="fw-semibold mb-2"><?php echo e($short_about); ?></p>
        <?php endif; ?>
        <div class="pub-about"><?php echo $aboutHtml; ?></div>
      </div>
    </div>
    <?php if ($whyList !== []): ?>
      <div class="row g-3 mt-4">
        <?php foreach ($whyList as $item): ?>
          <div class="col-12 col-md-6 col-lg-3">
            <div class="card card-soft p-3 h-100 text-center">
              <div class="fw-bold"><?php echo e($item['title']); ?></div>
              <?php if ($item['description'] !== ''): ?><div class="small text-muted mt-1"><?php echo nl2br(e($item['description'])); ?></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($facList !== []): ?>
<section class="py-5 bg-light" id="facilities">
  <div class="container">
    <h2 class="section-title text-center mb-4 d-block">Facilities</h2>
    <div class="row g-3 justify-content-center">
      <?php foreach ($facList as $fac): ?>
        <div class="col-12 col-md-6 col-lg-4">
          <div class="card card-soft p-4 h-100">
            <div class="h6 mb-1"><?php echo e($fac['title']); ?></div>
            <?php if ($fac['description'] !== ''): ?><div class="text-muted"><?php echo nl2br(e($fac['description'])); ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($classList !== []): ?>
<section class="py-5" id="classes">
  <div class="container">
    <h2 class="section-title text-center mb-4 d-block">Classes</h2>
    <div class="row g-3">
      <?php foreach ($classList as $c): ?>
        <div class="col-12 col-md-6 col-lg-3">
          <div class="card card-soft p-3 text-center h-100">
            <div class="h6 mb-1"><?php echo e($c['name']); ?></div>
            <?php if ($c['age'] !== ''): ?><div class="small text-muted"><?php echo e($c['age']); ?></div><?php endif; ?>
            <?php $feeTxt = $inr($c['fees']); ?>
            <?php if ($feeTxt !== ''): ?><div class="mt-2 fw-semibold"><?php echo e($feeTxt); ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($hasNews): ?>
<section class="py-5" id="news-events">
  <div class="container">
    <h2 class="section-title text-center mb-4">News &amp; events</h2>
    <div class="row g-4">
      <?php if ($news_items !== []): ?>
        <div class="col-md-6">
          <div class="card card-soft p-3 h-100">
            <h5 class="mb-3">News</h5>
            <ul class="list-unstyled mb-0">
              <?php foreach ($news_items as $n): $img = resolve_image_url((string) ($n['image_path'] ?? '')); ?>
                <li class="d-flex mb-3">
                  <?php if ($img !== ''): ?><img src="<?php echo e($img); ?>" class="news-thumb me-3" alt=""><?php endif; ?>
                  <div>
                    <div class="fw-semibold"><?php echo e((string) ($n['title'] ?? '')); ?></div>
                    <div class="small text-muted"><?php echo e(substr(strip_tags((string) ($n['excerpt'] ?? '')), 0, 120)); ?></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endif; ?>
      <?php if ($events_items !== []): ?>
        <div class="col-md-6">
          <div class="card card-soft p-3 h-100">
            <h5 class="mb-3">Events</h5>
            <ul class="list-unstyled mb-0">
              <?php foreach ($events_items as $ev): $img = resolve_image_url((string) ($ev['image_path'] ?? '')); ?>
                <li class="d-flex mb-3">
                  <?php if ($img !== ''): ?><img src="<?php echo e($img); ?>" class="news-thumb me-3" alt=""><?php endif; ?>
                  <div>
                    <div class="fw-semibold"><?php echo e((string) ($ev['title'] ?? '')); ?></div>
                    <div class="small text-muted">
                      <?php
                        if (!empty($ev['start_date'])) {
                            echo e(date('d M Y', strtotime((string) $ev['start_date'])));
                        }
                        if (!empty($ev['location'])) {
                            echo (!empty($ev['start_date']) ? ' · ' : '') . e((string) $ev['location']);
                        }
                      ?>
                    </div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($galleryList !== []): ?>
<section class="py-5 bg-light public-gallery" id="gallery">
  <div class="container">
    <h2 class="section-title text-center mb-4">Photos</h2>
    <div class="row g-3">
      <?php foreach ($galleryList as $img): ?>
        <div class="col-6 col-md-4 col-lg-3">
          <img src="<?php echo e(resolve_image_url($img)); ?>" alt="">
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($map_embed_safe !== '' || $address !== ''): ?>
<section class="py-5" id="location">
  <div class="container">
    <h2 class="section-title text-center mb-4">Find us</h2>
    <?php if ($address !== ''): ?>
      <p class="text-center text-muted mb-3" style="white-space:pre-line"><?php echo e($address); ?></p>
    <?php endif; ?>
    <?php if ($map_embed_safe !== ''): ?>
      <div class="ratio ratio-16x9 rounded-4 overflow-hidden shadow-sm"><?php echo $map_embed_safe; ?></div>
    <?php endif; ?>
    <?php if (!empty($social['google_map'])): ?>
      <p class="text-center mt-3 mb-0"><a class="btn btn-outline-primary" href="<?php echo e($social['google_map']); ?>" target="_blank" rel="noopener">Open in Google Maps</a></p>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($testiList !== []): ?>
<section class="py-5 bg-light" id="testimonials">
  <div class="container">
    <h2 class="section-title text-center mb-4">Parents say</h2>
    <div class="row g-3 justify-content-center">
      <?php foreach ($testiList as $t):
          $photoUrl = $t['photo'] !== '' ? resolve_image_url($t['photo']) : '';
          ?>
        <div class="col-12 col-md-6 col-lg-4">
          <div class="card card-soft p-4 h-100 text-center">
            <?php if ($photoUrl !== ''): ?>
              <img src="<?php echo e($photoUrl); ?>" class="testimonial-photo mb-3 mx-auto" alt="">
            <?php endif; ?>
            <div class="small"><?php echo nl2br(e($t['quote'])); ?></div>
            <div class="fw-semibold mt-3"><?php echo e($t['name']); ?></div>
            <?php if ($t['role'] !== ''): ?><div class="small text-muted"><?php echo e($t['role']); ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($faqList !== []): ?>
<section class="py-5" id="faqs">
  <div class="container">
    <h2 class="section-title text-center mb-4">Questions parents ask</h2>
    <div class="accordion" id="faqAccordion">
      <?php foreach ($faqList as $k => $f):
          $qid = 'faq-' . ($k + 1);
          ?>
        <div class="accordion-item">
          <h2 class="accordion-header">
            <button class="accordion-button <?php echo $k > 0 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?php echo $qid; ?>">
              <?php echo e($f['q']); ?>
            </button>
          </h2>
          <div id="collapse-<?php echo $qid; ?>" class="accordion-collapse collapse <?php echo $k === 0 ? 'show' : ''; ?>" data-bs-parent="#faqAccordion">
            <div class="accordion-body"><?php echo nl2br(e($f['a'])); ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="py-5 public-enquiry" id="enquiry">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-6">
        <div class="card card-soft p-4 border-0">
          <h3 class="text-center fw-bold section-title d-block">Ask about admission</h3>
          <p class="text-center text-muted">Leave your name and phone. The school will call you.</p>
          <?php if ($enq_success): ?>
            <div class="alert alert-success"><?php echo e($enq_success); ?></div>
          <?php elseif ($enq_errors !== []): ?>
            <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($enq_errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
          <?php endif; ?>
          <form method="post" class="mt-2" novalidate>
            <input type="hidden" name="enquire_action" value="submit_enquiry">
            <div class="mb-3"><input name="name" class="form-control form-control-lg" placeholder="Your name" value="<?php echo e((string) ($_POST['name'] ?? '')); ?>" required></div>
            <div class="mb-3">
              <select name="age_group" class="form-select form-select-lg">
                <option value="">Child’s class / age</option>
                <?php if ($classList !== []): ?>
                  <?php foreach ($classList as $c):
                      $opt = $c['name'] . ($c['age'] !== '' ? (' · ' . $c['age']) : '');
                      $sel = ((string) ($_POST['age_group'] ?? '')) === $c['name'] ? ' selected' : '';
                      ?>
                    <option value="<?php echo e($c['name']); ?>"<?php echo $sel; ?>><?php echo e($opt); ?></option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <option value="1-2" <?php echo (($_POST['age_group'] ?? '') === '1-2') ? 'selected' : ''; ?>>1–2 years</option>
                  <option value="2-3" <?php echo (($_POST['age_group'] ?? '') === '2-3') ? 'selected' : ''; ?>>2–3 years</option>
                  <option value="3-4" <?php echo (($_POST['age_group'] ?? '') === '3-4') ? 'selected' : ''; ?>>3–4 years</option>
                  <option value="4-5" <?php echo (($_POST['age_group'] ?? '') === '4-5') ? 'selected' : ''; ?>>4–5 years</option>
                  <option value="5-6" <?php echo (($_POST['age_group'] ?? '') === '5-6') ? 'selected' : ''; ?>>5–6 years</option>
                <?php endif; ?>
              </select>
            </div>
            <div class="mb-3"><input name="phone" class="form-control form-control-lg" inputmode="tel" placeholder="Phone" value="<?php echo e((string) ($_POST['phone'] ?? '')); ?>"></div>
            <div class="mb-3"><textarea name="message" class="form-control" rows="3" placeholder="Anything we should know? (optional)"><?php echo e((string) ($_POST['message'] ?? '')); ?></textarea></div>
            <div class="d-grid">
              <button type="submit" class="btn btn-submit btn-lg text-white">Send</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>

<footer class="pt-4 pb-5 public-footer">
  <div class="container text-center">
    <div class="fw-bold mb-1"><?php echo e($page_title); ?></div>
    <?php if ($address !== ''): ?><div class="small text-muted mb-1" style="white-space:pre-line"><?php echo e($address); ?></div><?php endif; ?>
    <?php if ($opening_hours !== ''): ?><div class="small text-muted mb-2"><?php echo e($opening_hours); ?></div><?php endif; ?>
    <div class="small mb-3">
      <?php if ($telHref !== ''): ?><a href="<?php echo e($telHref); ?>"><?php echo e($contact_phone); ?></a><?php endif; ?>
      <?php if ($contact_email !== ''): ?><?php echo $telHref !== '' ? ' · ' : ''; ?><a href="mailto:<?php echo e($contact_email); ?>"><?php echo e($contact_email); ?></a><?php endif; ?>
    </div>
    <div class="social-boxes" role="navigation" aria-label="Links">
      <a class="social-box" href="<?php echo e($loginUrl); ?>">Login</a>
      <?php if (!empty($social['whatsapp'])): ?><a class="social-box" href="<?php echo e($social['whatsapp']); ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
      <?php if (!empty($social['instagram'])): ?><a class="social-box" href="<?php echo e($social['instagram']); ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?>
      <?php if (!empty($social['facebook'])): ?><a class="social-box" href="<?php echo e($social['facebook']); ?>" target="_blank" rel="noopener">Facebook</a><?php endif; ?>
      <?php if (!empty($social['youtube'])): ?><a class="social-box" href="<?php echo e($social['youtube']); ?>" target="_blank" rel="noopener">YouTube</a><?php endif; ?>
      <?php if (!empty($social['google_map'])): ?><a class="social-box" href="<?php echo e($social['google_map']); ?>" target="_blank" rel="noopener">Map</a><?php endif; ?>
    </div>
    <div class="small mt-3">
      <a href="<?php echo e(function_exists('site_url') ? site_url('/feedback.php') : '/feedback.php'); ?>">Feedback</a>
      ·
      <a href="<?php echo e(function_exists('site_url') ? site_url('/complaint.php') : '/complaint.php'); ?>">Complaint</a>
    </div>
    <div class="small text-muted mt-2">&copy; <?php echo date('Y') . ' ' . e($page_title); ?></div>
  </div>
</footer>

<div id="bottom-social-bar" role="navigation" aria-label="Call or WhatsApp">
  <?php if ($telHref !== ''): ?>
    <a class="bs-btn" href="<?php echo e($telHref); ?>" aria-label="Call" style="background:#1d4ed8;">Call</a>
  <?php endif; ?>
  <?php if (!empty($social['whatsapp'])): ?>
    <a class="bs-btn" href="<?php echo e($social['whatsapp']); ?>" target="_blank" rel="noopener" aria-label="WhatsApp" style="background:#25D366;">WA</a>
  <?php endif; ?>
  <a class="bs-btn" href="#enquiry" aria-label="Enquire" style="background:#e11d48;">Ask</a>
</div>

<?php
$popupFile = __DIR__ . '/includes/popup_footer_snippet.php';
if (is_file($popupFile)) {
    require $popupFile;
}
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
