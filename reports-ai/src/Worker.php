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
        $script = Config::root() . '/bin/worker.php';
        if (PHP_OS_FAMILY === 'Windows') {
            // Apache on Windows (XAMPP, WAMP): "start /B" detaches the worker so the page does not wait.
            if (function_exists('popen') && !in_array('popen', $disabled, true)) {
                pclose(popen('start "" /B ' . escapeshellarg(self::php()) . ' ' . escapeshellarg($script) . ' > NUL 2>&1', 'r'));
            }
            return;
        }
        if (function_exists('exec') && !in_array('exec', $disabled, true)) {
            exec(escapeshellarg(self::php()) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
        }
    }

    /** PHP command line: RAI_PHP_CLI, else the php next to the running PHP (C:\xampp\php\php.exe…), else "php". */
    private static function php(): string
    {
        $cli = PHP_BINDIR . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        return Config::get('RAI_PHP_CLI') ?? (@is_file($cli) ? $cli : 'php');
    }
}
