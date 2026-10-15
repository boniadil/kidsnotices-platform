<?php

/*
 * Processes pending jobs (report ingestion, report writing).
 *   php bin/worker.php          run until no job is left (use from cron every minute)
 *   php bin/worker.php --loop   keep running and poll every 10 seconds
 *   php bin/worker.php --ingest <file.pdf> --year <YYYY> [--title "..."]   add a report from the command line
 */

declare(strict_types=1);

use ReportsAI\Config;
use ReportsAI\Ingest;
use ReportsAI\Worker;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/vendor/autoload.php';
Config::loadEnvFile(dirname(__DIR__) . '/.env');
set_time_limit(0);

$opts = getopt('', ['loop', 'ingest:', 'year:', 'title:']);
if (isset($opts['ingest'])) {
    $tmp = tempnam(sys_get_temp_dir(), 'rai');
    copy($opts['ingest'], $tmp);
    $id = Ingest::register($tmp, basename($opts['ingest']), (int) ($opts['year'] ?? 0), (string) ($opts['title'] ?? ''));
    echo "Report #$id queued.\n";
}

// One worker at a time.
$lock = fopen(Config::storageDir() . '/worker.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
do {
    while (Worker::runOnce()) {
        echo date('H:i:s') . " job done\n";
    }
    if (isset($opts['loop'])) {
        sleep(10);
    }
} while (isset($opts['loop']));
