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

    // --------------------------------------------------- Japanische Preise
    /**
     * Cardmarket fuehrt fuer japanische Karten kaum eigene Produkte; TCGdex
     * mappt sie deshalb fehlerhaft (oft auf das Basis-Produkt). Fuer JA-Karten
     * nutzen wir daher TCGplayer-Marktpreise (USD) ueber den kostenlosen,
     * schluessellosen Spiegel TCGcsv und rechnen sie in EUR um.
     */
    public const TCGCSV_BASE = 'https://tcgcsv.com/tcgplayer';

    /** TCGplayer-Kategorie-ID fuer "Pokemon Japan". */
    public const TCGCSV_JP_CATEGORY = 85;

    /** Quelle-Kennzeichnung fuer japanische Preise (Spalte card_prices.source). */
    public const JP_PRICE_SOURCE = 'tcgplayer-jp';

    /**
     * Wechselkurs-Endpunkte (schluessellos). Der erste, der antwortet, gewinnt.
     * Beide liefern JSON mit rates.EUR (Basis USD).
     */
    public const FX_ENDPOINTS = [
        'https://api.frankfurter.dev/v1/latest?base=USD&symbols=EUR',
        'https://open.er-api.com/v6/latest/USD',
    ];

    /** Wie lange (Sekunden) ein geholter USD->EUR-Kurs gilt. */
    public const FX_TTL = 86400; // 24 Stunden

    /** Notfall-Kurs USD->EUR, falls kein Endpunkt erreichbar ist. */
    public const USD_EUR_FALLBACK = 0.92;
}
