<?php

declare(strict_types=1);

namespace KidsNotices\AI;

use PDO;

/**
 * PDO connection + schema. SQLite by default (no server needed), MySQL/MariaDB via KN_AI_DB_DSN.
 *
 *   KN_AI_DB_DSN=sqlite:/path/to/ai-service.sqlite            (default: storage/ai-service.sqlite)
 *   KN_AI_DB_DSN=mysql:host=localhost;dbname=kn_ai;charset=utf8mb4
 *   KN_AI_DB_USER / KN_AI_DB_PASSWORD
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $dsn = Config::get('KN_AI_DB_DSN', 'sqlite:' . Config::storageDir() . '/ai-service.sqlite');
        $pdo = new PDO($dsn, Config::get('KN_AI_DB_USER'), Config::get('KN_AI_DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
        self::migrate($pdo);
        return self::$pdo = $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $mysql ? 'VARCHAR(190)' : 'TEXT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $pdo->exec("CREATE TABLE IF NOT EXISTS clients (
            id $id,
            name $text NOT NULL,
            key_prefix $text NOT NULL,
            key_hash CHAR(64) NOT NULL UNIQUE,
            active INT NOT NULL DEFAULT 1,
            monthly_request_limit INT NOT NULL DEFAULT 0,
            monthly_budget_usd DECIMAL(12,4) NOT NULL DEFAULT 0,
            created_at $text NOT NULL,
            revoked_at $text NULL
        )$suffix");

        $pdo->exec("CREATE TABLE IF NOT EXISTS client_schools (
            client_id INT NOT NULL,
            school_id $text NOT NULL,
            PRIMARY KEY (client_id, school_id)
        )$suffix");

        $pdo->exec("CREATE TABLE IF NOT EXISTS usage_log (
            id $id,
            created_at $text NOT NULL,
            day CHAR(10) NOT NULL,
            month CHAR(7) NOT NULL,
            client_id INT NOT NULL,
            school_id $text NOT NULL,
            feature $text NOT NULL,
            end_user CHAR(64) NULL,
            model $text NOT NULL,
            stop_reason $text NULL,
            input_tokens INT NOT NULL DEFAULT 0,
            output_tokens INT NOT NULL DEFAULT 0,
            cache_read_tokens INT NOT NULL DEFAULT 0,
            cache_write_tokens INT NOT NULL DEFAULT 0,
            cost_usd DECIMAL(12,6) NOT NULL DEFAULT 0
        )$suffix");

        foreach ([
            'idx_usage_client_month' => '(client_id, month)',
            'idx_usage_school_feature_month' => '(school_id, feature, month)',
            'idx_usage_user_day' => '(end_user, day)',
        ] as $name => $columns) {
            try {
                $pdo->exec($mysql
                    ? "CREATE INDEX $name ON usage_log $columns"
                    : "CREATE INDEX IF NOT EXISTS $name ON usage_log $columns");
            } catch (\PDOException) {
                // MySQL has no "IF NOT EXISTS" for indexes: already created.
            }
        }
    }
}
