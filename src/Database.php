<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * Duenne Wrapper-Klasse um eine PDO-SQLite-Verbindung inklusive
 * automatischer Schema-Erstellung und Migration.
 */
final class Database
{
    /** Aktuelle Schema-Version (PRAGMA user_version). */
    private const SCHEMA_VERSION = 5;

    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        if (!is_dir(Config::DATA_DIR)) {
            mkdir(Config::DATA_DIR, 0775, true);
        }

        $pdo = new PDO('sqlite:' . Config::DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL;');

        self::$pdo = $pdo;
        self::migrate($pdo);
        $pdo->exec('PRAGMA foreign_keys = ON;');

        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        // Waehrend der Migration FK-Pruefung aus, um Tabellen gefahrlos
        // neu erstellen zu koennen.
        $pdo->exec('PRAGMA foreign_keys = OFF;');

        // --- Alt-Schema (ohne Sprach-Dimension) auf v2 heben ---------------
        // sets & card_index sind reine Caches -> bei altem Schema verwerfen.
        if (self::tableExists($pdo, 'sets') && !self::columnExists($pdo, 'sets', 'lang')) {
            $pdo->exec('DROP TABLE sets');
        }
        if (self::tableExists($pdo, 'card_index') && !self::columnExists($pdo, 'card_index', 'lang')) {
            $pdo->exec('DROP TABLE card_index');
        }
        // collection_items: Sprach-Spalte ergaenzen (Daten bewahren).
        if (self::tableExists($pdo, 'collection_items') && !self::columnExists($pdo, 'collection_items', 'catalog_lang')) {
            $pdo->exec('ALTER TABLE collection_items RENAME TO collection_items_old');
            self::createCollectionItems($pdo);
            $pdo->exec(<<<'SQL'
                INSERT INTO collection_items
                    (id, card_id, variant, condition, language, quantity, notes, created_at, updated_at, catalog_lang)
                SELECT id, card_id, variant, condition, language, quantity, notes, created_at, updated_at, 'de'
                FROM collection_items_old
            SQL);
            $pdo->exec('DROP TABLE collection_items_old');
        }

        // --- v3 -> v4: Mehrbenutzer (user_id ergaenzen) --------------------
        // collection_items bekommt eine user_id-Spalte; die UNIQUE-Bedingung
        // muss user_id einschliessen -> Tabelle neu aufbauen. Bestehende Daten
        // bleiben (user_id = NULL) und werden beim Anlegen des ersten Admins
        // diesem zugeordnet (siehe Auth::createUser()).
        if (self::tableExists($pdo, 'collection_items') && !self::columnExists($pdo, 'collection_items', 'user_id')) {
            $pdo->exec('ALTER TABLE collection_items RENAME TO collection_items_old');
            self::createCollectionItems($pdo);
            $pdo->exec(<<<'SQL'
                INSERT INTO collection_items
                    (id, card_id, catalog_lang, variant, condition, language, quantity, notes, created_at, updated_at)
                SELECT id, card_id, catalog_lang, variant, condition, language, quantity, notes, created_at, updated_at
                FROM collection_items_old
            SQL);
            $pdo->exec('DROP TABLE collection_items_old');
        }

        // --- v4 -> v5: visueller Scanner ----------------------------------
        // card_index bekommt eine phash-Spalte (Perceptual-Hash des Karten-
        // bilds). Reiner Cache -> per ALTER ergaenzen, falls noch nicht da.
        if (self::tableExists($pdo, 'card_index') && !self::columnExists($pdo, 'card_index', 'phash')) {
            $pdo->exec('ALTER TABLE card_index ADD COLUMN phash TEXT');
        }

        // --- Tabellen anlegen (fuer frische Installationen) ----------------

        // Benutzerkonten fuer Login + Adminpanel.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role          TEXT NOT NULL DEFAULT 'user',  -- 'admin' | 'user'
                is_active     INTEGER NOT NULL DEFAULT 1,
                created_at    INTEGER NOT NULL,
                last_login    INTEGER
            );
        SQL);

        // Zwischenspeicher der Kartenstammdaten von TCGdex.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cards (
                id           TEXT PRIMARY KEY,
                name         TEXT NOT NULL,
                local_id     TEXT,
                set_id       TEXT,
                set_name     TEXT,
                set_total    INTEGER,
                rarity       TEXT,
                image        TEXT,
                variants     TEXT,
                data         TEXT,
                updated_at   INTEGER NOT NULL
            );
        SQL);

        // Gespeicherte Cardmarket-Preise (nur fuer Karten in der Sammlung).
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS card_prices (
                card_id      TEXT PRIMARY KEY,
                currency     TEXT NOT NULL DEFAULT 'EUR',
                low          REAL,
                avg          REAL,
                trend        REAL,
                avg7         REAL,
                avg30        REAL,
                low_holo     REAL,
                avg_holo     REAL,
                trend_holo   REAL,
                avg7_holo    REAL,
                avg30_holo   REAL,
                source       TEXT DEFAULT 'cardmarket',
                cm_updated   TEXT,
                fetched_at   INTEGER NOT NULL
            );
        SQL);

        self::createCollectionItems($pdo);

        // Manuelle Preis-Korrekturen (uebersteuern die Quelle, z. B. wenn
        // TCGdex/Cardmarket eine JA-Karte falsch verknuepft). Pro Karten-ID.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS price_overrides (
                card_id    TEXT PRIMARY KEY,
                price      REAL NOT NULL,
                currency   TEXT NOT NULL DEFAULT 'EUR',
                updated_at INTEGER NOT NULL
            );
        SQL);

        // Set-Verzeichnis je Sprache (DE/JA): Kuerzel -> Set-ID + Metadaten.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sets (
                id            TEXT NOT NULL,
                lang          TEXT NOT NULL DEFAULT 'de',
                name          TEXT,
                abbreviation  TEXT,
                tcg_online    TEXT,
                total         INTEGER,
                logo          TEXT,
                symbol        TEXT,
                release_date  TEXT,
                serie_name    TEXT,
                cards_json    TEXT,
                fetched_at    INTEGER NOT NULL,
                PRIMARY KEY (id, lang)
            );
        SQL);

        // Lokaler Karten-Index ueber ALLE Karten (je Sprache).
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS card_index (
                card_id    TEXT NOT NULL,
                lang       TEXT NOT NULL DEFAULT 'de',
                name       TEXT NOT NULL,
                name_lower TEXT NOT NULL,
                local_id   TEXT,
                local_num  INTEGER,
                set_id     TEXT,
                set_name   TEXT,
                set_abbr   TEXT,
                image      TEXT,
                phash      TEXT,
                PRIMARY KEY (card_id, lang)
            );
        SQL);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_collection_card ON collection_items(card_id);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_collection_user ON collection_items(user_id);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cards_set ON cards(set_id);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sets_abbr ON sets(abbreviation);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sets_lang ON sets(lang);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cardindex_name ON card_index(name_lower);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cardindex_set ON card_index(set_id, lang);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cardindex_num ON card_index(set_id, local_num, lang);');

        $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    private static function createCollectionItems(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS collection_items (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER,                       -- Besitzer (NULL = Alt-Daten)
                card_id      TEXT NOT NULL,
                catalog_lang TEXT NOT NULL DEFAULT 'de',  -- Katalogsprache (de|ja)
                variant      TEXT NOT NULL DEFAULT 'normal',
                condition    TEXT NOT NULL DEFAULT 'NM',
                language     TEXT NOT NULL DEFAULT 'de',   -- physische Kartensprache
                quantity     INTEGER NOT NULL DEFAULT 1,
                notes        TEXT,
                created_at   INTEGER NOT NULL,
                updated_at   INTEGER NOT NULL,
                UNIQUE (user_id, card_id, catalog_lang, variant, condition, language)
            );
        SQL);
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (($r['name'] ?? null) === $column) {
                return true;
            }
        }
        return false;
    }
}
