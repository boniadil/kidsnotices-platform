<?php

declare(strict_types=1);

namespace ReportsAI;

use PDO;

/**
 * SQLite database (one file in storage/), with FTS5 full-text indexes for report passages
 * and indicator names. Created automatically on first use.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $path = Config::get('RAI_DB_PATH', Config::storageDir() . '/reports-ai.sqlite');
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::migrate($pdo);
        return self::$pdo = $pdo;
    }

    /** For tests: forget the connection (e.g. after changing RAI_DB_PATH). */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    year INTEGER NOT NULL,
    language TEXT NOT NULL DEFAULT '',
    filename TEXT NOT NULL,
    sha256 TEXT NOT NULL UNIQUE,
    pages INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'queued',
    progress INTEGER NOT NULL DEFAULT 0,
    error TEXT NULL,
    outline_json TEXT NULL,
    summary TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS pages (
    report_id INTEGER NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    page_no INTEGER NOT NULL,
    text TEXT NOT NULL,
    PRIMARY KEY (report_id, page_no)
);
CREATE TABLE IF NOT EXISTS chunks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id INTEGER NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    page_no INTEGER NOT NULL,
    text TEXT NOT NULL
);
CREATE VIRTUAL TABLE IF NOT EXISTS chunks_fts USING fts5(norm, chunk_id UNINDEXED, tokenize = 'unicode61 remove_diacritics 2');
CREATE TABLE IF NOT EXISTS indicators (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name_ar TEXT NOT NULL,
    name_fr TEXT NOT NULL,
    unit TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT 'other',
    created_at TEXT NOT NULL
);
CREATE VIRTUAL TABLE IF NOT EXISTS indicators_fts USING fts5(norm, indicator_id UNINDEXED, tokenize = 'unicode61 remove_diacritics 2');
CREATE TABLE IF NOT EXISTS facts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    indicator_id INTEGER NOT NULL REFERENCES indicators(id) ON DELETE CASCADE,
    report_id INTEGER NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    year INTEGER NOT NULL,
    value REAL NOT NULL,
    unit TEXT NOT NULL DEFAULT '',
    scope TEXT NOT NULL DEFAULT '',
    page_no INTEGER NOT NULL,
    quote TEXT NOT NULL DEFAULT '',
    verified INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_facts_indicator ON facts (indicator_id, year);
CREATE TABLE IF NOT EXISTS generated_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    title TEXT NOT NULL,
    language TEXT NOT NULL,
    params_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    markdown TEXT NULL,
    error TEXT NULL,
    created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS usage_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    task TEXT NOT NULL,
    model TEXT NOT NULL,
    input_tokens INTEGER NOT NULL,
    output_tokens INTEGER NOT NULL,
    cache_read_tokens INTEGER NOT NULL,
    cache_write_tokens INTEGER NOT NULL,
    cost_usd REAL NOT NULL
);
SQL);
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
