<?php
/**
 * Public parent feedback form.
 */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (file_exists(__DIR__ . '/includes/config.php')) {
    require_once __DIR__ . '/includes/config.php';
}
if (file_exists(__DIR__ . '/includes/db.php')) {
    require_once __DIR__ . '/includes/db.php';
}
if (file_exists(__DIR__ . '/includes/functions.php')) {
    require_once __DIR__ . '/includes/functions.php';
}
if (file_exists(__DIR__ . '/includes/csrf.php')) {
    require_once __DIR__ . '/includes/csrf.php';
}
require_once __DIR__ . '/includes/simple_captcha.php';

if (!function_exists('ensure_pdo')) {
    function ensure_pdo(): ?\PDO
    {
        foreach (['pdo', 'db', 'dbh', 'DB'] as $g) {
            if (!empty($GLOBALS[$g]) && $GLOBALS[$g] instanceof \PDO) {
                $GLOBALS['pdo'] = $GLOBALS[$g];
                return $GLOBALS[$g];
            }
        }
        if (defined('DB_HOST') && defined('DB_NAME')) {
            $host = DB_HOST;
            $port = defined('DB_PORT') ? DB_PORT : 3306;
            $name = DB_NAME;
            $user = defined('DB_USER') ? DB_USER : null;
            $pass = defined('DB_PASS') ? DB_PASS : null;
            try {
                $pdo = new \PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            } catch (\PDOException $e) {
                return null;
            }
        }
        return null;
    }
}
if (!function_exists('db_get_one')) {
    function db_get_one(string $sql, array $params = [])
    {
        if (is_callable('db_fetch_one')) {
            try {
                return call_user_func('db_fetch_one', $sql, $params);
            } catch (Throwable $e) {
            }
        }
        $pdo = ensure_pdo();
        if (!($pdo instanceof \PDO)) {
            return null;
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        } catch (Throwable $e) {
            return null;
        }
    }
}
if (!function_exists('e')) {
    function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('table_exists')) {
    function table_exists(string $name): bool
    {
        try {
            $r = db_get_one(
                'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t',
                [':t' => $name]
            );
            return !empty($r) && (int) ($r['cnt'] ?? 0) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

$school = db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: [];
require_once __DIR__ . '/includes/public_site_layout.php';
$ps = public_site_prepare($school);
$homeUrl = $ps['homeUrl'];
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : (string) ($_SESSION['csrf_token'] ?? '');
if ($csrf === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $csrf = $_SESSION['csrf_token'];
}

$hasTable = table_exists('feedbacks');
$errors = [];
$old = ['name' => '', 'phone' => '', 'message' => '', 'type' => 'feedback'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasTable) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?? '';
    $message = trim((string) ($_POST['message'] ?? ''));
    $type = in_array((string) ($_POST['type'] ?? 'feedback'), ['feedback', 'suggestion'], true)
        ? (string) $_POST['type']
        : 'feedback';
    $old = ['name' => $name, 'phone' => (string) ($_POST['phone'] ?? ''), 'message' => $message, 'type' => $type];

    $token = (string) ($_POST['csrf'] ?? $_POST['csrf_token'] ?? '');
    $csrfOk = function_exists('validate_csrf_token')
        ? validate_csrf_token($token)
        : ($csrf !== '' && hash_equals($csrf, $token));
    $honeypot = trim((string) ($_POST['website'] ?? $_POST['company'] ?? ''));

    if (!$csrfOk) {
        $errors[] = 'Please refresh the page and try again.';
    }
    if ($honeypot !== '') {
        $errors[] = 'Could not send. Please try again.';
    }
    if ($name === '') {
        $errors[] = 'Please write your name.';
    }
    if (strlen($phone) < 10) {
        $errors[] = 'Please enter a 10-digit mobile number.';
    }
    if ($message === '' || mb_strlen($message) < 8) {
        $errors[] = 'Please write a short message.';
    }
    if (!simple_captcha_ok((string) ($_POST['captcha'] ?? ''))) {
        $errors[] = 'Please type the correct answer from the picture.';
    }

    if ($errors === []) {
        $phone10 = substr($phone, -10);
        try {
            $recent = db_get_one(
                "SELECT COUNT(*) AS c FROM feedbacks
                 WHERE phone LIKE :p AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
                [':p' => '%' . $phone10]
            );
            if ((int) ($recent['c'] ?? 0) >= 3) {
                $errors[] = 'Please wait a few minutes before sending again.';
            }
        } catch (Throwable $e) {
        }
    }

    if ($errors === []) {
        try {
            $pdo = ensure_pdo();
            if (!($pdo instanceof \PDO)) {
                throw new RuntimeException('no db');
            }
            $phoneStore = (strlen($phone) === 10) ? $phone : substr($phone, -10);
            $stmt = $pdo->prepare(
                'INSERT INTO feedbacks (name, phone, email, message, type, source, created_at)
                 VALUES (:name, :phone, NULL, :message, :type, :source, NOW())'
            );
            $stmt->execute([
                ':name' => $name,
                ':phone' => $phoneStore,
                ':message' => $message,
                ':type' => $type,
                ':source' => 'website',
            ]);
            $insertId = (int) $pdo->lastInsertId();
            $_SESSION['feedback_ref'] = 'FDB-' . date('Ymd') . '-' . str_pad((string) $insertId, 4, '0', STR_PAD_LEFT);
            header('Location: ' . (function_exists('site_url') ? site_url('/feedback.php?ok=1') : '/feedback.php?ok=1'));
            exit;
        } catch (Throwable $e) {
            error_log('feedback submit: ' . $e->getMessage());
            $errors[] = 'Could not send. Please try again later.';
        }
    }
}

$success = false;
$feedback_ref = '';
if (!empty($_GET['ok']) && !empty($_SESSION['feedback_ref'])) {
    $success = true;
    $feedback_ref = (string) $_SESSION['feedback_ref'];
    unset($_SESSION['feedback_ref']);
}

simple_captcha_ensure(true);
$captchaSrc = function_exists('site_url') ? site_url('/captcha.php') : '/captcha.php';

public_site_render_head($ps, 'Feedback', 'Tell Pioneer Play School what is going well or what we can improve.');
public_site_render_nav($ps, 'feedback');
?>
<section class="py-4 public-enquiry">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-6">

        <?php if (!$hasTable): ?>
          <div class="alert alert-danger">Feedback is not available right now.</div>
        <?php elseif ($success): ?>
          <div class="public-success-panel">
            <h1 class="h4 mb-2">Thank you</h1>
            <p class="mb-2">We have your message. If we need to talk, we will call you.</p>
            <p class="small text-muted mb-3">Ref <?php echo e($feedback_ref); ?></p>
            <a class="btn btn-submit text-white" href="<?php echo e($homeUrl); ?>">Back to home</a>
          </div>
        <?php else: ?>
          <div class="card card-soft p-4 border-0">
            <h1 class="h4 mb-1">Tell us how we are doing</h1>
            <p class="text-muted mb-3">One short note is enough. We read every message.</p>

            <?php if ($errors !== []): ?>
              <div class="alert alert-danger py-2"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
              <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
              <div class="fb-hp" aria-hidden="true">
                <label>Website</label>
                <input type="text" name="website" tabindex="-1" autocomplete="off">
              </div>

              <div class="mb-3">
                <span class="form-label d-block">This is</span>
                <div class="d-flex gap-2">
                  <label class="fb-type <?php echo $old['type'] === 'feedback' ? 'on' : ''; ?>">
                    <input type="radio" name="type" value="feedback" <?php echo $old['type'] === 'feedback' ? 'checked' : ''; ?>> Thanks
                  </label>
                  <label class="fb-type <?php echo $old['type'] === 'suggestion' ? 'on' : ''; ?>">
                    <input type="radio" name="type" value="suggestion" <?php echo $old['type'] === 'suggestion' ? 'checked' : ''; ?>> An idea
                  </label>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label" for="fb_name">Your name</label>
                <input id="fb_name" name="name" class="form-control" required maxlength="80" value="<?php echo e($old['name']); ?>" placeholder="Parent name">
              </div>
              <div class="mb-3">
                <label class="form-label" for="fb_phone">Mobile</label>
                <input id="fb_phone" name="phone" class="form-control" required inputmode="numeric" maxlength="15" value="<?php echo e($old['phone']); ?>" placeholder="10-digit number">
              </div>
              <div class="mb-3">
                <label class="form-label" for="fb_msg">Message</label>
                <textarea id="fb_msg" name="message" rows="4" class="form-control" required maxlength="2000" placeholder="What should we know?"><?php echo e($old['message']); ?></textarea>
              </div>

              <div class="mb-3">
                <label class="form-label" for="fb_captcha">Type the answer from the picture</label>
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <img id="fbCapImg" src="<?php echo e($captchaSrc); ?>" alt="Captcha" width="220" height="64" class="fb-captcha-img">
                  <input id="fb_captcha" name="captcha" class="form-control" required inputmode="numeric" maxlength="3" placeholder="Answer" style="max-width:110px" autocomplete="off">
                  <button type="button" class="btn btn-link btn-sm p-0" id="fbCapRefresh">New picture</button>
                </div>
              </div>

              <div class="d-grid">
                <button type="submit" class="btn btn-submit btn-lg text-white">Send</button>
              </div>
            </form>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</section>
<script>
document.querySelectorAll('.fb-type input').forEach(function (el) {
  el.addEventListener('change', function () {
    document.querySelectorAll('.fb-type').forEach(function (l) { l.classList.remove('on'); });
    el.closest('.fb-type').classList.add('on');
  });
});
var capImg = document.getElementById('fbCapImg');
var capBtn = document.getElementById('fbCapRefresh');
if (capImg && capBtn) {
  capBtn.addEventListener('click', function () {
    var u = capImg.src.split('?')[0];
    capImg.src = u + '?r=1&v=' + Date.now();
  });
}
</script>
<?php
public_site_render_footer($ps);
public_site_render_close();
