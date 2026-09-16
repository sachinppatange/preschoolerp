<?php
/**
 * includes/db_compat.php
 *
 * Legacy-compatible DB helpers used across panel pages.
 * Delegates to includes/db.php (getPDO, db_fetch_*) when available.
 *
 * Load after includes/config.php and includes/db.php.
 */
declare(strict_types=1);

if (!function_exists('pdo_connect')) {
    /**
     * @return PDO|null
     */
    function pdo_connect()
    {
        foreach (['pdo', 'db', 'dbh', 'DB'] as $g) {
            if (!empty($GLOBALS[$g]) && $GLOBALS[$g] instanceof PDO) {
                $GLOBALS['pdo'] = $GLOBALS[$g];
                return $GLOBALS[$g];
            }
        }

        if (function_exists('getPDO')) {
            try {
                $pdo = getPDO();
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            } catch (Throwable $e) {
                error_log('pdo_connect via getPDO failed: ' . $e->getMessage());
            }
        }

        if (defined('DB_DSN')) {
            try {
                $user = defined('DB_USER') ? constant('DB_USER') : null;
                $pass = defined('DB_PASS') ? constant('DB_PASS') : null;
                $pdo = new PDO(constant('DB_DSN'), $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            } catch (Throwable $e) {
                error_log('pdo_connect DSN failed: ' . $e->getMessage());
            }
        }

        if (defined('DB_HOST') && defined('DB_NAME')) {
            $host = constant('DB_HOST');
            $port = defined('DB_PORT') ? constant('DB_PORT') : 3306;
            $name = constant('DB_NAME');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            try {
                $user = defined('DB_USER') ? constant('DB_USER') : null;
                $pass = defined('DB_PASS') ? constant('DB_PASS') : null;
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            } catch (Throwable $e) {
                error_log('pdo_connect host failed: ' . $e->getMessage());
            }
        }

        return null;
    }
}

if (!function_exists('db_compat_execute_params')) {
    function db_compat_execute_params(PDOStatement $stmt, array $params): bool
    {
        try {
            return $stmt->execute($params);
        } catch (Throwable $e) {
            return $stmt->execute(array_values($params));
        }
    }
}

if (!function_exists('safe_db_get_one')) {
    function safe_db_get_one(string $sql, array $params = [])
    {
        if (function_exists('db_fetch_one')) {
            try {
                return db_fetch_one($sql, $params);
            } catch (Throwable $e) {
                // fall through
            }
        }

        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) {
            return null;
        }

        try {
            $stmt = $pdo->prepare($sql);
            db_compat_execute_params($stmt, $params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        } catch (Throwable $e) {
            if ($GLOBALS['DEBUG'] ?? false) {
                error_log('safe_db_get_one: ' . $e->getMessage() . ' SQL: ' . $sql);
            }
            return null;
        }
    }
}

if (!function_exists('safe_db_get_all')) {
    function safe_db_get_all(string $sql, array $params = []): array
    {
        if (function_exists('db_fetch_all')) {
            try {
                return db_fetch_all($sql, $params) ?: [];
            } catch (Throwable $e) {
                // fall through
            }
        }

        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) {
            return [];
        }

        try {
            $stmt = $pdo->prepare($sql);
            db_compat_execute_params($stmt, $params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            if ($GLOBALS['DEBUG'] ?? false) {
                error_log('safe_db_get_all: ' . $e->getMessage() . ' SQL: ' . $sql);
            }
            return [];
        }
    }
}

if (!function_exists('safe_db_run')) {
    function safe_db_run(string $sql, array $params = []): bool
    {
        if (function_exists('db_execute')) {
            try {
                db_execute($sql, $params);
                return true;
            } catch (Throwable $e) {
                // fall through
            }
        }

        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) {
            return false;
        }

        try {
            $stmt = $pdo->prepare($sql);
            return db_compat_execute_params($stmt, $params);
        } catch (Throwable $e) {
            if ($GLOBALS['DEBUG'] ?? false) {
                error_log('safe_db_run: ' . $e->getMessage() . ' SQL: ' . $sql);
            }
            return false;
        }
    }
}

if (!function_exists('table_exists')) {
    function table_exists(string $name): bool
    {
        try {
            $r = safe_db_get_one(
                "SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t",
                [':t' => $name]
            );
            return !empty($r) && (int) ($r['cnt'] ?? 0) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('column_exists')) {
    function column_exists(string $table, string $column): bool
    {
        return in_array(strtolower($column), get_table_columns($table), true);
    }
}

if (!function_exists('get_table_columns')) {
    function get_table_columns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $rows = safe_db_get_all(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t",
            [':t' => $table]
        );
        $names = [];
        foreach ($rows as $r) {
            $names[] = strtolower((string) ($r['COLUMN_NAME'] ?? ''));
        }
        $cache[$table] = $names;
        return $names;
    }
}
