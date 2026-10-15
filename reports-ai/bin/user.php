<?php

/*
 * Creates a user, or changes their password / role.
 *   php bin/user.php <username> [admin|user]      (asks for the password)
 * Admins can add and delete reports; users can ask questions, see statistics and write reports.
 */

declare(strict_types=1);

use ReportsAI\Auth;
use ReportsAI\Config;

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    fwrite(STDERR, "Usage: php bin/user.php <username> [admin|user]\n");
    exit(1);
}
require dirname(__DIR__) . '/src/autoload.php';
Config::loadEnvFile(dirname(__DIR__) . '/.env');

echo 'Password (min 10 characters): ';
$password = trim((string) fgets(STDIN));
try {
    Auth::createUser($argv[1], $password, $argv[2] ?? 'user');
    echo "User {$argv[1]} saved.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
