<?php

declare(strict_types=1);

namespace KidsNotices\AI;

/**
 * API clients (platforms that buy the service). Each has its own key, stored only as a
 * SHA-256 hash, can be revoked, and may only act for the schools linked to it.
 */
final class Clients
{
    /** @return array{id: int, key: string} The plain key is returned once, never stored. */
    public static function create(string $name, int $monthlyRequestLimit = 0, float $monthlyBudgetUsd = 0): array
    {
        $key = 'kn_live_' . bin2hex(random_bytes(24));
        $stmt = Database::pdo()->prepare(
            'INSERT INTO clients (name, key_prefix, key_hash, active, monthly_request_limit, monthly_budget_usd, created_at)
             VALUES (?, ?, ?, 1, ?, ?, ?)'
        );
        $stmt->execute([$name, substr($key, 0, 14), hash('sha256', $key), $monthlyRequestLimit, $monthlyBudgetUsd, gmdate('Y-m-d H:i:s')]);
        return ['id' => (int) Database::pdo()->lastInsertId(), 'key' => $key];
    }

    /** Authenticates "Authorization: Bearer <key>" and checks that the school belongs to the client. */
    public static function authenticate(string $authorizationHeader, string $schoolId): array
    {
        if (!preg_match('/^Bearer\s+(kn_live_[a-f0-9]{48})$/', $authorizationHeader, $m)) {
            throw new HttpError(401, 'unauthorized', 'Missing or invalid API key.');
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM clients WHERE key_hash = ?');
        $stmt->execute([hash('sha256', $m[1])]);
        $client = $stmt->fetch();
        if (!$client || !(int) $client['active']) {
            throw new HttpError(401, 'unauthorized', 'Missing or invalid API key.');
        }

        $stmt = Database::pdo()->prepare('SELECT 1 FROM client_schools WHERE client_id = ? AND school_id = ?');
        $stmt->execute([$client['id'], $schoolId]);
        if (!$stmt->fetchColumn()) {
            throw new HttpError(403, 'school_not_allowed', 'This school is not linked to your API key.');
        }
        return $client;
    }

    public static function revoke(int $clientId): void
    {
        Database::pdo()->prepare('UPDATE clients SET active = 0, revoked_at = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s'), $clientId]);
    }

    public static function addSchool(int $clientId, string $schoolId): void
    {
        self::assertSchoolId($schoolId);
        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT 1 FROM client_schools WHERE client_id = ? AND school_id = ?');
        $exists->execute([$clientId, $schoolId]);
        if (!$exists->fetchColumn()) {
            $pdo->prepare('INSERT INTO client_schools (client_id, school_id) VALUES (?, ?)')->execute([$clientId, $schoolId]);
        }
    }

    public static function removeSchool(int $clientId, string $schoolId): void
    {
        Database::pdo()->prepare('DELETE FROM client_schools WHERE client_id = ? AND school_id = ?')->execute([$clientId, $schoolId]);
    }

    public static function setLimits(int $clientId, int $monthlyRequestLimit, float $monthlyBudgetUsd): void
    {
        Database::pdo()->prepare('UPDATE clients SET monthly_request_limit = ?, monthly_budget_usd = ? WHERE id = ?')
            ->execute([$monthlyRequestLimit, $monthlyBudgetUsd, $clientId]);
    }

    public static function all(): array
    {
        $clients = Database::pdo()->query('SELECT id, name, key_prefix, active, monthly_request_limit, monthly_budget_usd, created_at, revoked_at FROM clients ORDER BY id')->fetchAll();
        $schools = Database::pdo()->query('SELECT client_id, school_id FROM client_schools ORDER BY school_id')->fetchAll();
        foreach ($clients as &$c) {
            $c['schools'] = array_values(array_map(fn ($s) => $s['school_id'], array_filter($schools, fn ($s) => (int) $s['client_id'] === (int) $c['id'])));
        }
        return $clients;
    }

    public static function assertSchoolId(string $schoolId): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $schoolId)) {
            throw new HttpError(400, 'invalid_school', 'School id must be 1-64 letters, digits, - or _.');
        }
    }
}
