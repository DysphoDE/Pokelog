<?php

declare(strict_types=1);

/**
 * Zentrale Konfiguration der Anwendung.
 */
final class Config
{
    /** Verzeichnis fuer veraenderliche Daten (SQLite-DB). */
    public const DATA_DIR = __DIR__ . '/../data';

    /** Pfad zur SQLite-Datenbank. */
    public const DB_PATH = self::DATA_DIR . '/pokelog.sqlite';

    /** Basis-URL der TCGdex REST API. */
    public const TCGDEX_BASE = 'https://api.tcgdex.net/v2';

    /** Sprache fuer Kartendaten (deutsche Namen, Sets, Bilder). */
    public const LANG = 'de';

    /**
     * Wie lange (in Sekunden) gespeicherte Cardmarket-Preise als aktuell
     * gelten, bevor sie beim naechsten Aufruf erneut geholt werden.
     * Cardmarket-Daten bei TCGdex werden taeglich aktualisiert.
     */
    public const PRICE_TTL = 86400; // 24 Stunden

    /** Waehrung der Cardmarket-Preise. */
    public const CURRENCY = 'EUR';
}
