<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Authentifizierung & Benutzerverwaltung fuer Pokelog.
 *
 * - Sessions ueber ein HttpOnly-Cookie (kein Token-Handling im Frontend noetig).
 * - Passwoerter werden mit password_hash() (bcrypt) gespeichert.
 * - Rollen: 'admin' (darf Benutzer verwalten + Wartung) und 'user'.
 * - Bootstrap: Solange keine Benutzer existieren, kann ueber auth.setup ein
 *   erster Admin angelegt werden. Diesem werden bestehende Alt-Sammlungs-
 *   eintraege (user_id = NULL) zugeordnet.
 */
final class Auth
{
    public const ROLES = ['admin', 'user'];

    private static bool $started = false;

    /** Startet die Session (idempotent). Muss vor jeder Ausgabe erfolgen. */
    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            // 'secure' bewusst nicht erzwungen, damit lokal ueber http://localhost
            // gearbeitet werden kann. Hinter HTTPS setzt der Browser es selbst.
        ]);
        session_name('pokelog_session');
        session_start();
        self::$started = true;
    }

    private static function db(): PDO
    {
        return Database::pdo();
    }

    // ---------------------------------------------------------- Sitzung

    /** Aktuell eingeloggter Benutzer (oder null). */
    public static function currentUser(): ?array
    {
        self::start();
        $id = $_SESSION['uid'] ?? null;
        if ($id === null) {
            return null;
        }
        $stmt = self::db()->prepare('SELECT id, username, role, is_active, created_at, last_login FROM users WHERE id = ?');
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();
        if ($row === false || (int) $row['is_active'] !== 1) {
            // Konto deaktiviert/geloescht -> Session verwerfen.
            self::logout();
            return null;
        }
        return self::publicUser($row);
    }

    public static function userId(): ?int
    {
        $u = self::currentUser();
        return $u === null ? null : (int) $u['id'];
    }

    public static function isAdmin(): bool
    {
        $u = self::currentUser();
        return $u !== null && $u['role'] === 'admin';
    }

    /** Anzahl vorhandener Benutzer (fuer Bootstrap-Erkennung). */
    public static function countUsers(): int
    {
        return (int) self::db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /** Pruefung, ob noch kein Benutzer existiert (Erst-Einrichtung). */
    public static function needsSetup(): bool
    {
        return self::countUsers() === 0;
    }

    // ---------------------------------------------------------- Login

    /**
     * Meldet einen Benutzer an. Gibt das Benutzerobjekt zurueck oder wirft.
     *
     * @return array<string,mixed>
     */
    public static function login(string $username, string $password): array
    {
        self::start();
        $username = trim($username);
        $stmt = self::db()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if ($row === false || !password_verify($password, (string) $row['password_hash'])) {
            throw new RuntimeException('Benutzername oder Passwort ist falsch.');
        }
        if ((int) $row['is_active'] !== 1) {
            throw new RuntimeException('Dieses Konto ist deaktiviert.');
        }

        // Session-Fixation vermeiden.
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $row['id'];

        $upd = self::db()->prepare('UPDATE users SET last_login = ? WHERE id = ?');
        $upd->execute([time(), (int) $row['id']]);

        return self::publicUser($row);
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // ---------------------------------------------------------- Schutz

    /** Erzwingt einen eingeloggten Benutzer. */
    public static function requireAuth(): array
    {
        $u = self::currentUser();
        if ($u === null) {
            throw new AuthException('Nicht angemeldet.', 401);
        }
        return $u;
    }

    /** Erzwingt einen Admin-Benutzer. */
    public static function requireAdmin(): array
    {
        $u = self::requireAuth();
        if ($u['role'] !== 'admin') {
            throw new AuthException('Keine Berechtigung.', 403);
        }
        return $u;
    }

    // ---------------------------------------------------- Benutzerverwaltung

    /**
     * Legt einen Benutzer an. Der allererste Benutzer wird automatisch Admin
     * und erbt bestehende Alt-Sammlungseintraege (user_id = NULL).
     *
     * @return array<string,mixed>
     */
    public static function createUser(string $username, string $password, string $role = 'user'): array
    {
        $username = trim($username);
        if ($username === '' || !preg_match('/^[\p{L}\p{N}_.\-]{3,32}$/u', $username)) {
            throw new RuntimeException('Benutzername: 3–32 Zeichen (Buchstaben, Zahlen, _ . -).');
        }
        if (mb_strlen($password) < 6) {
            throw new RuntimeException('Das Passwort muss mindestens 6 Zeichen lang sein.');
        }

        $isFirst = self::countUsers() === 0;
        $role = $isFirst ? 'admin' : (in_array($role, self::ROLES, true) ? $role : 'user');

        $exists = self::db()->prepare('SELECT 1 FROM users WHERE username = ?');
        $exists->execute([$username]);
        if ($exists->fetchColumn() !== false) {
            throw new RuntimeException('Dieser Benutzername ist bereits vergeben.');
        }

        $stmt = self::db()->prepare(<<<'SQL'
            INSERT INTO users (username, password_hash, role, is_active, created_at)
            VALUES (:u, :h, :r, 1, :now)
        SQL);
        $stmt->execute([
            ':u'   => $username,
            ':h'   => password_hash($password, PASSWORD_DEFAULT),
            ':r'   => $role,
            ':now' => time(),
        ]);
        $id = (int) self::db()->lastInsertId();

        // Erster Admin erbt eine evtl. vorhandene Alt-Sammlung.
        if ($isFirst) {
            self::db()->prepare('UPDATE collection_items SET user_id = ? WHERE user_id IS NULL')
                ->execute([$id]);
        }

        return self::getUser($id);
    }

    /**
     * Aktualisiert ein Benutzerkonto (Passwort, Rolle, Aktiv-Status).
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public static function updateUser(int $id, array $fields): array
    {
        $user = self::getUser($id);

        $set = [];
        $params = [':id' => $id];

        if (isset($fields['password']) && $fields['password'] !== '') {
            if (mb_strlen((string) $fields['password']) < 6) {
                throw new RuntimeException('Das Passwort muss mindestens 6 Zeichen lang sein.');
            }
            $set[] = 'password_hash = :h';
            $params[':h'] = password_hash((string) $fields['password'], PASSWORD_DEFAULT);
        }

        if (isset($fields['role']) && in_array($fields['role'], self::ROLES, true)) {
            // Letzten Admin nicht degradieren.
            if ($user['role'] === 'admin' && $fields['role'] !== 'admin' && self::countAdmins() <= 1) {
                throw new RuntimeException('Der letzte Admin kann nicht herabgestuft werden.');
            }
            $set[] = 'role = :r';
            $params[':r'] = $fields['role'];
        }

        if (array_key_exists('isActive', $fields)) {
            $active = !empty($fields['isActive']) ? 1 : 0;
            if ($active === 0 && $user['role'] === 'admin' && self::countAdmins() <= 1) {
                throw new RuntimeException('Der letzte Admin kann nicht deaktiviert werden.');
            }
            $set[] = 'is_active = :a';
            $params[':a'] = $active;
        }

        if ($set === []) {
            return $user;
        }

        $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id';
        self::db()->prepare($sql)->execute($params);

        return self::getUser($id);
    }

    public static function deleteUser(int $id): void
    {
        $user = self::getUser($id);
        if ($user['role'] === 'admin' && self::countAdmins() <= 1) {
            throw new RuntimeException('Der letzte Admin kann nicht gelöscht werden.');
        }
        // Sammlung des Benutzers mitloeschen.
        self::db()->prepare('DELETE FROM collection_items WHERE user_id = ?')->execute([$id]);
        self::db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    /**
     * Alle Benutzer inkl. Anzahl ihrer Sammlungseintraege (fuer das Adminpanel).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listUsers(): array
    {
        $rows = self::db()->query(<<<'SQL'
            SELECT u.id, u.username, u.role, u.is_active, u.created_at, u.last_login,
                   (SELECT COUNT(*) FROM collection_items ci WHERE ci.user_id = u.id) AS items,
                   (SELECT COALESCE(SUM(ci.quantity), 0) FROM collection_items ci WHERE ci.user_id = u.id) AS cards
            FROM users u
            ORDER BY u.created_at ASC
        SQL)->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $u = self::publicUser($r);
            $u['items'] = (int) $r['items'];
            $u['cards'] = (int) $r['cards'];
            $out[] = $u;
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public static function getUser(int $id): array
    {
        $stmt = self::db()->prepare('SELECT id, username, role, is_active, created_at, last_login FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Benutzer nicht gefunden.');
        }
        return self::publicUser($row);
    }

    private static function countAdmins(): int
    {
        return (int) self::db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function publicUser(array $row): array
    {
        return [
            'id'        => (int) $row['id'],
            'username'  => $row['username'],
            'role'      => $row['role'],
            'isActive'  => (int) $row['is_active'] === 1,
            'createdAt' => $row['created_at'] !== null ? (int) $row['created_at'] : null,
            'lastLogin' => $row['last_login'] !== null ? (int) $row['last_login'] : null,
        ];
    }
}

/** Fehler mit HTTP-Status fuer Auth-/Berechtigungsprobleme. */
final class AuthException extends RuntimeException
{
    public function __construct(string $message, int $status = 401)
    {
        parent::__construct($message, $status);
    }
}
