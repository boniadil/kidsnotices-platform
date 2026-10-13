<?php

declare(strict_types=1);

namespace KidsNotices\AI;

/**
 * GET /dashboard — usage and estimated cost per client / school / feature, for the service owner.
 * Protected by HTTP Basic auth: user "admin", password hash in KN_AI_ADMIN_PASSWORD_HASH
 * (generate it with: php bin/kn-admin.php admin:password).
 */
final class Dashboard
{
    public static function render(): void
    {
        $hash = Config::get('KN_AI_ADMIN_PASSWORD_HASH', '');
        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
        // PHP-FPM / CGI behind Apache: credentials only arrive in the Authorization header.
        if ($user === '' && preg_match('/^Basic\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
            [$user, $pass] = array_pad(explode(':', (string) base64_decode($m[1]), 2), 2, '');
        }
        if ($hash === '' || $user !== 'admin' || !password_verify($pass, $hash)) {
            header('WWW-Authenticate: Basic realm="KidsNotices AI"');
            http_response_code(401);
            echo $hash === '' ? 'Dashboard disabled: set KN_AI_ADMIN_PASSWORD_HASH.' : 'Authentication required.';
            return;
        }

        $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : gmdate('Y-m');
        $rows = Usage::report($month);
        $clients = Clients::all();
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $byClient = [];
        foreach ($rows as $r) {
            $byClient[$r['client_id']]['requests'] = ($byClient[$r['client_id']]['requests'] ?? 0) + $r['requests'];
            $byClient[$r['client_id']]['cost'] = ($byClient[$r['client_id']]['cost'] ?? 0) + $r['cost_usd'];
        }
        $total = array_sum(array_column($rows, 'cost_usd'));

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KidsNotices AI — Consommation</title>
<style>
  :root { --bg: #f6f7f9; --card: #fff; --text: #1f2937; --muted: #6b7280; --border: #e5e7eb; --accent: #1a4fd6; }
  @media (prefers-color-scheme: dark) { :root { --bg: #16181c; --card: #1f2227; --text: #e5e7eb; --muted: #9ca3af; --border: #33373e; --accent: #8ab4ff; } }
  body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, "Segoe UI", Tahoma, sans-serif; }
  main { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
  h1 { font-size: 22px; margin: 0 0 4px; } h2 { font-size: 17px; margin: 28px 0 10px; }
  .muted { color: var(--muted); }
  .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-top: 16px; }
  .tile { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; }
  .tile b { display: block; font-size: 24px; }
  .table-wrap { overflow-x: auto; background: var(--card); border: 1px solid var(--border); border-radius: 12px; }
  table { border-collapse: collapse; width: 100%; font-variant-numeric: tabular-nums; }
  th, td { padding: 8px 12px; border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; }
  th { font-size: 13px; color: var(--muted); font-weight: 600; }
  td.num, th.num { text-align: right; }
  form { margin-top: 12px; } input { font: inherit; padding: 4px 8px; }
  .off { color: #d14343; }
</style>
</head>
<body>
<main>
  <h1>KidsNotices AI — consommation</h1>
  <div class="muted">Coûts estimés à partir des prix publics d'Anthropic (src/Pricing.php).</div>
  <form><label>Mois <input type="month" name="month" value="<?= $e($month) ?>"></label> <button>Afficher</button></form>

  <div class="tiles">
    <div class="tile"><span class="muted">Requêtes</span><b><?= number_format(array_sum(array_column($rows, 'requests'))) ?></b></div>
    <div class="tile"><span class="muted">Coût estimé</span><b>$<?= number_format($total, 2) ?></b></div>
    <div class="tile"><span class="muted">Clients actifs</span><b><?= count(array_filter($clients, fn ($c) => (int) $c['active'])) ?></b></div>
  </div>

  <h2>Clients</h2>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>Client</th><th>Clé</th><th>Écoles</th><th class="num">Requêtes</th><th class="num">Quota</th><th class="num">Coût</th><th class="num">Budget</th><th>État</th></tr>
    <?php foreach ($clients as $c): $u = $byClient[$c['id']] ?? ['requests' => 0, 'cost' => 0]; ?>
    <tr>
      <td><?= (int) $c['id'] ?></td><td><?= $e($c['name']) ?></td><td><code><?= $e($c['key_prefix']) ?>…</code></td>
      <td><?= $e(implode(', ', $c['schools'])) ?></td>
      <td class="num"><?= number_format($u['requests']) ?></td>
      <td class="num"><?= (int) $c['monthly_request_limit'] ?: '∞' ?></td>
      <td class="num">$<?= number_format($u['cost'], 2) ?></td>
      <td class="num"><?= (float) $c['monthly_budget_usd'] > 0 ? '$' . number_format((float) $c['monthly_budget_usd'], 2) : '∞' ?></td>
      <td><?= (int) $c['active'] ? 'actif' : '<span class="off">révoqué</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>

  <h2>Détail par école et fonctionnalité</h2>
  <div class="table-wrap"><table>
    <tr><th>Client</th><th>École</th><th>Fonctionnalité</th><th class="num">Requêtes</th><th class="num">Tokens entrée</th><th class="num">Tokens sortie</th><th class="num">Cache lu</th><th class="num">Coût</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= $e($r['client_name']) ?></td><td><?= $e($r['school_id']) ?></td><td><?= $e($r['feature']) ?></td>
      <td class="num"><?= number_format($r['requests']) ?></td><td class="num"><?= number_format($r['input_tokens']) ?></td>
      <td class="num"><?= number_format($r['output_tokens']) ?></td><td class="num"><?= number_format($r['cache_read_tokens']) ?></td>
      <td class="num">$<?= number_format($r['cost_usd'], 4) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">Aucune requête ce mois-ci.</td></tr><?php endif; ?>
  </table></div>
</main>
</body>
</html>
        <?php
    }
}
