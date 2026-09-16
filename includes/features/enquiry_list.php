<?php
/**
 * Shared feature: enquiry_list
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');

$pageTitle = (string)($cfg['page_title'] ?? 'Enquiry List');
$authRoles = $cfg['auth_roles'] ?? $panel;
if (!is_array($authRoles)) {
    $authRoles = [$authRoles];
}
$sourceDefaultAdd = (string)($cfg['source_default_add'] ?? 'owner_added');
$sourceDefaultEdit = (string)($cfg['source_default_edit'] ?? $sourceDefaultAdd);

$esc = function(string $v) { return e($v); };

/* Status column info */
function get_status_column_info(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = ['type'=>null,'enum_values'=>[],'max_length'=>0];
    try {
        $r = safe_db_get_one("SELECT COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'status' LIMIT 1");
        if ($r) {
            $ctype = $r['COLUMN_TYPE'] ?? '';
            $max = !empty($r['CHARACTER_MAXIMUM_LENGTH']) ? (int)$r['CHARACTER_MAXIMUM_LENGTH'] : 0;
            if ($ctype && stripos($ctype,'enum(') === 0) {
                preg_match_all("/'([^']*)'/", $ctype, $m);
                $vals = $m[1] ?? [];
                $cache = ['type'=>'enum','enum_values'=>$vals,'max_length'=>0];
            } else {
                $cache = ['type'=>'char','enum_values'=>[],'max_length'=>$max];
            }
        }
    } catch (Throwable $e) {}
    return $cache;
}
function normalize_status(string $desired): ?string {
    $info = get_status_column_info();
    $desired = (string)$desired;
    if ($info['type'] === 'enum' && !empty($info['enum_values'])) {
        $allowed = $info['enum_values'];
        if (in_array($desired,$allowed,true)) return $desired;
        $map = ['in_progress'=>['in_progress','inprogress','in-progress','in progress'],'new'=>['new'],'closed'=>['closed','done']];
        foreach ($map as $k=>$aliases) {
            foreach ($aliases as $a) if (in_array($a,$allowed,true) && ($desired===$k||$desired===$a)) return $a;
        }
        return $allowed[0] ?? null;
    } else {
        $max = intval($info['max_length'] ?? 0);
        if ($max>0 && mb_strlen($desired)>$max) return mb_substr($desired,0,$max);
        return $desired;
    }
}

/* Ensure enquiries table exists */
if (!table_exists('enquiries')) {
    require_once __DIR__ . '/../header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>enquiries</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

/* Check whether enquiries table has dedicated age_group column */
$has_age_column = column_exists('enquiries', 'age_group');

/* Public submission from index.php: POST with enquire_action=submit_enquiry */
$public_submit = ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['enquire_action'] ?? '') === 'submit_enquiry' || ($_POST['enquire_action'] ?? '') === 'submit_enquiry_public'));

/* If not owner and not public submit, redirect to login */
if (!$public_submit && !auth_is_logged_in($authRoles)) {
    header('Location: ' . auth_login_url($panel));
    exit;
}

/* Prepare containers */
$messages = []; $errors = []; $enq_errors = []; $enq_success = '';

/* Supported age options (same as index.php request) */
$age_options = ['1-2'=>'1–2 Years','2-3'=>'2–3 Years','3-4'=>'3–4 Years','4-5'=>'4–5 Years','5-6'=>'5–6 Years'];

/* -------------------------
   Handle public submission (index.php form)
   ------------------------- */
if ($public_submit) {
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
            // adapt to source column constraints
            try {
                $pdoTmp = pdo_connect();
                if ($pdoTmp instanceof \PDO) {
                    $sth = $pdoTmp->prepare("SELECT CHARACTER_MAXIMUM_LENGTH, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'source' LIMIT 1");
                    $sth->execute();
                    $col = $sth->fetch(\PDO::FETCH_ASSOC);
                    if ($col) {
                        $maxLen = !empty($col['CHARACTER_MAXIMUM_LENGTH']) ? (int)$col['CHARACTER_MAXIMUM_LENGTH'] : 0;
                        if (!empty($col['COLUMN_TYPE']) && stripos($col['COLUMN_TYPE'],'enum(')===0) {
                            preg_match_all("/'([^']*)'/", $col['COLUMN_TYPE'], $m);
                            $enum = $m[1] ?? [];
                            if (!in_array($desired_source,$enum,true)) $final_source = $enum[0] ?? substr($desired_source,0,50);
                        } elseif ($maxLen>0 && strlen($desired_source)>$maxLen) {
                            $final_source = substr($desired_source,0,$maxLen);
                        }
                    }
                }
            } catch (Throwable $ignore) {}

            $pdo = pdo_connect();
            if (!($pdo instanceof \PDO)) {
                $enq_errors[] = 'Database connection not available.';
            } else {
                // determine how to store age_group: dedicated column or prefix message
                if ($has_age_column) {
                    $sql = "INSERT INTO enquiries (school_id,name,age_group,phone,source,message,status,created_at,updated_at)
                            VALUES (:school_id,:name,:age_group,:phone,:source,:message,'new',NOW(),NOW())";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':age_group', $age_group !== '' ? $age_group : null, $age_group !== '' ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
                } else {
                    $sql = "INSERT INTO enquiries (school_id,name,phone,source,message,status,created_at,updated_at)
                            VALUES (:school_id,:name,:phone,:source,:message,'new',NOW(),NOW())";
                    $stmt = $pdo->prepare($sql);
                    // prepend age into message if provided (same as index.php earlier behaviour)
                    if ($age_group !== '') $message = "Age group: {$age_group}\n" . $message;
                }

                $stmt->bindValue(':school_id', auth_school_id(), \PDO::PARAM_INT);
                $stmt->bindValue(':name', $name, \PDO::PARAM_STR);
                $stmt->bindValue(':phone', $phone, \PDO::PARAM_STR);
                $stmt->bindValue(':source', $final_source, \PDO::PARAM_STR);
                $stmt->bindValue(':message', $message, \PDO::PARAM_STR);

                $ok = $stmt->execute();
                if ($ok) {
                    // redirect back to index to avoid resubmit
                    header('Location: ' . (function_exists('site_url') ? site_url('/index.php?enq=ok') : '/index.php?enq=ok'));
                    exit;
                } else {
                    $enq_errors[] = 'Failed to submit enquiry.';
                }
            }
        } catch (Throwable $e) {
            error_log('Public enquiry error: ' . $e->getMessage());
            $enq_errors[] = $DEBUG ? 'DB error: ' . $e->getMessage() : 'Database error.';
        }
    }
}

/* If we handled a public submission and user is not owner, show errors or stop (success redirected) */
if ($public_submit && !auth_is_logged_in($authRoles)) {
    if (!empty($enq_errors)) {
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><body><div class="container py-4">';
        foreach ($enq_errors as $ee) echo '<div class="alert alert-danger">' . e($ee) . '</div>';
        echo '<a href="/index.php" class="btn btn-primary">Back</a></div></body></html>';
    }
    exit;
}

/* From here on: owner (authenticated) features only */
/* -------------------------
   Actions (owner)
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';

/* ADD (owner modal) */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
    $name = trim((string)($_POST['name'] ?? ''));
    $age_group = trim((string)($_POST['age_group'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
    $source = trim((string)($_POST['source'] ?? $sourceDefaultAdd));
    $message = trim((string)($_POST['message'] ?? ''));
    $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null;

    if ($name === '') $errors[] = 'Please enter name.';
    if ($phone === '') $errors[] = 'Please enter phone.';

    if (empty($errors)) {
        $pdo = pdo_connect();
        if (!($pdo instanceof \PDO)) $errors[] = 'Database connection not available.';
        else {
            try {
                $statusToWrite = normalize_status('new') ?? 'new';
                if ($has_age_column) {
                    $sql = "INSERT INTO enquiries (school_id,name,age_group,phone,source,message,assigned_to,status,created_at,updated_at)
                            VALUES (:school_id,:name,:age_group,:phone,:source,:message,:assigned_to,:status,NOW(),NOW())";
                } else {
                    $sql = "INSERT INTO enquiries (school_id,name,phone,source,message,assigned_to,status,created_at,updated_at)
                            VALUES (:school_id,:name,:phone,:source,:message,:assigned_to,:status,NOW(),NOW())";
                    if ($age_group !== '') $message = "Age group: {$age_group}\n" . $message;
                }

                $stmt = $pdo->prepare($sql);
                if ($school_id === null) $stmt->bindValue(':school_id', null, \PDO::PARAM_NULL); else $stmt->bindValue(':school_id', $school_id, \PDO::PARAM_INT);
                $stmt->bindValue(':name', $name, \PDO::PARAM_STR);
                if ($has_age_column) $stmt->bindValue(':age_group', $age_group !== '' ? $age_group : null, $age_group !== '' ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
                $stmt->bindValue(':phone', $phone, \PDO::PARAM_STR);
                $stmt->bindValue(':source', $source, \PDO::PARAM_STR);
                $stmt->bindValue(':message', $message, \PDO::PARAM_STR);
                if ($assigned_to === null) $stmt->bindValue(':assigned_to', null, \PDO::PARAM_NULL); else $stmt->bindValue(':assigned_to', $assigned_to, \PDO::PARAM_INT);
                $stmt->bindValue(':status', $statusToWrite, \PDO::PARAM_STR);

                $ok = $stmt->execute();
                if ($ok) { $messages[] = 'Enquiry added successfully.'; header('Location: ?'); exit; }
                else $errors[] = 'Failed to save enquiry.';
            } catch (Throwable $e) {
                error_log('enquiry add error: ' . $e->getMessage());
                $errors[] = $DEBUG ? 'DB error: ' . $e->getMessage() : 'Failed to save enquiry.';
            }
        }
    }
}

/* VIEW (modal fragment) */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger">Invalid id</div>'; exit; }
    $row = safe_db_get_one("SELECT e.*, COALESCE(s.name,'') AS school_name, COALESCE(u.name,'') AS assignee_name
                            FROM enquiries e
                            LEFT JOIN schools s ON s.id = e.school_id
                            LEFT JOIN users u ON u.id = e.assigned_to
                            WHERE e.id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted">Enquiry not found</div>'; exit; }

    echo '<dl class="row">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">School</dt><dd class="col-sm-9">'.($row['school_name'] ? $esc($row['school_name']) : '—').'</dd>';
    echo '<dt class="col-sm-3">Name</dt><dd class="col-sm-9">'.$esc($row['name']).'</dd>';
    if ($has_age_column) {
        echo '<dt class="col-sm-3">Child Age</dt><dd class="col-sm-9">'.($row['age_group'] ? $esc($row['age_group']) : '—').'</dd>';
    } else {
        // if age stored in message as prefix, try to extract first line if it starts with "Age group:"
        $ageLine = '';
        if (!empty($row['message'])) {
            if (preg_match('/^Age group:\s*([^\r\n]+)/i', $row['message'], $m)) $ageLine = $m[1];
        }
        echo '<dt class="col-sm-3">Child Age</dt><dd class="col-sm-9">'.($ageLine ? $esc($ageLine) : '—').'</dd>';
    }
    echo '<dt class="col-sm-3">Phone</dt><dd class="col-sm-9">'.$esc($row['phone']).'</dd>';
    echo '<dt class="col-sm-3">Source</dt><dd class="col-sm-9">'.$esc($row['source']).'</dd>';
    echo '<dt class="col-sm-3">Assigned</dt><dd class="col-sm-9">'.($row['assignee_name'] ? $esc($row['assignee_name']) : 'Unassigned').'</dd>';
    echo '<dt class="col-sm-3">Status</dt><dd class="col-sm-9">'.$esc($row['status'] ?? '').'</dd>';
    echo '<dt class="col-sm-3">Created</dt><dd class="col-sm-9">'.$esc($row['created_at']).'</dd>';
    echo '<dt class="col-sm-3">Updated</dt><dd class="col-sm-9">'.$esc($row['updated_at']).'</dd>';
    echo '<dt class="col-12">Message</dt><dd class="col-12"><pre style="white-space:pre-wrap;">'.$esc($row['message']).'</pre></dd>';
    echo '</dl>';
    exit;
}

/* GET (for edit modal) returns JSON */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    if ($id <= 0) { echo json_encode(['error'=>'Invalid id']); exit; }
    $row = safe_db_get_one("SELECT id, school_id, name, phone, source, message, assigned_to, status" . ($has_age_column ? ", age_group" : "") . " FROM enquiries WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Enquiry not found']); exit; }

    // if no dedicated age column, attempt to extract age from message prefix "Age group: X"
    if (!$has_age_column) {
        $ageVal = '';
        if (!empty($row['message']) && preg_match('/^Age group:\s*([^\r\n]+)/i', $row['message'], $m)) {
            $ageVal = trim($m[1]);
            // remove the age prefix from message for editing convenience
            $row['message'] = preg_replace('/^Age group:\s*[^\r\n]+\r?\n?/i', '', $row['message']);
        }
        $row['age_group'] = $ageVal;
    }

    echo json_encode(['ok'=>true, 'data'=>$row]);
    exit;
}

/* EDIT (owner) */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
    $name = trim((string)($_POST['name'] ?? ''));
    $age_group = trim((string)($_POST['age_group'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
    $source = trim((string)($_POST['source'] ?? $sourceDefaultEdit));
    $message = trim((string)($_POST['message'] ?? ''));
    $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null;
    $status_in = trim((string)($_POST['status'] ?? 'new'));
    $status = normalize_status($status_in) ?? 'new';

    if ($id <= 0) $errors[] = 'Invalid enquiry id.';
    if ($name === '') $errors[] = 'Name required.';
    if ($phone === '') $errors[] = 'Phone required.';

    if (empty($errors)) {
        $pdo = pdo_connect();
        if (!($pdo instanceof \PDO)) $errors[] = 'Database connection not available.';
        else {
            try {
                if ($has_age_column) {
                    $sql = "UPDATE enquiries SET school_id = :school_id, name = :name, age_group = :age_group, phone = :phone, source = :source, message = :message, assigned_to = :assigned_to, status = :status, updated_at = NOW() WHERE id = :id";
                } else {
                    // remove existing Age group prefix from stored message (if present) and prepend new one
                    $existing = safe_db_get_one("SELECT message FROM enquiries WHERE id = :id LIMIT 1", [':id'=>$id]);
                    $existingMsg = $existing['message'] ?? '';
                    $existingMsg = preg_replace('/^Age group:\s*[^\r\n]+\r?\n?/i', '', $existingMsg);
                    $newMessage = ($age_group !== '' ? "Age group: {$age_group}\n" : '') . $message;
                    $message = $newMessage;
                    $sql = "UPDATE enquiries SET school_id = :school_id, name = :name, phone = :phone, source = :source, message = :message, assigned_to = :assigned_to, status = :status, updated_at = NOW() WHERE id = :id";
                }

                $stmt = $pdo->prepare($sql);
                if ($school_id === null) $stmt->bindValue(':school_id', null, \PDO::PARAM_NULL); else $stmt->bindValue(':school_id', $school_id, \PDO::PARAM_INT);
                $stmt->bindValue(':name', $name, \PDO::PARAM_STR);
                if ($has_age_column) $stmt->bindValue(':age_group', $age_group !== '' ? $age_group : null, $age_group !== '' ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
                $stmt->bindValue(':phone', $phone, \PDO::PARAM_STR);
                $stmt->bindValue(':source', $source, \PDO::PARAM_STR);
                $stmt->bindValue(':message', $message, \PDO::PARAM_STR);
                if ($assigned_to === null) $stmt->bindValue(':assigned_to', null, \PDO::PARAM_NULL); else $stmt->bindValue(':assigned_to', $assigned_to, \PDO::PARAM_INT);
                $stmt->bindValue(':status', $status, \PDO::PARAM_STR);
                $stmt->bindValue(':id', $id, \PDO::PARAM_INT);

                $ok = $stmt->execute();
                if ($ok) { $messages[] = 'Enquiry updated.'; header('Location: ?'); exit; }
                else $errors[] = 'Failed to update enquiry.';
            } catch (Throwable $e) {
                error_log('enquiry edit error: ' . $e->getMessage());
                $errors[] = $DEBUG ? 'DB error: ' . $e->getMessage() : 'Failed to update enquiry.';
            }
        }
    }
}

/* ASSIGN */
if ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $assigned_to = (isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '') ? (int)$_POST['assigned_to'] : null;
    if ($id <= 0) $errors[] = 'Invalid enquiry id.'; else {
        $ok = safe_db_run("UPDATE enquiries SET assigned_to = :a, updated_at = NOW() WHERE id = :id", [':a'=>$assigned_to, ':id'=>$id]);
        if ($ok) $messages[] = 'Assignee updated.'; else $errors[] = 'Failed to update assignee.';
    }
}

/* STATUS */
if ($action === 'status' && !empty($_GET['id']) && !empty($_GET['to'])) {
    $id = (int)$_GET['id']; $to = normalize_status($_GET['to']);
    if ($to === null) $errors[] = 'Invalid status value.'; else {
        $ok = safe_db_run("UPDATE enquiries SET status = :s, updated_at = NOW() WHERE id = :id", [':s'=>$to, ':id'=>$id]);
        if ($ok) $messages[] = 'Status updated.'; else $errors[] = 'Status update failed.';
    }
}

/* DELETE */
/* BACKUP: Phase-A — delete requires POST + CSRF (was unsafe GET) */
if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    if (isset($errors) && is_array($errors)) { $errors[] = 'Delete requires confirmation (POST).'; }
    elseif (isset($messages) && is_array($messages)) { $messages[] = 'Delete requires confirmation (POST).'; }
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    $id = $deleteId; $ok = safe_db_run("DELETE FROM enquiries WHERE id = :id", [':id'=>$id]);
    if ($ok) $messages[] = 'Enquiry deleted.'; else $errors[] = 'Delete failed.';
}

/* EXPORT */
if ($action === 'export') {
    $where=[];$params=[];
    if (!empty($_GET['q'])) { $where[]="(name LIKE :q OR phone LIKE :q OR message LIKE :q OR source LIKE :q)"; $params[':q']='%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['status'])) { $s=normalize_status($_GET['status']); if ($s!==null) { $where[]="status=:status"; $params[':status']=$s; } }
    if (!empty($_GET['school_id'])) { $where[]="school_id = :school_id"; $params[':school_id'] = (int)$_GET['school_id']; }
    if (function_exists('ay_apply_date_filter')) {
        ay_apply_date_filter($where, $params, 'created_at');
    }
    $whereSql = $where?('WHERE '.implode(' AND ',$where)):'';
    $rows = safe_db_get_all("SELECT * FROM enquiries $whereSql ORDER BY created_at DESC", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=enquiries_'.date('Ymd_His').'.csv');
    $out = fopen('php://output','w');
    fputcsv($out,['ID','School ID','Name','Age Group','Phone','Source','Assigned','Status','Created','Updated','Message']);
    foreach ($rows as $r) {
        $ageVal = $has_age_column ? ($r['age_group'] ?? '') : '';
        if (!$has_age_column && empty($ageVal) && !empty($r['message']) && preg_match('/^Age group:\s*([^\r\n]+)/i',$r['message'],$m)) $ageVal = $m[1];
        fputcsv($out,[$r['id'],$r['school_id'] ?? '',$r['name'] ?? '',$ageVal,$r['phone'] ?? '',$r['source'] ?? '',$r['assigned_to'] ?? '',$r['status'] ?? '',$r['created_at'] ?? '',$r['updated_at'] ?? '',preg_replace("/\r\n|\r|\n/"," ",$r['message'] ?? '')]);
    }
    fclose($out); exit;
}

/* -------------------------
   Filters & Pagination
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25; $offset = ($page - 1) * $perPage;
$where = []; $params = [];
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') { $where[] = "(e.name LIKE :q OR e.phone LIKE :q OR e.message LIKE :q OR e.source LIKE :q)"; $params[':q'] = '%'.$qraw.'%'; }
$statusFilter = $_GET['status'] ?? '';
if ($statusFilter !== '' && normalize_status($statusFilter) !== null) { $where[] = "e.status = :status"; $params[':status'] = normalize_status($statusFilter); }
$from = $_GET['from'] ?? ''; if ($from !== '') { $where[] = "e.created_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
$to   = $_GET['to'] ?? '';   if ($to   !== '') { $where[] = "e.created_at <= :to";   $params[':to']   = $to . ' 23:59:59'; }
if (!empty($_GET['school_id'])) { $where[] = "e.school_id = :school_id"; $params[':school_id'] = (int)$_GET['school_id']; }
if (function_exists('ay_apply_date_filter')) {
    ay_apply_date_filter($where, $params, 'e.created_at');
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM enquiries e " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$enquiries = [];
try {
    $joinSchool = table_exists('schools') ? "LEFT JOIN schools s ON s.id = e.school_id" : "";
    $joinUsers  = table_exists('users')   ? "LEFT JOIN users u ON u.id = e.assigned_to" : "";

    $sql = "SELECT e.id, e.school_id, COALESCE(s.name,'') AS school_name, e.name, " . ($has_age_column ? "e.age_group, " : "") . " e.phone, e.source, e.message, e.assigned_to, COALESCE(u.name,'') AS assignee_name, e.status, e.created_at, e.updated_at
            FROM enquiries e
            $joinSchool
            $joinUsers
            $whereSql
            ORDER BY e.created_at DESC
            LIMIT :limit OFFSET :offset";

    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $enquiries = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $enquiries = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
    }
} catch (Throwable $e) {
    $errors[] = 'List fetch failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

/* Data for forms */
$staffList = [];
if (table_exists('users')) {
    if (column_exists('users', 'is_active')) $staffList = safe_db_get_all("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name ASC");
    elseif (column_exists('users', 'active')) $staffList = safe_db_get_all("SELECT id, name FROM users WHERE active = 1 ORDER BY name ASC");
    else $staffList = safe_db_get_all("SELECT id, name FROM users ORDER BY name ASC");
}
$schoolList = table_exists('schools') ? safe_db_get_all("SELECT id, name FROM schools ORDER BY name ASC") : [];

$statusInfo = get_status_column_info();
$uiStatusOptions = ($statusInfo['type'] === 'enum' && !empty($statusInfo['enum_values'])) ? $statusInfo['enum_values'] : ['new','in_progress','closed'];

$ayStatsParams = function_exists('ay_range') ? [
    ':panel_ay_start' => ay_range()['start'],
    ':panel_ay_end' => ay_range()['end'] . ' 23:59:59',
] : [];
$ayStatsWhere = $ayStatsParams ? ' AND created_at BETWEEN :panel_ay_start AND :panel_ay_end' : '';
$stats = [
    'total' => $total,
    'today' => intval((safe_db_get_one("SELECT COUNT(*) AS c FROM enquiries WHERE DATE(created_at) = CURDATE(){$ayStatsWhere}", $ayStatsParams)['c'] ?? 0)),
    'new' => intval((safe_db_get_one("SELECT COUNT(*) AS c FROM enquiries WHERE status = 'new'{$ayStatsWhere}", $ayStatsParams)['c'] ?? 0)),
    'in_progress' => intval((safe_db_get_one("SELECT COUNT(*) AS c FROM enquiries WHERE status = 'in_progress'{$ayStatsWhere}", $ayStatsParams)['c'] ?? 0)),
    'closed' => intval((safe_db_get_one("SELECT COUNT(*) AS c FROM enquiries WHERE status = 'closed'{$ayStatsWhere}", $ayStatsParams)['c'] ?? 0)),
    'unassigned' => intval((safe_db_get_one("SELECT COUNT(*) AS c FROM enquiries WHERE (assigned_to IS NULL OR assigned_to = ''){$ayStatsWhere}", $ayStatsParams)['c'] ?? 0)),
];

$totalPages = (int)ceil(max(0, $total) / $perPage);
function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

/* Render header/footer if present */
$pageTitle = (string)($cfg['page_title'] ?? 'Enquiry List');
require_once __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-end gap-2 mb-3">
<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addEnquiryModal">Add Enquiry</button>
      <a class="btn btn-outline-secondary" href="?">Refresh</a>
    </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo $esc($err); ?></div><?php endforeach; ?>

  <!-- stats -->
  <div class="row g-2 mb-3">
    <div class="col-sm-2"><div class="card p-2 text-center"><div class="h5 mb-0"><?php echo $esc((string)$stats['total']); ?></div><div class="small text-muted">Total</div></div></div>
    <div class="col-sm-2"><div class="card p-2 text-center"><div class="h5 mb-0"><?php echo $esc((string)$stats['today']); ?></div><div class="small text-muted">Today</div></div></div>
    <div class="col-sm-2"><div class="card p-2 text-center"><div class="h5 mb-0"><?php echo $esc((string)$stats['new']); ?></div><div class="small text-muted">New</div></div></div>
    <div class="col-sm-2"><div class="card p-2 text-center"><div class="h5 mb-0"><?php echo $esc((string)$stats['in_progress']); ?></div><div class="small text-muted">In Progress</div></div></div>
    <div class="col-sm-2"><div class="card p-2 text-center"><div class="h5 mb-0"><?php echo $esc((string)$stats['closed']); ?></div><div class="small text-muted">Closed</div></div></div>
    <div class="col-sm-2"><div class="card p-2 text-center"><div class="h5 mb-0"><?php echo $esc((string)$stats['unassigned']); ?></div><div class="small text-muted">Unassigned</div></div></div>
  </div>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="Name, phone, message or source"></div>
      <div class="col-md-2"><label class="form-label">Status</label>
        <select name="status" class="form-select"><option value="">Any</option><?php foreach ($uiStatusOptions as $opt): ?><option value="<?php echo $esc($opt); ?>" <?php if(($statusFilter ?? '')===$opt) echo 'selected'; ?>><?php echo $esc(ucfirst(str_replace('_',' ',$opt))); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo $esc($from); ?>"></div>
      <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?php echo $esc($to); ?>"></div>
      <div class="col-md-2 text-end"><button class="btn btn-primary">Filter</button> <a class="btn btn-outline-secondary" href="?">Reset</a></div>
      <div class="col-12 text-end mt-2"><a class="btn btn-sm btn-success" href="?action=export&<?php echo build_qs(); ?>">Export CSV</a></div>
    </form>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th style="width:160px">Created</th>
            <th>Name / Phone / Message</th>
            <th style="width:260px">School / Assigned</th>
            <th style="width:160px">Status</th>
            <th style="width:120px">Source</th>
            <th style="width:220px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($enquiries)): foreach ($enquiries as $r): ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo $esc(substr($r['created_at'] ?? '',0,16)); ?></td>
              <td>
                <div class="fw-semibold"><?php echo $esc($r['name']); ?></div>
                <div class="small text-muted"><?php echo $esc($r['phone']); ?></div>
                <div class="small text-muted"><?php
                    // show age if available
                    $ageDisplay = '';
                    if ($has_age_column && isset($r['age_group'])) $ageDisplay = $r['age_group'];
                    else if (!$has_age_column && !empty($r['message']) && preg_match('/^Age group:\s*([^\r\n]+)/i', $r['message'], $m)) $ageDisplay = $m[1];
                    if ($ageDisplay) echo 'Age: ' . e($ageDisplay) . ' • ';
                    echo $esc(mb_strimwidth(strip_tags($r['message'] ?? ''), 0, 120, '...'));
                ?></div>
              </td>
              <td>
                <div class="small text-muted"><?php echo $esc($r['school_name'] ?? ''); ?></div>
                <form method="post" class="d-flex gap-1 align-items-center mt-1">
                  <input type="hidden" name="action" value="assign">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <select name="assigned_to" class="form-select form-select-sm">
                    <option value="">Unassigned</option>
                    <?php foreach ($staffList as $s): ?>
                      <option value="<?php echo (int)$s['id']; ?>" <?php if((int)($r['assigned_to'] ?? 0) === (int)$s['id']) echo 'selected'; ?>><?php echo $esc($s['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-primary ms-1" type="submit">Save</button>
                </form>
                <?php if (!empty($r['assignee_name'])): ?><div class="small text-muted mt-1">Current: <?php echo $esc($r['assignee_name']); ?></div><?php endif; ?>
              </td>
              <td>
                <?php $st = $r['status'] ?? 'new'; ?>
                <span class="badge <?php echo ($st==='new' ? 'bg-primary' : ($st==='in_progress' ? 'bg-warning text-dark' : 'bg-secondary')); ?>"><?php echo $esc(ucfirst(str_replace('_',' ',$st))); ?></span>
                <div class="mt-1">
                  <?php foreach ($uiStatusOptions as $opt): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="?action=status&id=<?php echo (int)$r['id']; ?>&to=<?php echo $esc($opt); ?>"><?php echo $esc(ucfirst(str_replace('_',' ',$opt))); ?></a>
                  <?php endforeach; ?>
                </div>
              </td>
              <td><?php echo $esc($r['source'] ?? ''); ?></td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$r['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editEnquiryModal" data-id="<?php echo (int)$r['id']; ?>">Edit</button>
                <?php echo render_secure_delete_button((int)$r['id'], 'Delete', 'Delete enquiry?'); ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-center text-muted">No enquiries found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($enquiries)); ?> of <?php echo $total; ?></div>
      <nav>
        <ul class="pagination mb-0">
          <?php for ($p = 1; $p <= max(1, $totalPages); $p++): ?>
            <li class="page-item <?php if ($p === $page) echo 'active'; ?>"><a class="page-link" href="?<?php echo build_qs(['page'=>$p]); ?>"><?php echo $p; ?></a></li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>

  </div>
</div>

<!-- Add Enquiry Modal -->
<div class="modal fade" id="addEnquiryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=add">
        <div class="modal-header">
          <h5 class="modal-title">Add Enquiry</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Parent / Guardian Name *</label><input name="name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Phone *</label><input name="phone" class="form-control" required></div>

            <div class="col-md-6">
              <label class="form-label">Child Age group</label>
              <select name="age_group" class="form-select">
                <option value="">Select child age</option>
                <?php foreach ($age_options as $val => $label): ?>
                  <option value="<?php echo $esc($val); ?>"><?php echo $esc($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <?php if (!empty($schoolList)): ?>
            <div class="col-md-6">
              <label class="form-label">School</label>
              <select name="school_id" class="form-select">
                <option value="">Select (optional)</option>
                <?php foreach ($schoolList as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>"><?php echo $esc($s['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>

            <div class="col-md-6">
              <label class="form-label">Assign to (optional)</label>
              <select name="assigned_to" class="form-select">
                <option value="">Unassigned</option>
                <?php foreach ($staffList as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>"><?php echo $esc($s['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12"><label class="form-label">Source</label><input name="source" class="form-control" value="owner_added"></div>
            <div class="col-12"><label class="form-label">Message</label><textarea name="message" rows="4" class="form-control"></textarea></div>
            <div class="col-12"><div class="small text-muted">Fields marked * are required.</div></div>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-success" type="submit">Add Enquiry</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Enquiry Modal -->
<div class="modal fade" id="editEnquiryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editEnquiryForm">
        <div class="modal-header">
          <h5 class="modal-title">Edit Enquiry</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="edit_id">
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Parent / Guardian Name *</label><input name="name" id="edit_name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Phone *</label><input name="phone" id="edit_phone" class="form-control" required></div>

            <div class="col-md-6">
              <label class="form-label">Child Age group</label>
              <select name="age_group" id="edit_age_group" class="form-select">
                <option value="">Select child age</option>
                <?php foreach ($age_options as $val => $label): ?>
                  <option value="<?php echo $esc($val); ?>"><?php echo $esc($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <?php if (!empty($schoolList)): ?>
            <div class="col-md-6">
              <label class="form-label">School</label>
              <select name="school_id" id="edit_school_id" class="form-select">
                <option value="">Select (optional)</option>
                <?php foreach ($schoolList as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>"><?php echo $esc($s['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>

            <div class="col-md-6">
              <label class="form-label">Assign to (optional)</label>
              <select name="assigned_to" id="edit_assigned_to" class="form-select">
                <option value="">Unassigned</option>
                <?php foreach ($staffList as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>"><?php echo $esc($s['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12"><label class="form-label">Source</label><input name="source" id="edit_source" class="form-control"></div>
            <div class="col-12"><label class="form-label">Message</label><textarea name="message" id="edit_message" rows="4" class="form-control"></textarea></div>

            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" id="edit_status" class="form-select">
                <?php foreach ($uiStatusOptions as $opt): ?>
                  <option value="<?php echo $esc($opt); ?>"><?php echo $esc(ucfirst(str_replace('_',' ',$opt))); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12"><div class="small text-muted">Fields marked * are required.</div></div>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- View modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Enquiry details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // View modal: load HTML fragment
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewModalBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.text() : Promise.reject(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load details.</div>'; });
    });
  }

  // Edit modal: fetch JSON and populate fields
  var editModal = document.getElementById('editEnquiryModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      // clear fields
      ['edit_id','edit_name','edit_phone','edit_message','edit_source','edit_status','edit_assigned_to','edit_school_id','edit_age_group'].forEach(function(idn){
        var el = document.getElementById(idn); if (el) el.value = '';
      });
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.json() : Promise.reject(); })
        .then(function(json){
          if (json && json.ok && json.data) {
            var d = json.data;
            document.getElementById('edit_id').value = d.id || '';
            document.getElementById('edit_name').value = d.name || '';
            document.getElementById('edit_phone').value = d.phone || '';
            document.getElementById('edit_message').value = d.message || '';
            document.getElementById('edit_source').value = d.source || '';
            document.getElementById('edit_status').value = d.status || '';
            document.getElementById('edit_assigned_to').value = d.assigned_to || '';
            document.getElementById('edit_school_id').value = d.school_id || '';
            document.getElementById('edit_age_group').value = d.age_group || '';
          } else {
            alert(json.error || 'Failed to load enquiry for edit.');
            var mdl = bootstrap.Modal.getInstance(editModal);
            if (mdl) mdl.hide();
          }
        })
        .catch(function(){
          alert('Failed to load enquiry for edit.');
          var mdl = bootstrap.Modal.getInstance(editModal);
          if (mdl) mdl.hide();
        });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../footer.php';
?>