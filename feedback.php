<?php
/**
 * /pioneerplayschool01/feedback.php
 *
 * Public Feedback & Suggestion Box page (styled to match index.php).
 * - Anyone can submit feedback or suggestion.
 * - Saves to `feedbacks` table:
 *     id, name, phone, email, message, type (feedback|suggestion), source, created_at
 * - Uses project includes when available (includes/config.php, includes/db.php, includes/functions.php).
 * - Falls back to minimal PDO helpers if necessary.
 * - On success redirects to ?ok=1 and shows a success panel with a reference code and print option.
 *
 * Place this file at: /pioneerplayschool01/feedback.php
 */

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

/* Optional project includes */
if (file_exists(__DIR__ . '/includes/config.php')) require_once __DIR__ . '/includes/config.php';
if (file_exists(__DIR__ . '/includes/db.php'))     require_once __DIR__ . '/includes/db.php';
if (file_exists(__DIR__ . '/includes/functions.php')) require_once __DIR__ . '/includes/functions.php';

/* ---------------------------
   Minimal helpers (compatible with index.php)
   --------------------------- */
if (!function_exists('ensure_pdo')) {
    function ensure_pdo(): ?\PDO {
        foreach (['pdo','db','dbh','DB'] as $g) {
            if (!empty($GLOBALS[$g]) && $GLOBALS[$g] instanceof \PDO) { $GLOBALS['pdo'] = $GLOBALS[$g]; return $GLOBALS[$g]; }
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
            } catch (\PDOException $e) { error_log('ensure_pdo: ' . $e->getMessage()); return null; }
        }
        if (defined('DB_HOST') && defined('DB_NAME')) {
            $host = DB_HOST; $port = defined('DB_PORT') ? DB_PORT : 3306; $name = DB_NAME;
            $user = defined('DB_USER') ? DB_USER : null; $pass = defined('DB_PASS') ? DB_PASS : null;
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            try {
                $pdo = new \PDO($dsn, $user, $pass, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            } catch (\PDOException $e) { error_log('ensure_pdo fallback: ' . $e->getMessage()); return null; }
        }
        return null;
    }
}

if (!function_exists('db_run')) {
    function db_run(string $sql, array $params = []): bool {
        if (is_callable('db_execute')) {
            try { return (bool) call_user_func('db_execute', $sql, $params); } catch (Throwable $e) {}
        }
        $pdo = ensure_pdo();
        if (!($pdo instanceof \PDO)) return false;
        try { $stmt = $pdo->prepare($sql); return (bool)$stmt->execute($params); }
        catch (Throwable $e) { error_log('db_run error: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('db_get_one')) {
    function db_get_one(string $sql, array $params = []) {
        if (is_callable('db_fetch_one')) {
            try { return call_user_func('db_fetch_one', $sql, $params); } catch (Throwable $e) {}
        }
        $pdo = ensure_pdo();
        if (!($pdo instanceof \PDO)) return null;
        try { $stmt = $pdo->prepare($sql); $stmt->execute($params); $row = $stmt->fetch(); return $row === false ? null : $row; }
        catch (Throwable $e) { error_log('db_get_one error: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('e')) { function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }

if (!function_exists('table_exists')) {
    function table_exists(string $name): bool {
        try {
            $r = db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
            return !empty($r) && intval($r['cnt']) > 0;
        } catch (Throwable $e) { return false; }
    }
}

/* ---------------------------
   Load site branding (from DB like index.php)
   --------------------------- */
$school = db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id'=>1]) ?: [];
require_once __DIR__ . '/includes/public_site_layout.php';
$ps = public_site_prepare($school);
$hero_subtitle = $school['hero_subtitle'] ?? $school['short_about'] ?? 'Share your feedback or suggestion — we appreciate your input.';
$homeUrl = $ps['homeUrl'];

/* ---------------------------
   Ensure feedbacks table exists
   --------------------------- */
if (!table_exists('feedbacks')) {
    http_response_code(500);
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Missing table</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>
    <body class="bg-light">
    <div class="container py-5">
      <div class="alert alert-danger">The <strong>feedbacks</strong> table does not exist. Please create it first.</div>
      <p>Example SQL (run once):</p>
      <pre>CREATE TABLE IF NOT EXISTS `feedbacks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(191) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(191) DEFAULT NULL,
  `message` TEXT,
  `type` VARCHAR(50) DEFAULT 'feedback',
  `source` VARCHAR(100) DEFAULT 'website',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
      <a class="btn btn-primary" href="<?php echo e(function_exists('site_url') ? site_url('/') : '/'); ?>">Back to Home</a>
    </div></body></html>
    <?php
    exit;
}

/* ---------------------------
   Handle POST submission
   --------------------------- */
$errors = [];
$old = ['name'=>'','phone'=>'','email'=>'','message'=>'','type'=>'feedback'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $type = in_array($_POST['type'] ?? 'feedback', ['feedback','suggestion'], true) ? $_POST['type'] : 'feedback';

    $old['name'] = $name; $old['phone'] = $phone; $old['email'] = $email; $old['message'] = $message; $old['type'] = $type;

    if ($message === '') $errors[] = 'Please enter your feedback or suggestion.';
    if ($phone === '' && $email === '') $errors[] = 'Please provide a phone number or email so we can contact you.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please provide a valid email address.';

    if (empty($errors)) {
        try {
            $pdo = ensure_pdo();
            if (!($pdo instanceof \PDO)) throw new \RuntimeException('Database connection not available.');

            // adapt source to column safely
            $desired_source = 'website';
            $final_source = $desired_source;
            try {
                $sth = $pdo->prepare("SELECT COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feedbacks' AND COLUMN_NAME = 'source' LIMIT 1");
                $sth->execute();
                $col = $sth->fetch(\PDO::FETCH_ASSOC);
                if ($col) {
                    $ctype = $col['COLUMN_TYPE'] ?? '';
                    $max = !empty($col['CHARACTER_MAXIMUM_LENGTH']) ? (int)$col['CHARACTER_MAXIMUM_LENGTH'] : 0;
                    if ($ctype && stripos($ctype,'enum(')===0) {
                        preg_match_all("/'([^']*)'/", $ctype, $m);
                        $enum = $m[1] ?? [];
                        if (!in_array($desired_source, $enum, true)) $final_source = $enum[0] ?? substr($desired_source,0,50);
                    } elseif ($max > 0 && strlen($desired_source) > $max) {
                        $final_source = substr($desired_source,0,$max);
                    }
                }
            } catch (Throwable $t) { /* ignore */ }

            $stmt = $pdo->prepare("INSERT INTO feedbacks (name, phone, email, message, type, source, created_at) VALUES (:name,:phone,:email,:message,:type,:source,NOW())");
            $stmt->execute([
                ':name' => $name !== '' ? $name : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':email' => $email !== '' ? $email : null,
                ':message' => $message,
                ':type' => $type,
                ':source' => $final_source
            ]);
            $insertId = (int)$pdo->lastInsertId();
            $ref = 'FDB-' . date('Ymd') . '-' . str_pad((string)$insertId, 4, '0', STR_PAD_LEFT);
            $_SESSION['feedback_ref'] = $ref;

            header('Location: ' . (function_exists('site_url') ? site_url('/feedback.php?ok=1') : '/feedback.php?ok=1'));
            exit;
        } catch (Throwable $e) {
            error_log('feedback submit error: ' . $e->getMessage());
            $errors[] = 'Database error. Please try again later.';
        }
    }
}

/* After redirect show ref */
$success = false;
$feedback_ref = '';
if (!empty($_GET['ok']) && !empty($_SESSION['feedback_ref'])) {
    $success = true;
    $feedback_ref = $_SESSION['feedback_ref'];
    unset($_SESSION['feedback_ref']);
}

/* ---------------------------
   Render page (matching index.php style)
   --------------------------- */
public_site_render_head($ps, 'Feedback & Suggestions', $hero_subtitle);
public_site_render_nav($ps, 'feedback');
public_site_render_hero('Feedback & Suggestion Box', $hero_subtitle, $ps['hero']);
?>
<section class="py-5 public-enquiry">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-8">

        <?php if ($success): ?>
          <div class="public-success-panel mb-4">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <h3 class="mb-1">Thank you — your submission is received</h3>
                <p class="mb-1 text-muted">We appreciate your feedback. We will review it and respond if necessary.</p>
                <p class="mb-0"><strong>Reference:</strong> <?php echo e($feedback_ref); ?></p>
                <div class="mt-3 small text-muted">
                  <ul class="mb-0">
                    <li>We may contact you for clarifications if you provided contact details.</li>
                    <li>If urgent, please contact: <strong><?php echo e($school['contact_phone'] ?? ''); ?></strong></li>
                  </ul>
                </div>
              </div>
              <div class="text-end ms-3">
                <button class="btn btn-print mb-2" onclick="window.print();">Print Receipt</button>
                <a class="btn btn-outline-secondary" href="<?php echo e($homeUrl); ?>">Back to Home</a>
              </div>
            </div>
          </div>
        <?php else: ?>

          <div class="card card-soft p-4 mb-3 border-0">
            <h3 class="text-center fw-bold section-title d-block mb-3">Send Feedback or Suggestion</h3>

            <?php if (!empty($errors)): ?>
              <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
            <?php endif; ?>

            <form method="post" novalidate>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Your name</label>
                  <input name="name" class="form-control" value="<?php echo e($old['name']); ?>" placeholder="Parent / Guardian name">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Phone</label>
                  <input name="phone" class="form-control" value="<?php echo e($old['phone']); ?>" placeholder="+91 98765 43210">
                </div>

                <div class="col-12">
                  <label class="form-label">Email</label>
                  <input name="email" class="form-control" value="<?php echo e($old['email']); ?>" placeholder="your@email.com">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Type</label>
                  <select name="type" class="form-select">
                    <option value="feedback" <?php if(($old['type'] ?? '')==='feedback') echo 'selected'; ?>>Feedback</option>
                    <option value="suggestion" <?php if(($old['type'] ?? '')==='suggestion') echo 'selected'; ?>>Suggestion</option>
                  </select>
                </div>

                <div class="col-12">
                  <label class="form-label">Message *</label>
                  <textarea name="message" rows="6" class="form-control" required><?php echo e($old['message']); ?></textarea>
                </div>

                <input type="hidden" name="source" value="website">

                <div class="col-12">
                  <div class="d-grid d-md-flex justify-content-md-end gap-2">
                    <a href="<?php echo e($homeUrl); ?>" class="btn btn-outline-secondary">Back</a>
                    <button type="submit" class="btn btn-submit btn-lg text-white">Submit</button>
                  </div>
                </div>
              </div>
            </form>
          </div>

          <div class="card p-3 small text-muted">
            <strong>Tips:</strong>
            <ul class="mb-0">
              <li>Be specific — tell us what worked or what you'd like to see improved.</li>
              <li>Provide contact details if you'd like a response.</li>
            </ul>
          </div>

        <?php endif; ?>

      </div>
    </div>
  </div>
</section>
<?php
public_site_render_footer($ps);
public_site_render_close();