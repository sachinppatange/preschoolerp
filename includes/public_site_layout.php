<?php
/**
 * Shared public-site layout helpers (index.php, feedback.php, complaint.php).
 */
declare(strict_types=1);

if (!function_exists('public_site_prepare')) {
    function public_site_prepare(array $school = []): array
    {
        $social = json_decode($school['social_links'] ?? '[]', true) ?: [];
        $defaults = [
            'whatsapp' => 'https://wa.me/917770007801',
            'instagram' => 'https://www.instagram.com/pioneer_play_school/',
            'facebook' => 'https://www.facebook.com/pioneerplayschool',
            'youtube' => 'https://www.youtube.com/@pioneer_play_school',
            'google_map' => '#',
        ];
        foreach ($defaults as $k => $v) {
            if (empty($social[$k])) {
                $social[$k] = $v;
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
    function public_site_render_head(array $ps, string $pageHeading, string $metaDescription = ''): void
    {
        $title = e($ps['page_title']) . ' — ' . e($pageHeading);
        $meta = $metaDescription !== '' ? e(strip_tags(substr($metaDescription, 0, 160))) : '';
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
<link href="<?php echo e($ps['cssBase']); ?>/assets/css/public-site.css" rel="stylesheet">
</head>
<body class="public-site">
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
        <li class="nav-item"><a class="nav-link" href="<?php echo $home; ?>#why">Why Us</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo $home; ?>#facilities">Facilities</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo $home; ?>#gallery">Gallery</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo $home; ?>#news-events">News</a></li>
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
        ?>
<footer class="pt-4 pb-3 public-footer">
  <div class="container text-center">
    <div class="social-boxes" role="navigation" aria-label="Social links">

      <a class="social-box bi-person-check" href="<?php echo $loginUrl; ?>">
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
      <?php if ($ps['contact_phone']): ?>📞 <?php echo e($ps['contact_phone']); ?> <?php endif; ?>
      <?php if ($ps['contact_email']): ?> • ✉ <?php echo e($ps['contact_email']); ?><?php endif; ?>
    </div>

    <div class="small text-muted mt-1">&copy; <?php echo date('Y') . ' ' . e($ps['page_title']); ?>. All rights reserved.</div>
  </div>
</footer>

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
