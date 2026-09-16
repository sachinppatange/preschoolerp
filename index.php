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

/* ---------------------------
   Social links: prefer DB values, fallback to hardcoded defaults
   --------------------------- */
$defaults = [
    'whatsapp'   => 'https://wa.me/917770007801',
    'instagram'  => 'https://www.instagram.com/pioneer_play_school/',
    'facebook'   => 'https://www.facebook.com/pioneerplayschool',
    'youtube'    => 'https://www.youtube.com/@pioneer_play_school',
    'google_map' => 'https://share.google/b3CCm2KIyX1iHRXsU'
];

// Merge possible values from school or settings
try {
    if (empty($social) && !empty($school['social_links'])) {
        $social = decode_json_field($school['social_links']);
    }
    if (empty($social) && !empty($settings)) {
        if (!empty($settings['social_links'])) {
            $s2 = decode_json_field($settings['social_links']);
            if (is_array($s2)) $social = array_merge($social ?: [], $s2);
        } else {
            foreach (['whatsapp','instagram','facebook','youtube','google_map'] as $k) {
                if (!empty($settings[$k])) $social[$k] = $settings[$k];
            }
        }
    }
    if (empty($social)) {
        $row = db_get_one("SELECT social_links, settings FROM schools WHERE id = :id LIMIT 1", [':id'=>1]);
        if ($row) {
            if (!empty($row['social_links'])) $social = decode_json_field($row['social_links']);
            if (empty($social) && !empty($row['settings'])) {
                $s2 = decode_json_field($row['settings']);
                if (!empty($s2['social_links'])) $social = decode_json_field($s2['social_links']);
                foreach (['whatsapp','instagram','facebook','youtube','google_map'] as $k) {
                    if (empty($social[$k]) && !empty($s2[$k])) $social[$k] = $s2[$k];
                }
            }
        }
    }
} catch (Throwable $e) {
    $social = $social ?: [];
}

// Normalize and apply defaults
foreach ($defaults as $k => $url) {
    if (empty($social[$k])) $social[$k] = $url;
    if (!preg_match('#^https?://#i', trim((string)$social[$k]))) {
        if (preg_match('/^\+?\d+$/', $social[$k])) {
            $social[$k] = 'https://wa.me/' . preg_replace('/\D+/', '', $social[$k]);
        } else {
            $social[$k] = 'https://' . ltrim($social[$k], '/');
        }
    }
}

/* Determine about image */
$about_image = '';
try {
    $pdo = ensure_pdo();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schools' AND COLUMN_NAME = 'about_image' LIMIT 1");
        $stmt->execute();
        $col = $stmt->fetch();
        if ($col && !empty($school['about_image'])) $about_image = $school['about_image'];
    }
} catch (Throwable $e) {}
if (empty($about_image) && !empty($settings['about_image'])) $about_image = $settings['about_image'];
$about_image = resolve_image_url($about_image ?: ($school['hero_image'] ?? $school['logo_path'] ?? '/assets/images/hero.jpg'));

/* Display variables */
$page_title = $school['name'] ?? 'Pioneer Play School';
$tagline = $school['tagline'] ?? '';
$logo = resolve_image_url($school['logo_path'] ?? '/assets/images/logo.png');
$hero = resolve_image_url($school['hero_image'] ?? $school['logo_path'] ?? '/assets/images/hero.jpg');
$hero_title = $school['hero_title'] ?? ($school['tagline'] ?? $page_title);
$hero_subtitle = $school['hero_subtitle'] ?? $school['short_about'] ?? '';
$hero_cta_text = $school['hero_cta_text'] ?? 'Admission Enquiry';
$hero_cta_url = $school['hero_cta_url'] ?? '#enquiry';
$short_about = $school['short_about'] ?? '';
$long_about = $school['long_about'] ?? '';
$map_embed_safe = sanitize_map_embed($school['map_embed'] ?? '');
$contact_phone = $school['contact_phone'] ?? ($settings['contact_phone'] ?? '');
$contact_email = $school['contact_email'] ?? ($settings['contact_email'] ?? '');
$opening_hours = $school['opening_hours'] ?? ($settings['opening_hours'] ?? '');
$hero_alt = e($page_title) . ' hero';

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

/* ---------------------------
   Render HTML
   --------------------------- */
$homeUrl = function_exists('site_url') ? site_url('/') : '/';
$loginUrl = function_exists('site_url') ? site_url('/login.php') : '/login.php';
$cssBase = function_exists('site_url') ? rtrim(site_url(''), '/') : '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo e($page_title); ?><?php echo $tagline ? ' — ' . e($tagline) : ''; ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="<?php echo e(strip_tags(substr($short_about ?: $long_about, 0, 160))); ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?php echo e($cssBase); ?>/assets/css/public-site.css" rel="stylesheet">
</head>
<body class="public-site">

<nav class="navbar navbar-expand-lg fixed-top public-nav">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="<?php echo e($homeUrl); ?>">
      <img src="<?php echo e($logo); ?>" alt="logo" class="logo-img me-2" onerror="this.onerror=null;this.src='<?php echo e(resolve_image_url('assets/images/default-logo.png')); ?>'">
      <div class="d-none d-sm-block">
        <div><?php echo e($page_title); ?></div>
        <?php if ($tagline): ?><div class="small text-muted"><?php echo e($tagline); ?></div><?php endif; ?>
      </div>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainMenu" aria-label="Menu">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainMenu">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
        <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
        <li class="nav-item"><a class="nav-link" href="#why">Why Us</a></li>
        <li class="nav-item"><a class="nav-link" href="#facilities">Facilities</a></li>
        <li class="nav-item"><a class="nav-link" href="#gallery">Gallery</a></li>
        <li class="nav-item"><a class="nav-link" href="#news-events">News</a></li>
        <li class="nav-item"><a class="nav-link btn-login ms-lg-1" href="<?php echo e($loginUrl); ?>"><i class="bi bi-person-circle me-1"></i>Login</a></li>
        <li class="nav-item"><a class="nav-link btn-enquiry ms-lg-1" href="#enquiry">Enquiry</a></li>
      </ul>
    </div>
  </div>
</nav>

<div style="height:72px" aria-hidden="true"></div>

<!-- HERO -->
<section class="public-hero">
  <div class="container">
    <div class="row align-items-center g-4">
      <div class="col-lg-6 text-center text-lg-start">
        <div class="d-flex flex-wrap gap-2 mb-3 justify-content-center justify-content-lg-start">
          <span class="feature-pill pink"><i class="bi bi-heart-fill"></i> Nurturing</span>
          <span class="feature-pill blue"><i class="bi bi-lightbulb-fill"></i> Play & Learn</span>
          <span class="feature-pill green"><i class="bi bi-shield-check"></i> Safe Campus</span>
        </div>
        <h1 class="display-5 fw-bold"><?php echo e($hero_title); ?></h1>
        <p class="lead"><?php echo e($hero_subtitle); ?></p>
        <a href="<?php echo e($hero_cta_url); ?>" class="btn btn-cta btn-lg text-white"><?php echo e($hero_cta_text); ?></a>
      </div>
      <div class="col-lg-6 text-center">
        <img src="<?php echo e($hero); ?>" class="img-fluid hero-img" alt="<?php echo e($hero_alt); ?>">
      </div>
    </div>
  </div>
</section>

<!-- ABOUT -->
<section class="py-5" id="about">
  <div class="container">
    <h2 class="section-title text-center mb-4 d-block">About Our School</h2>
    <div class="row align-items-center">
      <div class="col-md-6">
        <img src="<?php echo e($about_image); ?>" class="img-fluid about-img rounded-4 shadow" alt="About image">
      </div>
      <div class="col-md-6 mt-4 mt-md-0">
        <div class="fs-5"><?php echo $long_about ? $long_about : nl2br(e(strip_tags($short_about))); ?></div>
      </div>
    </div>
  </div>
</section>

<!-- WHY CHOOSE US -->
<section class="py-5 bg-light" id="why">
  <div class="container">
    <h2 class="section-title text-center mb-4 d-block">Why Choose Us</h2>
    <div class="row g-3 justify-content-center">
      <?php if (!empty($why)): foreach ($why as $item): ?>
        <div class="col-12 col-md-6 col-lg-3">
          <div class="card card-soft p-4 text-center h-100">
            <div class="h6 mb-2"><?php echo e($item['title'] ?? ''); ?></div>
            <div class="fs-5 text-muted"><?php echo nl2br(e($item['description'] ?? '')); ?></div>
          </div>
        </div>
      <?php endforeach; else: ?>
        <div class="col-12 text-center">Information not available.</div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- FACILITIES -->
<section class="py-5 bg-light" id="facilities">
  <div class="container">
    <h2 class="section-title text-center mb-4 d-block">Our Facilities</h2>
    <div class="row g-3 justify-content-center">
      <?php if (!empty($facilities) && is_array($facilities)): foreach ($facilities as $fac): ?>
        <div class="col-12 col-md-6 col-lg-4">
          <div class="card card-soft p-4 h-100">
            <div class="d-flex align-items-start gap-3">
              <span class="feature-pill blue mb-0"><i class="bi bi-star-fill"></i></span>
              <div>
                <div class="h6 mb-1"><?php echo e($fac['title'] ?? $fac['name'] ?? 'Facility'); ?></div>
                <div class="text-muted"><?php echo nl2br(e($fac['description'] ?? $fac['desc'] ?? '')); ?></div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; else: ?>
        <div class="col-12 col-md-6 col-lg-3"><div class="card card-soft p-4 text-center h-100"><span class="feature-pill pink d-inline-flex mb-2"><i class="bi bi-palette"></i></span><div class="h6">Activity Rooms</div><div class="small text-muted">Colorful, child-friendly learning spaces</div></div></div>
        <div class="col-12 col-md-6 col-lg-3"><div class="card card-soft p-4 text-center h-100"><span class="feature-pill blue d-inline-flex mb-2"><i class="bi bi-tree"></i></span><div class="h6">Outdoor Play</div><div class="small text-muted">Safe play area with supervision</div></div></div>
        <div class="col-12 col-md-6 col-lg-3"><div class="card card-soft p-4 text-center h-100"><span class="feature-pill green d-inline-flex mb-2"><i class="bi bi-camera-video"></i></span><div class="h6">CCTV Security</div><div class="small text-muted">Monitored campus for peace of mind</div></div></div>
        <div class="col-12 col-md-6 col-lg-3"><div class="card card-soft p-4 text-center h-100"><span class="feature-pill yellow d-inline-flex mb-2"><i class="bi bi-bus-front"></i></span><div class="h6">Transport</div><div class="small text-muted">Safe pick-up and drop facility</div></div></div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- CLASSES -->
<section class="py-5" id="classes">
  <div class="container">
    <h2 class="section-title text-center mb-4 d-block">Classes Offered</h2>
    <div class="row g-3">
      <?php if (!empty($classes)): foreach ($classes as $c): ?>
        <div class="col-12 col-md-6 col-lg-3">
          <div class="card card-soft p-3 text-center h-100">
            <div class="h6 mb-1"><?php echo e($c['name'] ?? 'Class'); ?></div>
            <div class="small text-muted"><?php echo e($c['age'] ?? 'Age group'); ?></div>
            <?php if (!empty($c['fees'])): ?><div class="mt-2 fw-semibold">₹ <?php echo number_format((float)$c['fees'], 2); ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; else: ?>
        <div class="col-12 text-center">Classes information not available.</div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- NEWS & EVENTS -->
<section class="py-5" id="news-events">
  <div class="container">
    <h2 class="section-title text-center mb-4">Latest News / Upcoming Events</h2>
    <div class="row g-4">
      <div class="col-md-6">
        <div class="card card-soft p-3">
          <h5 class="mb-3">Latest News</h5>
          <?php if (!empty($news_items)): ?>
            <ul class="list-unstyled mb-0">
              <?php foreach ($news_items as $n): $img = resolve_image_url($n['image_path'] ?? '/assets/images/news_placeholder.png'); ?>
                <li class="d-flex mb-3">
                  <img src="<?php echo e($img); ?>" class="news-thumb me-3" alt="">
                  <div>
                    <div class="fw-semibold"><?php echo e($n['title']); ?></div>
                    <div class="small text-muted"><?php echo e(substr(strip_tags($n['excerpt'] ?? ''),0,120)); ?></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="text-muted">No news available.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card card-soft p-3">
          <h5 class="mb-3">Upcoming Events</h5>
          <?php if (!empty($events_items)): ?>
            <ul class="list-unstyled mb-0">
              <?php foreach ($events_items as $ev): $img = resolve_image_url($ev['image_path'] ?? '/assets/images/event_placeholder.png'); ?>
                <li class="d-flex mb-3">
                  <img src="<?php echo e($img); ?>" class="news-thumb me-3" alt="">
                  <div>
                    <div class="fw-semibold"><?php echo e($ev['title']); ?></div>
                    <div class="small text-muted">
                      <?php
                        if (!empty($ev['start_date']) || !empty($ev['end_date'])) {
                          if (!empty($ev['start_date'])) echo e(date('d M Y', strtotime($ev['start_date'])));
                          if (!empty($ev['end_date'])) echo (!empty($ev['start_date']) ? ' - ' : '') . e(date('d M Y', strtotime($ev['end_date'])));
                        } else {
                          echo e(substr(strip_tags($ev['excerpt'] ?? ''),0,80));
                        }
                        if (!empty($ev['location'])) echo ' • ' . e($ev['location']);
                      ?>
                    </div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="text-muted">No upcoming events at the moment.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- GALLERY -->
<section class="py-5 bg-light public-gallery" id="gallery">
  <div class="container">
    <h2 class="section-title text-center mb-4">Photo Gallery</h2>
    <div class="row g-3">
      <?php if (!empty($gallery)): foreach ($gallery as $img): $imgUrl = resolve_image_url($img); ?>
        <div class="col-6 col-md-4 col-lg-3">
          <img src="<?php echo e($imgUrl); ?>" alt="gallery image" class="img-fluid shadow-sm">
        </div>
      <?php endforeach; else: ?>
        <div class="col-12 text-center">No images yet.</div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- MAP / LOCATION -->
<?php if (!empty($map_embed_safe)): ?>
<section class="py-5" id="location">
  <div class="container">
    <h2 class="section-title text-center mb-4">Location</h2>
    <div class="row justify-content-center">
      <div class="col-12">
        <div class="ratio ratio-16x9">
          <?php echo $map_embed_safe; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- TESTIMONIALS -->
<section class="py-5" id="testimonials">
  <div class="container">
    <h2 class="section-title text-center mb-4">Testimonials</h2>
    <?php if (!empty($testimonials) && is_array($testimonials)): ?>
      <div id="testCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-interval="6000">
        <div class="carousel-inner">
          <?php $i = 0; foreach ($testimonials as $t):
            $active = $i === 0 ? 'active' : '';
            $photoUrl = resolve_image_url($t['photo'] ?? ($t['image'] ?? '') );
            $name = $t['name'] ?? '';
            $role = $t['role'] ?? '';
            $quote = $t['quote'] ?? '';
          ?>
            <div class="carousel-item <?php echo $active; ?>">
              <div class="d-flex flex-column align-items-center text-center py-4">
                <?php if (!empty($photoUrl)): ?>
                  <img src="<?php echo e($photoUrl); ?>" class="testimonial-photo mb-3" alt="<?php echo e($name ? e($name) : 'testimonial'); ?>">
                <?php endif; ?>
                <div class="fw-semibold"><?php echo e($name); ?><?php if (!empty($role)) echo ' • <span class="small text-muted">' . e($role) . '</span>'; ?></div>
                <div class="small text-muted mt-2"><?php echo nl2br(e($quote)); ?></div>
              </div>
            </div>
          <?php $i++; endforeach; ?>
        </div>

        <?php if (count($testimonials) > 1): ?>
          <button class="carousel-control-prev" type="button" data-bs-target="#testCarousel" data-bs-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Previous</span>
          </button>
          <button class="carousel-control-next" type="button" data-bs-target="#testCarousel" data-bs-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Next</span>
          </button>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <p class="text-center">No testimonials yet.</p>
    <?php endif; ?>
  </div>
</section>

<!-- FAQs -->
<section class="py-5 bg-light" id="faqs">
  <div class="container">
    <h2 class="section-title text-center mb-4">Frequently Asked Questions</h2>
    <div class="accordion" id="faqAccordion">
      <?php if (!empty($faqs) && is_array($faqs)): $k = 0; foreach ($faqs as $f): $k++;
          $qid = 'faq-' . $k;
          $q = $f['q'] ?? '';
          $a = $f['a'] ?? '';
      ?>
        <div class="accordion-item">
          <h2 class="accordion-header" id="heading-<?php echo $qid; ?>">
            <button class="accordion-button <?php echo $k>1 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?php echo $qid; ?>" aria-expanded="<?php echo $k===1 ? 'true' : 'false'; ?>">
              <?php echo e($q); ?>
            </button>
          </h2>
          <div id="collapse-<?php echo $qid; ?>" class="accordion-collapse collapse <?php echo $k===1 ? 'show' : ''; ?>" aria-labelledby="heading-<?php echo $qid; ?>" data-bs-parent="#faqAccordion">
            <div class="accordion-body"><?php echo nl2br(e($a)); ?></div>
          </div>
        </div>
      <?php endforeach; else: ?>
        <p class="text-center">No FAQs available.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ENQUIRY -->
<section class="py-5 public-enquiry" id="enquiry">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-6">
        <div class="card card-soft p-4 border-0">
          <h3 class="text-center fw-bold section-title d-block">Admission Enquiry</h3>

          <?php if ($enq_success): ?>
            <div class="alert alert-success"><?php echo e($enq_success); ?></div>
          <?php elseif (!empty($enq_errors)): ?>
            <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($enq_errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
          <?php endif; ?>

          <form method="post" class="mt-3" novalidate>
            <input type="hidden" name="enquire_action" value="submit_enquiry">
            <div class="mb-3"><input name="name" class="form-control form-control-lg" placeholder="Parent / Guardian Name" value="<?php echo e($_POST['name'] ?? ''); ?>" required></div>

            <div class="mb-3">
              <select name="age_group" class="form-select form-select-lg">
                <option value="">Select Child Age</option>
                <option value="1-2" <?php if (isset($_POST['age_group']) && $_POST['age_group']=='1-2') echo 'selected'; ?>>1–2 Years</option>
                <option value="2-3" <?php if (isset($_POST['age_group']) && $_POST['age_group']=='2-3') echo 'selected'; ?>>2–3 Years</option>
                <option value="3-4" <?php if (isset($_POST['age_group']) && $_POST['age_group']=='3-4') echo 'selected'; ?>>3–4 Years</option>
                <option value="4-5" <?php if (isset($_POST['age_group']) && $_POST['age_group']=='4-5') echo 'selected'; ?>>4–5 Years</option>
                <option value="5-6" <?php if (isset($_POST['age_group']) && $_POST['age_group']=='5-6') echo 'selected'; ?>>5–6 Years</option>
              </select>
            </div>

            <div class="mb-3"><input name="phone" class="form-control form-control-lg" placeholder="Phone" value="<?php echo e($_POST['phone'] ?? ''); ?>"></div>
            <div class="mb-3"><textarea name="message" class="form-control" rows="4" placeholder="Message"><?php echo e($_POST['message'] ?? ''); ?></textarea></div>

            <div class="d-grid">
              <button type="submit" class="btn btn-submit btn-lg text-white">Submit Enquiry</button>
            </div>
          </form>

        </div>
      </div>
    </div>
  </div>
</section>



<!-- FOOTER -->
<footer class="pt-4 pb-3 public-footer">
  <div class="container text-center">
    <div class="social-boxes" role="navigation" aria-label="Social links">

      <a class="social-box bi-person-check" href="<?php echo e($loginUrl); ?>">
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="#d3252b" viewBox="0 0 16 16">
      <path d="M15.854 5.854a.5.5 0 0 0-.708-.708L12.5 7.793 11.354 6.646a.5.5 0 1 0-.708.708l1.5 1.5a.5.5 0 0 0 .708 0l3-3z"/>
      <path d="M6 8a3 3 0 1 0-2.995-3.176A3 3 0 0 0 6 8zm2 1a4 4 0 0 1 4 4v1H0v-1a4 4 0 0 1 4-4h4z"/>
      </svg>  
      <span>Login</span>
      </a>

      <a class="social-box social-whatsapp" href="<?php echo e($social['whatsapp']); ?>" target="_blank" rel="noopener noreferrer">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M21 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.63A2 2 0 0 1 4.09 3h3a2 2 0 0 1 2 1.72c.12 1.21.38 2.39.77 3.5a2 2 0 0 1-.45 2.11L8.91 11.09a16 16 0 0 0 6 6l1.75-1.75a2 2 0 0 1 2.11-.45c1.11.39 2.29.65 3.5.77A2 2 0 0 1 21 16.92z" fill="#25D366"/></svg>
        <span>WhatsApp</span>
      </a>

      <a class="social-box social-instagram" href="<?php echo e($social['instagram']); ?>" target="_blank" rel="noopener noreferrer">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="5" stroke="#E1306C" stroke-width="1.4" fill="none"/><circle cx="12" cy="12" r="3.2" stroke="#E1306C" stroke-width="1.4" fill="none"/><circle cx="17.5" cy="6.5" r="0.8" fill="#E1306C"/></svg>
        <span>Instagram</span>
      </a>

      <a class="social-box social-facebook" href="<?php echo e($social['facebook']); ?>" target="_blank" rel="noopener noreferrer">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M22 12.07C22 6.48 17.52 2 11.93 2S2 6.48 2 12.07c0 4.99 3.66 9.12 8.44 9.86v-6.99H8.08v-2.87h2.36V9.41c0-2.34 1.39-3.63 3.52-3.63.0 0 2.04 0 2.04 0v2.24h-1.15c-1.14 0-1.49.7-1.49 1.42v1.7h2.54l-.41 2.87h-2.13v6.99C18.34 21.19 22 17.06 22 12.07z" fill="#1877F2"/></svg>
        <span>Facebook</span>
      </a>

      <a class="social-box social-youtube" href="<?php echo e($social['youtube']); ?>" target="_blank" rel="noopener noreferrer">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.96-1.96C18.88 4 12 4 12 4s-6.88 0-8.58.46A2.78 2.78 0 0 0 1.47 6.42 29.44 29.44 0 0 0 1 12a29.44 29.44 0 0 0 .47 5.58 2.78 2.78 0 0 0 1.96 1.96C5.12 20 12 20 12 20s6.88 0 8.58-.46a2.78 2.78 0 0 0 1.96-1.96A29.44 29.44 0 0 0 23 12a29.44 29.44 0 0 0-.46-5.58zM9.75 15.02V8.98L15.5 12l-5.75 3.02z" fill="#FF0000"/></svg>
        <span>YouTube</span>
      </a>

      <a class="social-box social-map" href="<?php echo e($social['google_map']); ?>" target="_blank" rel="noopener noreferrer">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" fill="#FF4F70"/></svg>
        <span>Location</span>
      </a>

    </div>

    <div class="small text-muted mt-2">
      <?php if ($contact_phone): ?>📞 <?php echo e($contact_phone); ?> <?php endif; ?>
      <?php if ($contact_email): ?> • ✉ <?php echo e($contact_email); ?><?php endif; ?>
    </div>

    <div class="small text-muted mt-1">&copy; <?php echo date('Y') . ' ' . e($page_title); ?>. All rights reserved.</div>
    
    
  
  </div>
</footer>

<!-- Bottom compact social bar -->
<div id="bottom-social-bar" role="navigation" aria-label="Quick social links">
  <a class="bs-btn" href="<?php echo e($social['whatsapp']); ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp" style="background:#25D366;">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M21 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.63A2 2 0 0 1 4.09 3h3a2 2 0 0 1 2 1.72c.12 1.21.38 2.39.77 3.5a2 2 0 0 1-.45 2.11L8.91 11.09a16 16 0 0 0 6 6l1.75-1.75a2 2 0 0 1 2.11-.45c1.11.39 2.29.65 3.5.77A2 2 0 0 1 21 16.92z" fill="#fff"/></svg>
  </a>

  <a class="bs-btn" href="<?php echo e($social['instagram']); ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram" style="background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888);">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="5" stroke="#fff" stroke-width="1.2" fill="none"/><circle cx="12" cy="12" r="3.2" stroke="#fff" stroke-width="1.2" fill="none"/><circle cx="17.5" cy="6.5" r="0.8" fill="#fff"/></svg>
  </a>

  <a class="bs-btn" href="<?php echo e($social['facebook']); ?>" target="_blank" rel="noopener noreferrer" aria-label="Facebook" style="background:#1877F2;">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M22 12.07C22 6.48 17.52 2 11.93 2S2 6.48 2 12.07c0 4.99 3.66 9.12 8.44 9.86v-6.99H8.08v-2.87h2.36V9.41c0-2.34 1.39-3.63 3.52-3.63.0 0 2.04 0 2.04 0v2.24h-1.15c-1.14 0-1.49.7-1.49 1.42v1.7h2.54l-.41 2.87h-2.13v6.99C18.34 21.19 22 17.06 22 12.07z" fill="#fff"/></svg>
  </a>

  <a class="bs-btn" href="<?php echo e($social['youtube']); ?>" target="_blank" rel="noopener noreferrer" aria-label="YouTube" style="background:#FF0000;">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.96-1.96C18.88 4 12 4 12 4s-6.88 0-8.58.46A2.78 2.78 0 0 0 1.47 6.42 29.44 29.44 0 0 0 1 12a29.44 29.44 0 0 0 .47 5.58 2.78 2.78 0 0 0 1.96 1.96C5.12 20 12 20 12 20s6.88 0 8.58-.46a2.78 2.78 0 0 0 1.96-1.96A29.44 29.44 0 0 0 23 12a29.44 29.44 0 0 0-.46-5.58zM9.75 15.02V8.98L15.5 12l-5.75 3.02z" fill="#fff"/></svg>
  </a>

  <a class="bs-btn" href="<?php echo e($social['google_map']); ?>" target="_blank" rel="noopener noreferrer" aria-label="Location" style="background:#FF4F70;">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" fill="#fff"/></svg>
  </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>