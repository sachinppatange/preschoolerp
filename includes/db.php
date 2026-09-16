<?php
/**
 * includes/db.php
 * PDO-based database helper for Pioneer Play School (Non-MVC)
 *
 * Usage:
 *   require_once __DIR__ . '/config.php';
 *   require_once __DIR__ . '/db.php';
 *
 * Note: This file expects includes/config.php to define DB_* constants.
 */

if (!defined('BASE_PATH')) {
    // ensure config is loaded; if not, try to include it
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    } else {
        throw new RuntimeException('Missing includes/config.php - please require it before db.php or ensure config.php exists.');
    }
}

/* Ensure logs directory exists */
if (!empty(LOGS_PATH) && !is_dir(LOGS_PATH)) {
    @mkdir(LOGS_PATH, 0755, true);
}

/* Internal holder for PDO instance (global) */
$__pps_pdo_instance = null;

/**
 * getPDO
 * Return a singleton PDO instance configured from includes/config.php
 *
 * @return PDO
 * @throws PDOException
 */
function getPDO()
{
    global $__pps_pdo_instance;

    if ($__pps_pdo_instance instanceof PDO) {
        return $__pps_pdo_instance;
    }

    $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
    $port = defined('DB_PORT') ? DB_PORT : '3306';
    $db   = defined('DB_NAME') ? DB_NAME : 'demopredb1';
    $user = defined('DB_USER') ? DB_USER : 'root';
    $pass = defined('DB_PASS') ? DB_PASS : '';
    $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // use native prepared statements when possible
    ];

    // Optionally enable persistent connections if configured via env()
    if (function_exists('env') && env('DB_PERSISTENT', false)) {
        $options[PDO::ATTR_PERSISTENT] = true;
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        $__pps_pdo_instance = $pdo;
        return $__pps_pdo_instance;
    } catch (PDOException $e) {
        // log error to file if possible then rethrow (do not expose details to end user)
        $msg = '[' . date('Y-m-d H:i:s') . '] PDO connection error: ' . $e->getMessage() . PHP_EOL;
        if (!empty(LOGS_PATH)) {
            @file_put_contents(rtrim(LOGS_PATH, '/\\') . '/db_errors.log', $msg, FILE_APPEND | LOCK_EX);
        }
        throw $e;
    }
}

/**
 * db_prepare_execute
 * Prepare a SQL statement, execute with params and return PDOStatement
 *
 * @param string $sql
 * @param array $params
 * @return PDOStatement
 * @throws Exception
 */
function db_prepare_execute($sql, $params = [])
{
    $pdo = getPDO();
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute((array)$params);
    if ($ok) {
        return $stmt;
    }
    $errorInfo = $stmt->errorInfo();
    throw new Exception('DB execute error: ' . json_encode($errorInfo));
}

/**
 * db_fetch_one
 * Fetch single row (associative) or null if not found
 *
 * @param string $sql
 * @param array $params
 * @return array|null
 */
function db_fetch_one($sql, $params = [])
{
    $stmt = db_prepare_execute($sql, $params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * db_fetch_all
 * Fetch all rows as array of associative arrays
 *
 * @param string $sql
 * @param array $params
 * @return array
 */
function db_fetch_all($sql, $params = [])
{
    $stmt = db_prepare_execute($sql, $params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * db_execute
 * Execute a non-select query (INSERT/UPDATE/DELETE). Returns number of affected rows.
 *
 * @param string $sql
 * @param array $params
 * @return int affected rows
 */
function db_execute($sql, $params = [])
{
    $stmt = db_prepare_execute($sql, $params);
    return $stmt->rowCount();
}

/**
 * db_last_insert_id
 * Return last insert id for current PDO connection
 *
 * @return string
 */
function db_last_insert_id()
{
    $pdo = getPDO();
    return $pdo->lastInsertId();
}

/**
 * db_begin / db_commit / db_rollback
 * Transaction helpers
 */
function db_begin()
{
    $pdo = getPDO();
    return $pdo->beginTransaction();
}

function db_commit()
{
    $pdo = getPDO();
    return $pdo->commit();
}

function db_rollback()
{
    $pdo = getPDO();
    return $pdo->rollBack();
}

/**
 * db_transaction
 * Execute a callable inside a transaction. On exception, rollback and rethrow.
 *
 * Example:
 *   db_transaction(function($pdo) use ($data) {
 *       db_execute("INSERT ...", [...]);
 *       db_execute("UPDATE ...", [...]);
 *   });
 *
 * @param callable $callable  function(PDO $pdo) : mixed
 * @return mixed  returns callable's return value
 * @throws Exception
 */
function db_transaction(callable $callable)
{
    $pdo = getPDO();
    $started = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        $result = $callable($pdo);
        if ($started) {
            $pdo->commit();
        }
        return $result;
    } catch (Exception $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // log error
        $msg = '[' . date('Y-m-d H:i:s') . '] DB transaction error: ' . $e->getMessage() . PHP_EOL;
        if (!empty(LOGS_PATH)) {
            @file_put_contents(rtrim(LOGS_PATH, '/\\') . '/db_errors.log', $msg, FILE_APPEND | LOCK_EX);
        }
        throw $e;
    }
}

/**
 * db_log_query (optional)
 * Small helper to log slow or important queries (development)
 *
 * @param string $sql
 * @param array $params
 * @param float|null $duration seconds
 * @return void
 */
function db_log_query($sql, $params = [], $duration = null)
{
    if (defined('APP_ENV') && APP_ENV !== 'development') {
        return;
    }
    $logLine = '[' . date('Y-m-d H:i:s') . '] SQL: ' . $sql . ' | params: ' . json_encode($params);
    if ($duration !== null) {
        $logLine .= ' | duration: ' . round($duration, 4) . 's';
    }
    $logLine .= PHP_EOL;
    if (!empty(LOGS_PATH)) {
        @file_put_contents(rtrim(LOGS_PATH, '/\\') . '/db_queries.log', $logLine, FILE_APPEND | LOCK_EX);
    }
}

/* End of includes/db.php */