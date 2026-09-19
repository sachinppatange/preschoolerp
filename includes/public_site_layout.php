<?php
/**
 * Shared public-site layout helpers (index.php, feedback.php, complaint.php).
 */
declare(strict_types=1);

if (!function_exists('public_site_prepare')) {
    function public_site_prepare(array $school = []): array
    {
        $social = json_decode($school['social_links'] ?? '[]', true) ?: [];
        foreach (['whatsapp' => '', 'instagram' => '', 'facebook' => '', 'youtube' => '', 'google_map' => ''] as $k => $v) {
            if (empty($social[$k])) {
                $social[$k] = $v;
            }
        }
        $wa = trim((string) ($school['contact_whatsapp'] ?? ''));
        if ($wa !== '' && empty($social['whatsapp'])) {
            $d = preg_replace('/\D+/', '', $wa) ?? '';
            if (strlen($d) >= 10) {
                if (!str_starts_with($d, '91') && strlen($d) === 10) {
                    $d = '91' . $d;
                }
                $social['whatsapp'] = 'https://wa.me/' . $d;
            }
        }

        $homeUrl = function_exists('site_url') ? site_url('/') : '/';
        $loginUrl = function_exists('site_url') ? site_url('/login.php') : '/login.php';
        $cssBase = function_exists('site_url') ? rtrim(site_url(''), '/') : '';

        $logoPath = $school['logo_path'] ?? 'assets/images/logo.png';
        $heroPath = $school['hero_image'] ?? 'assets/images/hero.jpg';
        $resolve = function_exists('resolve_image_url')
            ? 'resolve_image_url'
            : static fn(string $p): string => $p;

        return [
            'page_title' => $school['name'] ?? 'Pioneer Play School',
            'tagline' => $school['tagline'] ?? '',
            'logo' => $resolve($logoPath),
            'hero' => $resolve($heroPath),
            'default_logo' => $resolve('assets/images/default-logo.png'),
            'social' => $social,
            'contact_phone' => $school['contact_phone'] ?? '',
            'contact_email' => $school['contact_email'] ?? '',
            'opening_hours' => $school['opening_hours'] ?? '',
            'address' => $school['address'] ?? '',
            'homeUrl' => $homeUrl,
            'loginUrl' => $loginUrl,
            'cssBase' => $cssBase,
            'feedbackUrl' => function_exists('site_url') ? site_url('/feedback.php') : '/feedback.php',
            'complaintUrl' => function_exists('site_url') ? site_url('/complaint.php') : '/complaint.php',
            'enquiryUrl' => $homeUrl . '#enquiry',
        ];
    }
}

if (!function_exists('public_site_render_head')) {
    function public_site_render_head(array $ps, string $pageHeading, string $metaDescription = '', string $extraBodyClass = ''): void
    {
        $title = e($ps['page_title']) . ' — ' . e($pageHeading);
        $meta = $metaDescription !== '' ? e(strip_tags(substr($metaDescription, 0, 160))) : '';
        $bodyClass = trim('public-site ' . $extraBodyClass);
        ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo $title; ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php if ($meta !== ''): ?><meta name="description" content="<?php echo $meta; ?>"><?php endif; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?php echo e($ps['cssBase']); ?>/assets/css/public-site.css?v=20260919b" rel="stylesheet">
<?php if (str_contains($extraBodyClass, 'public-form')): ?>
<style>
body.public-form #bottom-social-bar { display: none !important; }
.fb-hp { position: absolute !important; left: -9999px !important; height: 0 !important; width: 0 !important; overflow: hidden !important; opacity: 0; }
.fb-type { flex: 1; text-align: center; border: 2px solid #e2e8f0; border-radius: 999px; padding: .45rem .75rem; font-weight: 700; cursor: pointer; background: #fff; }
.fb-type input { position: absolute; opacity: 0; width: 0; height: 0; }
.fb-type.on { border-color: #ff6b8a; background: #fff0f3; color: #c81e4a; }
.fb-captcha-img { border-radius: 12px; border: 1px solid #f3d7a3; background: #fff8e8; height: 64px; width: 220px; object-fit: contain; }
</style>
<?php endif; ?>
</head>
<body class="<?php echo e($bodyClass); ?>">
        <?php
    }
}

if (!function_exists('public_site_render_nav')) {
    function public_site_render_nav(array $ps, string $active = ''): void
    {
        $home = e($ps['homeUrl']);
        $loginUrl = e($ps['loginUrl']);
        $enquiryUrl = e($ps['enquiryUrl']);
        $feedbackUrl = e($ps['feedbackUrl']);
        $complaintUrl = e($ps['complaintUrl']);
        ?>
<nav class="navbar navbar-expand-lg fixed-top public-nav">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="<?php echo $home; ?>">
      <img src="<?php echo e($ps['logo']); ?>" alt="logo" class="logo-img me-2" onerror="this.onerror=null;this.src='<?php echo e($ps['default_logo']); ?>'">
      <div class="d-none d-sm-block">
        <div><?php echo e($ps['page_title']); ?></div>
        <?php if ($ps['tagline']): ?><div class="small text-muted"><?php echo e($ps['tagline']); ?></div><?php endif; ?>
      </div>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainMenu" aria-label="Menu">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainMenu">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
        <li class="nav-item"><a class="nav-link" href="<?php echo $home; ?>#about">About</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo $home; ?>#classes">Classes</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo $home; ?>#gallery">Photos</a></li>
        <li class="nav-item"><a class="nav-link<?php echo $active === 'feedback' ? ' fw-bold text-danger' : ''; ?>" href="<?php echo $feedbackUrl; ?>">Feedback</a></li>
        <li class="nav-item"><a class="nav-link<?php echo $active === 'complaint' ? ' fw-bold text-danger' : ''; ?>" href="<?php echo $complaintUrl; ?>">Complaint</a></li>
        <li class="nav-item"><a class="nav-link btn-login ms-lg-1" href="<?php echo $loginUrl; ?>"><i class="bi bi-person-circle me-1"></i>Login</a></li>
        <li class="nav-item"><a class="nav-link btn-enquiry ms-lg-1" href="<?php echo $enquiryUrl; ?>">Enquiry</a></li>
      </ul>
    </div>
  </div>
</nav>

<div style="height:72px" aria-hidden="true"></div>
        <?php
    }
}

if (!function_exists('public_site_render_hero')) {
    function public_site_render_hero(string $heading, string $subtitle, string $heroImg): void
    {
        ?>
<section class="public-hero">
  <div class="container">
    <div class="row align-items-center g-4">
      <div class="col-lg-6 text-center text-lg-start">
        <div class="d-flex flex-wrap gap-2 mb-3 justify-content-center justify-content-lg-start">
          <span class="feature-pill pink"><i class="bi bi-heart-fill"></i> Nurturing</span>
          <span class="feature-pill blue"><i class="bi bi-lightbulb-fill"></i> Play & Learn</span>
          <span class="feature-pill green"><i class="bi bi-shield-check"></i> Safe Campus</span>
        </div>
        <h1 class="display-5 fw-bold"><?php echo e($heading); ?></h1>
        <p class="lead"><?php echo e($subtitle); ?></p>
      </div>
      <div class="col-lg-6 text-center">
        <img src="<?php echo e($heroImg); ?>" class="img-fluid hero-img" alt="<?php echo e($heading); ?>">
      </div>
    </div>
  </div>
</section>
        <?php
    }
}

if (!function_exists('public_site_render_footer')) {
    function public_site_render_footer(array $ps): void
    {
        $loginUrl = e($ps['loginUrl']);
        $social = $ps['social'];
        $phone = trim((string) ($ps['contact_phone'] ?? ''));
        $email = trim((string) ($ps['contact_email'] ?? ''));
        $hours = trim((string) ($ps['opening_hours'] ?? ''));
        $addr = trim(strip_tags((string) ($ps['address'] ?? '')));
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $tel = strlen($digits) >= 10 ? ('tel:+91' . substr($digits, -10)) : '';
        ?>
<footer class="pt-4 pb-5 public-footer">
  <div class="container text-center">
    <div class="fw-bold mb-1"><?php echo e($ps['page_title']); ?></div>
    <?php if ($addr !== ''): ?><div class="small text-muted mb-1"><?php echo e($addr); ?></div><?php endif; ?>
    <?php if ($hours !== ''): ?><div class="small text-muted mb-2"><?php echo e($hours); ?></div><?php endif; ?>
    <div class="small mb-3">
      <?php if ($tel !== ''): ?><a href="<?php echo e($tel); ?>"><?php echo e($phone); ?></a><?php endif; ?>
      <?php if ($email !== ''): ?><?php echo $tel !== '' ? ' · ' : ''; ?><a href="mailto:<?php echo e($email); ?>"><?php echo e($email); ?></a><?php endif; ?>
    </div>
    <div class="social-boxes" role="navigation" aria-label="Links">
      <a class="social-box" href="<?php echo $loginUrl; ?>">Login</a>
      <?php if (!empty($social['whatsapp'])): ?><a class="social-box" href="<?php echo e($social['whatsapp']); ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
      <?php if (!empty($social['instagram'])): ?><a class="social-box" href="<?php echo e($social['instagram']); ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?>
      <?php if (!empty($social['facebook'])): ?><a class="social-box" href="<?php echo e($social['facebook']); ?>" target="_blank" rel="noopener">Facebook</a><?php endif; ?>
      <?php if (!empty($social['youtube'])): ?><a class="social-box" href="<?php echo e($social['youtube']); ?>" target="_blank" rel="noopener">YouTube</a><?php endif; ?>
      <?php if (!empty($social['google_map'])): ?><a class="social-box" href="<?php echo e($social['google_map']); ?>" target="_blank" rel="noopener">Map</a><?php endif; ?>
    </div>
    <div class="small mt-3">
      <a href="<?php echo e($ps['feedbackUrl']); ?>">Feedback</a>
      ·
      <a href="<?php echo e($ps['complaintUrl']); ?>">Complaint</a>
    </div>
    <div class="small text-muted mt-2">&copy; <?php echo date('Y') . ' ' . e($ps['page_title']); ?></div>
  </div>
</footer>
<div id="bottom-social-bar" role="navigation" aria-label="Call or WhatsApp">
  <?php if ($tel !== ''): ?><a class="bs-btn" href="<?php echo e($tel); ?>" style="background:#1d4ed8;">Call</a><?php endif; ?>
  <?php if (!empty($social['whatsapp'])): ?><a class="bs-btn" href="<?php echo e($social['whatsapp']); ?>" target="_blank" rel="noopener" style="background:#25D366;">WA</a><?php endif; ?>
  <a class="bs-btn" href="<?php echo e($ps['enquiryUrl']); ?>" style="background:#e11d48;">Ask</a>
</div>
        <?php
    }
}

if (!function_exists('public_site_render_close')) {
    function public_site_render_close(): void
    {
        ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
        <?php
    }
}
