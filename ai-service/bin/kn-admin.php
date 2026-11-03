<?php

declare(strict_types=1);

/*
 * Administration of the AI service (command line only).
 *
 *   php bin/kn-admin.php client:create "Plateforme Jusour" [--requests=10000] [--budget=50]
 *   php bin/kn-admin.php client:list
 *   php bin/kn-admin.php client:limits <client_id> --requests=N --budget=USD
 *   php bin/kn-admin.php client:revoke <client_id>
 *   php bin/kn-admin.php school:add <client_id> <school_id>
 *   php bin/kn-admin.php school:remove <client_id> <school_id>
 *   php bin/kn-admin.php usage [YYYY-MM]
 *   php bin/kn-admin.php admin:password            (prints the hash for KN_AI_ADMIN_PASSWORD_HASH)
 */

use KidsNotices\AI\Clients;
use KidsNotices\AI\Config;
use KidsNotices\AI\Usage;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/autoload.php';
Config::loadEnvFile(dirname(__DIR__) . '/.env');

$args = array_slice($argv, 1);
$options = [];
foreach ($args as $i => $arg) {
    if (preg_match('/^--(\w+)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
        unset($args[$i]);
    }
}
$args = array_values($args);
$command = $args[0] ?? 'help';

function need(array $args, int $index, string $what): string
{
    if (!isset($args[$index]) || $args[$index] === '') {
        fwrite(STDERR, "Missing $what.\n");
        exit(1);
    }
    return $args[$index];
}

try {
    switch ($command) {
        case 'client:create':
            $created = Clients::create(need($args, 1, 'client name'), (int) ($options['requests'] ?? 0), (float) ($options['budget'] ?? 0));
            echo "Client #{$created['id']} created.\n";
            echo "API key (shown only once, store it in the client platform):\n{$created['key']}\n";
            echo "Now link its schools: php bin/kn-admin.php school:add {$created['id']} <school_id>\n";
            break;

        case 'client:list':
            foreach (Clients::all() as $c) {
                printf("#%d  %-30s %s…  %s  requests/month: %s  budget: %s  schools: %s\n",
                    $c['id'], $c['name'], $c['key_prefix'], (int) $c['active'] ? 'active ' : 'REVOKED',
                    (int) $c['monthly_request_limit'] ?: '∞', (float) $c['monthly_budget_usd'] > 0 ? '$' . $c['monthly_budget_usd'] : '∞',
                    implode(', ', $c['schools']) ?: '-');
            }
            break;

        case 'client:limits':
            Clients::setLimits((int) need($args, 1, 'client id'), (int) ($options['requests'] ?? 0), (float) ($options['budget'] ?? 0));
            echo "Limits updated.\n";
            break;

        case 'client:revoke':
            Clients::revoke((int) need($args, 1, 'client id'));
            echo "Key revoked.\n";
            break;

        case 'school:add':
            Clients::addSchool((int) need($args, 1, 'client id'), need($args, 2, 'school id'));
            echo "School linked.\n";
            break;

        case 'school:remove':
            Clients::removeSchool((int) need($args, 1, 'client id'), need($args, 2, 'school id'));
            echo "School unlinked.\n";
            break;

        case 'usage':
            $month = $args[1] ?? gmdate('Y-m');
            $total = 0;
            foreach (Usage::report($month) as $r) {
                printf("%-25s %-20s %-12s %6d req  in %8d  out %7d  $%.4f\n", $r['client_name'], $r['school_id'], $r['feature'], $r['requests'], $r['input_tokens'], $r['output_tokens'], $r['cost_usd']);
                $total += $r['cost_usd'];
            }
            printf("Total %s: $%.4f\n", $month, $total);
            break;

        case 'admin:password':
            echo 'Password for the dashboard: ';
            $password = trim((string) fgets(STDIN));
            if (strlen($password) < 10) {
                fwrite(STDERR, "Use at least 10 characters.\n");
                exit(1);
            }
            echo "Add to .env:\nKN_AI_ADMIN_PASSWORD_HASH='" . password_hash($password, PASSWORD_DEFAULT) . "'\n";
            break;

        default:
            echo file_get_contents(__FILE__, length: 900);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
