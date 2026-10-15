<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Session login (users created with bin/user.php) and CSRF tokens.
 */
final class Auth
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
            session_start();
        }
    }

    public static function user(): ?array
    {
        return $_SESSION['rai_user'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function login(string $username, string $password): bool
    {
        $stmt = Database::pdo()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $u = $stmt->fetch();
        // Same work whether the user exists or not.
        $ok = password_verify($password, $u['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        if (!$u || !$ok) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['rai_user'] = ['id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role']];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function csrf(): string
    {
        return $_SESSION['rai_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function checkCsrf(): void
    {
        $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || !hash_equals(self::csrf(), $token)) {
            throw new UserError('انتهت صلاحية الجلسة. أعد تحميل الصفحة وحاول من جديد.');
        }
    }

    public static function createUser(string $username, string $password, string $role): void
    {
        if (!preg_match('/^[\p{L}\p{N}._-]{3,40}$/u', $username) || strlen($password) < 10 || !in_array($role, ['admin', 'user'], true)) {
            throw new UserError('Username: 3-40 letters/digits; password: at least 10 characters; role: admin or user.');
        }
        Database::pdo()->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)
                                  ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash, role = excluded.role')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, Database::now()]);
    }
}
