<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Background jobs: report ingestion and report writing (minutes each).
 * Run by bin/worker.php — from cron every minute, or started right after an action.
 */
final class Worker
{
    /** Processes one pending job. Returns false when there is nothing to do. */
    public static function runOnce(): bool
    {
        $pdo = Database::pdo();
        $id = $pdo->query("SELECT id FROM reports WHERE status = 'queued' ORDER BY id LIMIT 1")->fetchColumn();
        if ($id) {
            try {
                Ingest::process((int) $id);
            } catch (\Throwable) {
                // status and error already stored on the report
            }
            return true;
        }
        $id = $pdo->query("SELECT id FROM generated_reports WHERE status = 'queued' ORDER BY id LIMIT 1")->fetchColumn();
        if ($id) {
            try {
                ReportWriter::process((int) $id);
            } catch (\Throwable) {
            }
            return true;
        }
        return false;
    }

    /** Starts the worker in the background if the host allows it (otherwise cron picks the job up). */
    public static function kick(): void
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            return;
        }
        $php = Config::get('RAI_PHP_CLI', 'php');
        $script = Config::root() . '/bin/worker.php';
        exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
    }
}
