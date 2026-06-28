<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/TcgdexClient.php';
require_once __DIR__ . '/TcgPlayerJpClient.php';
require_once __DIR__ . '/Japanese.php';

/**
 * Geschaeftslogik: Kartendaten cachen, Cardmarket-Preise speichern und die
 * Sammlung verwalten.
 *
 * Mehrsprachig: Der Katalog wird je Sprache (DE/JA) lokal indexiert. Deutsche
 * Karten kommen mit deutschen Namen/Bildern und Cardmarket-EUR-Preisen;
 * japanische Karten mit japanischen Namen/Bildern (Cardmarket-Preise dort nur
 * vereinzelt vorhanden).
 *
 * Preis-Strategie: Cardmarket-Preise werden NUR fuer Karten geholt/gespeichert,
 * die aktiv in der Sammlung sind.
 */
final class CollectionRepository
{
    private PDO $db;

    /** Aktiver Benutzer (Sammlung ist pro Benutzer getrennt). */
    private ?int $userId = null;

    /** @var array<string,TcgdexClient> Lazy erzeugte API-Clients je Sprache. */
    private array $clients = [];

    /** Lazy erzeugter TCGplayer-JP-Client (Preise japanischer Karten). */
    private ?TcgPlayerJpClient $jp = null;

    /** Pro Request gecachter USD->EUR-Kurs. */
    private ?float $fxRate = null;

    /** @var array<string,array<string,int>> Besitz-Map (lang => cardId => Menge), pro Request gecacht. */
    private array $ownedCache = [];

    /** Unterstuetzte Katalogsprachen. */
    public const LANGS = ['de', 'ja'];

    /** Erlaubte Zustaende (Cardmarket-Notation). */
    public const CONDITIONS = ['M', 'NM', 'EX', 'GD', 'LP', 'PL', 'PO'];
    public const VARIANTS    = ['normal', 'holo', 'reverse'];

    /**
     * Kantenlaenge des Perceptual-Hash-Rasters (dHash). Ergibt N*N Bit
     * (N=12 -> 144 Bit / 18 Byte). Muss exakt mit der Client-Logik in app.js
     * uebereinstimmen.
     */
    private const HASH_N = 12;

    /**
     * Karten-Suffixe, die NICHT als Set-Kuerzel interpretiert werden duerfen
     * (sonst wuerde z. B. "glurak ex" faelschlich als Set "EX"/Expedition gelten).
     */
    private const CARD_SUFFIXES = [
        'ex', 'gx', 'v', 'vmax', 'vstar', 'vunion', 'lv', 'prime', 'break', 'tag',
    ];

    public function __construct(?TcgdexClient $tcg = null)
    {
        $this->db = Database::pdo();
        if ($tcg !== null) {
            $this->clients['de'] = $tcg;
        }
    }

    private function client(string $lang): TcgdexClient
    {
        $lang = in_array($lang, ['de', 'ja', 'en'], true) ? $lang : 'de';
        return $this->clients[$lang] ??= new TcgdexClient($lang);
    }

    private function jpClient(): TcgPlayerJpClient
    {
        return $this->jp ??= new TcgPlayerJpClient();
    }

    // ------------------------------------------------ App-Metadaten (KV)

    private function metaGet(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM app_meta WHERE key = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private function metaSet(string $key, string $value): void
    {
        $this->db->prepare(<<<'SQL'
            INSERT INTO app_meta (key, value, updated_at) VALUES (:k, :v, :now)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
        SQL)->execute([':k' => $key, ':v' => $value, ':now' => time()]);
    }

    /**
     * Aktueller USD->EUR-Wechselkurs (taeglich gecacht). Faellt bei
     * Netzwerkproblemen auf den letzten bekannten bzw. einen festen Kurs zurueck.
     */
    private function usdToEur(): float
    {
        if ($this->fxRate !== null) {
            return $this->fxRate;
        }

        $cached = $this->metaGet('fx_usd_eur');
        $cachedAt = (int) ($this->metaGet('fx_usd_eur_at') ?? '0');
        if ($cached !== null && (time() - $cachedAt) < Config::FX_TTL) {
            return $this->fxRate = (float) $cached;
        }

        $rate = $this->fetchUsdEur();
        if ($rate !== null && $rate > 0) {
            $this->metaSet('fx_usd_eur', (string) $rate);
            $this->metaSet('fx_usd_eur_at', (string) time());
            return $this->fxRate = $rate;
        }

        // Kein frischer Kurs: letzter bekannter Wert, sonst Fallback.
        return $this->fxRate = $cached !== null ? (float) $cached : Config::USD_EUR_FALLBACK;
    }

    private function fetchUsdEur(): ?float
    {
        foreach (Config::FX_ENDPOINTS as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status < 200 || $status >= 300 || !is_string($body) || $body === '') {
                continue;
            }
            $d = json_decode($body, true);
            $eur = $d['rates']['EUR'] ?? null;
            if (is_numeric($eur) && (float) $eur > 0) {
                return (float) $eur;
            }
        }
        return null;
    }

    /** Setzt den aktiven Benutzer; die Sammlung wird darauf eingeschraenkt. */
    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
        $this->ownedCache = [];
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    private static function normLang(string $lang): string
    {
        return in_array($lang, self::LANGS, true) ? $lang : 'de';
    }

    // ----------------------------------------------------------------- Suche

    /**
     * Reine Namenssuche ueber den lokalen Index (keine Live-API-Calls).
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, string $lang = 'de'): array
    {
        $this->ensureSetIndex();
        // Japanische Zeichen -> automatisch JA-Katalog.
        if (self::hasJapanese($query)) {
            $lang = 'ja';
        }
        return $this->nameSearch($query, self::normLang($lang));
    }

    /**
     * Lokale Suche im card_index. Optional auf ein Set und/oder eine Sprache
     * eingeschraenkt.
     *
     * @param array<int,string> $nameTokens
     * @param array<string,mixed>|null $set
     * @return array<int,array<string,mixed>>
     */
    private function localSearch(array $nameTokens, ?array $set, ?string $lang, int $limit = 80): array
    {
        $where = [];
        $params = [];

        if ($set !== null) {
            $where[] = 'ci.set_id = :setid';
            $params[':setid'] = $set['id'];
            // Set hat eine eigene Sprache -> diese verwenden.
            $lang = $set['lang'] ?? $lang;
        }
        if ($lang !== null) {
            $where[] = 'ci.lang = :lang';
            $params[':lang'] = $lang;
        }

        $full = mb_strtolower(implode(' ', $nameTokens));
        foreach ($nameTokens as $i => $tok) {
            $where[] = "ci.name_lower LIKE :t$i";
            $params[":t$i"] = '%' . mb_strtolower($tok) . '%';
        }

        $clause = $where === [] ? '1=1' : implode(' AND ', $where);
        $params[':full']   = $full;
        $params[':prefix'] = $full . '%';

        $sql = <<<SQL
            SELECT ci.card_id, ci.lang, ci.name, ci.local_id, ci.set_id, ci.set_name,
                   ci.set_abbr, ci.image,
                   p.trend, p.trend_holo, p.avg, p.low
            FROM card_index ci
            LEFT JOIN card_prices p ON p.card_id = ci.card_id
            WHERE $clause
            ORDER BY
                CASE WHEN ci.name_lower = :full THEN 0
                     WHEN ci.name_lower LIKE :prefix THEN 1
                     ELSE 2 END,
                LENGTH(ci.name),
                ci.set_name,
                ci.local_num
            LIMIT $limit
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $this->mapIndexRows($stmt->fetchAll());
    }

    /**
     * Wandelt card_index-Zeilen in einheitliche Such-Ergebnisse um.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function mapIndexRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $trend = $r['trend'] ?? $r['trend_holo'] ?? $r['avg'] ?? $r['low'] ?? null;
            $lang = $r['lang'] ?? 'de';
            $alt = self::altNames($lang, (string) $r['name']);
            $out[] = [
                'id'      => $r['card_id'],
                'lang'    => $lang,
                'name'    => $r['name'],
                'nameDe'  => $alt['de'],
                'nameEn'  => $alt['en'],
                'nameAlt' => $alt['alt'],
                'localId' => $r['local_id'],
                'image'   => TcgdexClient::imageUrl($r['image'] ?? null, 'low'),
                'set'     => $r['set_name'],
                'setId'   => $r['set_id'],
                'setAbbr' => $r['set_abbr'],
                'owned'   => $this->ownedFor($lang, (string) $r['card_id']),
                'pricing' => $trend !== null ? ['trend' => (float) $trend, 'currency' => Config::CURRENCY] : null,
            ];
        }
        return $out;
    }

    /**
     * Liefert fuer japanische Karten den deutschen/englischen Pokémon-Namen
     * (ueber die National-Dex) plus eine Romaji-Lesehilfe. Sonst leer.
     *
     * @return array{de:?string,en:?string,alt:?string}
     */
    private static function altNames(string $lang, string $name): array
    {
        if ($lang !== 'ja' || $name === '') {
            return ['de' => null, 'en' => null, 'alt' => null];
        }
        $tr = Japanese::translate($name);
        return [
            'de'  => $tr['de'] ?? null,
            'en'  => $tr['en'] ?? null,
            'alt' => Japanese::romaji($name),
        ];
    }

    private static function tokenize(string $query): array
    {
        $tokens = preg_split('/\s+/', trim($query)) ?: [];
        return array_values(array_filter($tokens, static fn($t) => $t !== ''));
    }

    /** Enthaelt die Eingabe Hiragana/Katakana/Kanji? */
    public static function hasJapanese(string $s): bool
    {
        return (bool) preg_match('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $s);
    }

    /**
     * Besitz-Menge einer Karte in der angegebenen Katalogsprache (gecacht).
     */
    private function ownedFor(string $lang, string $cardId): int
    {
        if (!isset($this->ownedCache[$lang])) {
            // Ohne angemeldeten Benutzer gibt es keine serverseitige Sammlung.
            if ($this->userId === null) {
                $this->ownedCache[$lang] = [];
                return 0;
            }
            $stmt = $this->db->prepare(
                'SELECT card_id, SUM(quantity) AS q FROM collection_items WHERE catalog_lang = ? AND user_id = ? GROUP BY card_id'
            );
            $stmt->execute([$lang, $this->userId]);
            $map = [];
            foreach ($stmt->fetchAll() as $r) {
                $map[(string) $r['card_id']] = (int) $r['q'];
            }
            $this->ownedCache[$lang] = $map;
        }
        return $this->ownedCache[$lang][$cardId] ?? 0;
    }

    /**
     * Namenssuche: im JA-Katalog werden lateinische Eingaben zusaetzlich ueber
     * den deutschen/englischen Pokémon-Namen aufgeloest (z. B. "Charizard" oder
     * "Glurak" findet リザードン-Karten).
     *
     * @return array<int,array<string,mixed>>
     */
    private function nameSearch(string $query, string $lang): array
    {
        $tokens = self::tokenize($query);
        if ($tokens === []) {
            return [];
        }
        if ($lang === 'ja' && !self::hasJapanese($query)) {
            $merged = [];
            foreach (Japanese::toJapanese($query) as $jaName) {
                foreach ($this->localSearch([$jaName], null, 'ja') as $row) {
                    $merged[$row['id']] = $row;
                }
            }
            if ($merged !== []) {
                return array_values($merged);
            }
        }
        return $this->localSearch($tokens, null, $lang);
    }

    /**
     * Kompakter Such-Index einer Sprache fuer die clientseitige Sofortsuche.
     * Enthaelt nur die noetigen Felder; bei JA zusaetzlich DE/EN-Namen.
     *
     * @return array<int,array<string,mixed>>
     */
    public function searchIndex(string $lang = 'de'): array
    {
        $this->ensureSetIndex();
        $lang = self::normLang($lang);
        // Set-Gesamtzahl (s.total) mitliefern -> der Scanner kann clientseitig
        // ueber "Nummer / Gesamt" (z. B. 136/189) eindeutig matchen.
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT ci.card_id, ci.name, ci.local_id, ci.local_num, ci.set_id,
                   ci.set_name, ci.set_abbr, ci.image, s.total AS set_total
            FROM card_index ci
            LEFT JOIN sets s ON s.id = ci.set_id AND s.lang = ci.lang
            WHERE ci.lang = :lang
        SQL);
        $stmt->execute([':lang' => $lang]);

        $isJa = $lang === 'ja';
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $entry = [
                'id'  => $r['card_id'],
                'n'   => $r['name'],
                'l'   => $r['local_id'],
                'ln'  => $r['local_num'] !== null ? (int) $r['local_num'] : null,
                't'   => $r['set_total'] !== null ? (int) $r['set_total'] : null,
                'sid' => $r['set_id'],
                's'   => $r['set_name'],
                'a'   => $r['set_abbr'],
                'img' => $r['image'] ?: null,
            ];
            if ($isJa) {
                // Schnelle Uebersetzung (ohne teuren Substring-Scan).
                $tr = Japanese::translate((string) $r['name'], false);
                if ($tr !== null) {
                    if (!empty($tr['en'])) {
                        $entry['en'] = $tr['en'];
                    }
                    if (!empty($tr['de'])) {
                        $entry['de'] = $tr['de'];
                    }
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Vollstaendige Besitz-Map (cardId => Menge) einer Katalogsprache.
     * Fuer die clientseitige Besitz-Markierung der Sofortsuche.
     *
     * @return array<string,int>
     */
    public function ownedMap(string $lang = 'de'): array
    {
        $lang = self::normLang($lang);
        // ownedFor baut den Cache fuer die Sprache einmalig auf.
        $this->ownedFor($lang, '__warm__');
        return $this->ownedCache[$lang] ?? [];
    }

    // ------------------------------------------------ Karten-Detailseite

    /**
     * Liefert alle Detaildaten zu einer Karte: Stammdaten (TCGdex), aktueller
     * Cardmarket-Preis, Besitzmenge, DE/EN-Name (bei JA) und verwandte Karten.
     *
     * @return array<string,mixed>|null
     */
    public function getCardDetail(string $id, string $lang = 'de'): ?array
    {
        $lang = self::normLang($lang);
        $card = $this->getCardCached($id, $lang);
        if ($card === null) {
            return null;
        }
        // Preis sicherstellen (holt + cacht bei Bedarf) und vollstaendig laden.
        $this->getPrices([$id], $lang);
        $pr = $this->db->prepare('SELECT * FROM card_prices WHERE card_id = ?');
        $pr->execute([$id]);
        $price = $pr->fetch() ?: null;

        $name = (string) ($card['name'] ?? '');
        $names = $lang === 'ja' ? Japanese::translate($name) : null;

        return [
            'card'     => $card,
            'lang'     => $lang,
            'owned'    => $this->ownedFor($lang, $id),
            'names'    => $names,
            'price'    => $this->fullPriceRow($price),
            'override' => $this->getOverride($id),
            'related'  => $this->relatedCards($id, $name, $card['set']['id'] ?? null, $lang),
        ];
    }

    /** Liefert den manuell gesetzten Preis einer Karte (oder null). */
    public function getOverride(string $cardId): ?float
    {
        $stmt = $this->db->prepare('SELECT price FROM price_overrides WHERE card_id = ?');
        $stmt->execute([$cardId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (float) $v;
    }

    /**
     * Setzt oder entfernt eine manuelle Preis-Korrektur. price=null loescht.
     */
    public function setOverride(string $cardId, ?float $price): void
    {
        $cardId = trim($cardId);
        if ($cardId === '') {
            throw new InvalidArgumentException('cardId fehlt');
        }
        if ($price === null || $price < 0) {
            $stmt = $this->db->prepare('DELETE FROM price_overrides WHERE card_id = ?');
            $stmt->execute([$cardId]);
            return;
        }
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO price_overrides (card_id, price, currency, updated_at)
            VALUES (:id, :price, :cur, :now)
            ON CONFLICT(card_id) DO UPDATE SET price = :price, updated_at = :now
        SQL);
        $stmt->execute([
            ':id' => $cardId,
            ':price' => round($price, 2),
            ':cur' => Config::CURRENCY,
            ':now' => time(),
        ]);
    }

    /**
     * Verwandte Karten: weitere Karten desselben Sets und andere Drucke
     * (gleicher Name in anderen Sets).
     *
     * @return array{set:array<int,array<string,mixed>>,prints:array<int,array<string,mixed>>}
     */
    private function relatedCards(string $id, string $name, ?string $setId, string $lang): array
    {
        $sameSet = [];
        if ($setId !== null && $setId !== '') {
            $stmt = $this->db->prepare(<<<'SQL'
                SELECT ci.card_id, ci.lang, ci.name, ci.local_id, ci.set_id, ci.set_name, ci.set_abbr, ci.image,
                       p.trend, p.trend_holo, p.avg, p.low
                FROM card_index ci
                LEFT JOIN card_prices p ON p.card_id = ci.card_id
                WHERE ci.lang = :lang AND ci.set_id = :sid AND ci.card_id <> :id
                ORDER BY ci.local_num, ci.local_id
                LIMIT 18
            SQL);
            $stmt->execute([':lang' => $lang, ':sid' => $setId, ':id' => $id]);
            $sameSet = $this->mapIndexRows($stmt->fetchAll());
        }

        $prints = [];
        if ($name !== '') {
            $stmt = $this->db->prepare(<<<'SQL'
                SELECT ci.card_id, ci.lang, ci.name, ci.local_id, ci.set_id, ci.set_name, ci.set_abbr, ci.image,
                       p.trend, p.trend_holo, p.avg, p.low
                FROM card_index ci
                LEFT JOIN card_prices p ON p.card_id = ci.card_id
                WHERE ci.lang = :lang AND ci.name = :name AND ci.card_id <> :id
                ORDER BY ci.set_name
                LIMIT 18
            SQL);
            $stmt->execute([':lang' => $lang, ':name' => $name, ':id' => $id]);
            $prints = $this->mapIndexRows($stmt->fetchAll());
        }

        return ['set' => $sameSet, 'prints' => $prints];
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    private function fullPriceRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $num = static fn($k) => isset($row[$k]) && $row[$k] !== null ? (float) $row[$k] : null;
        $trend = $num('trend') ?? $num('trend_holo') ?? $num('avg') ?? $num('low');
        if ($trend === null) {
            return null;
        }
        return [
            'currency'  => $row['currency'] ?? Config::CURRENCY,
            'trend'     => $num('trend'),
            'avg'       => $num('avg'),
            'low'       => $num('low'),
            'avg7'      => $num('avg7'),
            'avg30'     => $num('avg30'),
            'trendHolo' => $num('trend_holo'),
            'avgHolo'   => $num('avg_holo'),
            'lowHolo'   => $num('low_holo'),
            'display'   => $trend,
            'cmUpdated' => $row['cm_updated'] ?? null,
            'source'    => $row['source'] ?? null,
        ];
    }

    // -------------------------------------------------- Smarte Suche (Router)

    /**
     * Erkennt automatisch Sammlernummer ("MEP 047"), Kombi "Name + Set"
     * ("mew PAF") oder reine Namenssuche. $lang ist die bevorzugte Sprache.
     *
     * @return array{mode:string,query:string,lang:string,set?:array<string,mixed>|null,results:array<int,array<string,mixed>>}
     */
    public function smartSearch(string $query, string $lang = 'de'): array
    {
        $this->ensureSetIndex();
        $lang = self::normLang($lang);
        // Japanische Zeichen in der Eingabe -> automatisch JA-Katalog.
        if (self::hasJapanese($query)) {
            $lang = 'ja';
        }

        // 1) Suche per Sammlernummer.
        $parsed = self::parseNumberQuery($query);
        if ($parsed !== null) {
            $found = $this->searchByNumber($parsed['code'], $parsed['number'], $lang);
            if ($found['results'] !== []) {
                return ['mode' => 'number', 'query' => $query, 'lang' => $lang, 'set' => $found['set'], 'results' => $found['results']];
            }
            if ($found['set'] !== null) {
                return [
                    'mode'    => 'number',
                    'query'   => $query,
                    'lang'    => $lang,
                    'set'     => $found['set'],
                    'results' => [],
                    'note'    => sprintf(
                        'Set "%s" erkannt, aber Karte Nr. %d ist in der TCGdex-Datenbank nicht vorhanden.',
                        $found['set']['name'] ?? $parsed['code'],
                        $parsed['number']
                    ),
                ];
            }
        }

        // 2) Kombi-Suche Name + Set (z. B. "mew PAF" oder "PAF mew").
        $tokens = self::tokenize($query);
        $nameFallback = null;
        if (count($tokens) >= 2) {
            foreach ($tokens as $idx => $tok) {
                if (in_array(mb_strtolower($tok), self::CARD_SUFFIXES, true)) {
                    continue;
                }
                $set = $this->resolveSet($tok, $lang);
                if ($set === null) {
                    continue;
                }
                $nameTokens = $tokens;
                unset($nameTokens[$idx]);
                $nameTokens = array_values($nameTokens);
                if ($nameTokens === []) {
                    continue;
                }
                $nameFallback = $nameFallback ?? $nameTokens;
                $results = $this->localSearch($nameTokens, $set, $set['lang'] ?? $lang);
                if ($results !== []) {
                    return [
                        'mode'    => 'combo',
                        'query'   => $query,
                        'lang'    => $set['lang'] ?? $lang,
                        'set'     => $this->setSummary($set),
                        'results' => $results,
                    ];
                }
            }
        }

        // 3) Set erkannt, aber Name dort nicht gefunden -> Name ohne Set-Code.
        if ($nameFallback !== null) {
            $r = $this->localSearch($nameFallback, null, $lang);
            if ($r !== []) {
                return ['mode' => 'name', 'query' => $query, 'lang' => $lang, 'results' => $r];
            }
        }

        // 4) Reine Namenssuche.
        return ['mode' => 'name', 'query' => $query, 'lang' => $lang, 'results' => $this->search($query, $lang)];
    }

    /**
     * Zerlegt eine Eingabe in Set-Code + Nummer (z. B. "MEP 047", "mep-47").
     *
     * @return array{code:string,number:int}|null
     */
    public static function parseNumberQuery(string $query): ?array
    {
        $q = trim($query);
        if (preg_match('/^([A-Za-z][A-Za-z0-9.]{0,9})[\s\-]+0*(\d{1,4})(?:\s*\/\s*\d{1,4})?$/', $q, $m)) {
            return ['code' => $m[1], 'number' => (int) $m[2]];
        }
        if (preg_match('/^([A-Za-z]{2,6})0*(\d{1,4})$/', $q, $m)) {
            return ['code' => $m[1], 'number' => (int) $m[2]];
        }
        return null;
    }

    /**
     * Sucht eine Karte ueber Set-Code und gedruckte Nummer.
     *
     * @return array{set:?array<string,mixed>,results:array<int,array<string,mixed>>}
     */
    public function searchByNumber(string $code, int $number, string $lang = 'de'): array
    {
        $this->ensureSetIndex();
        $set = $this->resolveSet($code, $lang);
        if ($set === null) {
            return ['set' => null, 'results' => []];
        }

        $stmt = $this->db->prepare(<<<'SQL'
            SELECT ci.card_id, ci.lang, ci.name, ci.local_id, ci.set_id, ci.set_name,
                   ci.set_abbr, ci.image,
                   p.trend, p.trend_holo, p.avg, p.low
            FROM card_index ci
            LEFT JOIN card_prices p ON p.card_id = ci.card_id
            WHERE ci.set_id = :setid AND ci.lang = :lang AND ci.local_num = :num
            ORDER BY ci.local_id
        SQL);
        $stmt->execute([':setid' => $set['id'], ':lang' => $set['lang'], ':num' => $number]);
        $results = $this->mapIndexRows($stmt->fetchAll());

        return ['set' => $this->setSummary($set), 'results' => $results];
    }

    /**
     * Loest ein gedrucktes Kuerzel (oder eine Set-ID) zu einem Set auf.
     * Bevorzugt die angegebene Sprache, faellt sonst auf eine beliebige zurueck.
     *
     * @return array<string,mixed>|null
     */
    public function resolveSet(string $code, ?string $lang = null): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT * FROM sets
            WHERE UPPER(abbreviation) = UPPER(:c)
               OR UPPER(id) = UPPER(:c)
               OR UPPER(tcg_online) = UPPER(:c)
            ORDER BY
               (:lang IS NOT NULL AND lang = :lang) DESC,
               (UPPER(abbreviation) = UPPER(:c)) DESC,
               fetched_at DESC
            LIMIT 1
        SQL);
        $stmt->execute([':c' => $code, ':lang' => $lang]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $set
     * @return array<string,mixed>
     */
    private function setSummary(array $set): array
    {
        $lang = $set['lang'] ?? 'de';
        return [
            'id'         => $set['id'],
            'lang'       => $lang,
            'name'       => $set['name'],
            'nameEn'     => $lang === 'ja' ? Japanese::setEnglish((string) $set['name']) : null,
            'nameRomaji' => $lang === 'ja' ? Japanese::romaji((string) $set['name']) : null,
            'abbr'       => $set['abbreviation'],
            'total'      => isset($set['total']) && $set['total'] !== null ? (int) $set['total'] : null,
        ];
    }

    // ------------------------------------------------------- Set-Browser

    /**
     * Liefert alle Sets einer Sprache, nach Serie gruppiert und nach
     * Erscheinungsdatum sortiert (fuer die Set-Uebersicht).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listSets(string $lang = 'de'): array
    {
        $this->ensureSetIndex();
        $lang = self::normLang($lang);
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT s.id, s.name, s.abbreviation, s.total, s.logo, s.symbol,
                   s.release_date, s.serie_name,
                   (SELECT COUNT(*) FROM card_index ci WHERE ci.set_id = s.id AND ci.lang = s.lang) AS card_count
            FROM sets s
            WHERE s.lang = :lang
            ORDER BY (s.release_date IS NULL), s.release_date DESC, s.name
        SQL);
        $stmt->execute([':lang' => $lang]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'          => $r['id'],
                'lang'        => $lang,
                'name'        => $r['name'],
                'nameEn'      => $lang === 'ja' ? Japanese::setEnglish((string) $r['name']) : null,
                'nameRomaji'  => $lang === 'ja' ? Japanese::romaji((string) $r['name']) : null,
                'abbr'        => $r['abbreviation'],
                'total'       => $r['total'] !== null ? (int) $r['total'] : null,
                'cardCount'   => (int) $r['card_count'],
                'logo'        => self::assetUrl($r['logo']),
                'symbol'      => self::assetUrl($r['symbol']),
                'releaseDate' => $r['release_date'],
                'serie'       => $r['serie_name'] ?: 'Sonstige',
            ];
        }
        return $out;
    }

    /**
     * Alle Karten eines Sets (aus dem lokalen Index) inkl. Bild & ggf. Preis.
     *
     * @return array{set:?array<string,mixed>,cards:array<int,array<string,mixed>>}
     */
    public function getSetCards(string $setId, string $lang = 'de'): array
    {
        $this->ensureSetIndex();
        $lang = self::normLang($lang);

        $setStmt = $this->db->prepare('SELECT * FROM sets WHERE id = :id AND lang = :lang');
        $setStmt->execute([':id' => $setId, ':lang' => $lang]);
        $set = $setStmt->fetch();

        $stmt = $this->db->prepare(<<<'SQL'
            SELECT ci.card_id, ci.lang, ci.name, ci.local_id, ci.set_id, ci.set_name,
                   ci.set_abbr, ci.image,
                   p.trend, p.trend_holo, p.avg, p.low
            FROM card_index ci
            LEFT JOIN card_prices p ON p.card_id = ci.card_id
            WHERE ci.set_id = :sid AND ci.lang = :lang
            ORDER BY ci.local_num, ci.local_id
        SQL);
        $stmt->execute([':sid' => $setId, ':lang' => $lang]);
        $cards = $this->mapIndexRows($stmt->fetchAll());
        $ownedCount = 0;
        foreach ($cards as $c) {
            if (($c['owned'] ?? 0) > 0) {
                $ownedCount++;
            }
        }

        return [
            'set'   => $set === false ? null : [
                'id'          => $set['id'],
                'lang'        => $lang,
                'name'        => $set['name'],
                'nameEn'      => $lang === 'ja' ? Japanese::setEnglish((string) $set['name']) : null,
                'nameRomaji'  => $lang === 'ja' ? Japanese::romaji((string) $set['name']) : null,
                'abbr'        => $set['abbreviation'],
                'total'       => $set['total'] !== null ? (int) $set['total'] : null,
                'cardCount'   => count($cards),
                'ownedCount'  => $ownedCount,
                'logo'        => self::assetUrl($set['logo']),
                'symbol'      => self::assetUrl($set['symbol']),
                'releaseDate' => $set['release_date'],
                'serie'       => $set['serie_name'],
            ],
            'cards' => $cards,
        ];
    }

    private static function assetUrl(?string $base, string $ext = 'png'): ?string
    {
        if ($base === null || $base === '') {
            return null;
        }
        return $base . '.' . $ext;
    }

    // ---------------------------------------------------- Set-Index aufbauen

    public function ensureSetIndex(): void
    {
        $setsCount = (int) $this->db->query('SELECT COUNT(*) FROM sets')->fetchColumn();
        if ($setsCount === 0) {
            $this->rebuildSetIndex();
            return;
        }
        $idxCount = (int) $this->db->query('SELECT COUNT(*) FROM card_index')->fetchColumn();
        if ($idxCount === 0) {
            $this->buildCardIndexFromSets();
        }
    }

    /**
     * Baut Set-Verzeichnis und Karten-Index fuer ALLE Sprachen neu auf.
     * Fuer Deutsch wird bei fehlenden Bildern das englische Artwork als
     * Fallback genutzt.
     *
     * @return array{sets:int,failed:int,cards:int,enFallbacks:int,langs:array<string,int>}
     */
    public function rebuildSetIndex(): array
    {
        @set_time_limit(0);
        $now = time();
        $okTotal = 0;
        $failed = 0;
        $enFallbacks = 0;
        $perLang = [];

        $this->db->exec('DELETE FROM card_index');
        $this->db->exec('DELETE FROM sets');

        $setStmt = $this->db->prepare(<<<'SQL'
            INSERT INTO sets (id, lang, name, abbreviation, tcg_online, total, logo, symbol, release_date, serie_name, cards_json, fetched_at)
            VALUES (:id, :lang, :name, :abbr, :tcg, :total, :logo, :symbol, :release, :serie, :cards, :now)
        SQL);

        foreach (self::LANGS as $lang) {
            $client = $this->client($lang);
            $sets = $client->listSets();
            $okLang = 0;

            foreach ($sets as $brief) {
                $id = $brief['id'] ?? null;
                if ($id === null) {
                    continue;
                }
                try {
                    $detail = $client->getSet((string) $id);
                    if ($detail === null) {
                        $failed++;
                        continue;
                    }

                    $cards = array_map(static function ($c) {
                        return [
                            'id'      => $c['id'] ?? null,
                            'localId' => $c['localId'] ?? null,
                            'name'    => $c['name'] ?? '',
                            'image'   => $c['image'] ?? null,
                        ];
                    }, $detail['cards'] ?? []);

                    // Englischer Bild-Fallback nur fuer Deutsch.
                    if ($lang === 'de') {
                        $missing = 0;
                        foreach ($cards as $c) {
                            if (empty($c['image'])) {
                                $missing++;
                            }
                        }
                        if ($missing > 0) {
                            try {
                                $en = $this->client('en')->getSet((string) $id);
                                if ($en !== null) {
                                    $enMap = [];
                                    foreach (($en['cards'] ?? []) as $ec) {
                                        if (!empty($ec['image']) && isset($ec['localId'])) {
                                            $enMap[(string) $ec['localId']] = $ec['image'];
                                        }
                                    }
                                    foreach ($cards as &$c) {
                                        if (empty($c['image']) && isset($enMap[(string) $c['localId']])) {
                                            $c['image'] = $enMap[(string) $c['localId']];
                                            $enFallbacks++;
                                        }
                                    }
                                    unset($c);
                                }
                            } catch (Throwable $e) {
                                // optional
                            }
                        }
                    }

                    $setMeta = [
                        'id'   => $id,
                        'lang' => $lang,
                        'name' => $detail['name'] ?? ($brief['name'] ?? ''),
                        'abbr' => $detail['abbreviation']['official'] ?? null,
                    ];

                    $setStmt->execute([
                        ':id'      => $id,
                        ':lang'    => $lang,
                        ':name'    => $setMeta['name'],
                        ':abbr'    => $setMeta['abbr'],
                        ':tcg'     => $detail['tcgOnline'] ?? null,
                        ':total'   => $detail['cardCount']['total'] ?? ($detail['cardCount']['official'] ?? null),
                        ':logo'    => $detail['logo'] ?? null,
                        ':symbol'  => $detail['symbol'] ?? null,
                        ':release' => $detail['releaseDate'] ?? null,
                        ':serie'   => $detail['serie']['name'] ?? null,
                        ':cards'   => json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ':now'     => $now,
                    ]);

                    $this->insertCardIndex($cards, $setMeta);
                    $okLang++;
                    $okTotal++;
                } catch (Throwable $e) {
                    $failed++;
                }
            }
            $perLang[$lang] = $okLang;
        }

        $cardCount = (int) $this->db->query('SELECT COUNT(*) FROM card_index')->fetchColumn();
        return ['sets' => $okTotal, 'failed' => $failed, 'cards' => $cardCount, 'enFallbacks' => $enFallbacks, 'langs' => $perLang];
    }

    /**
     * Baut nur den lokalen Karten-Index aus den gespeicherten Set-Daten neu
     * auf (ohne Netzwerkzugriff).
     */
    public function buildCardIndexFromSets(): void
    {
        $this->db->exec('DELETE FROM card_index');
        $rows = $this->db->query('SELECT id, lang, name, abbreviation, cards_json FROM sets')->fetchAll();
        foreach ($rows as $s) {
            $cards = json_decode((string) ($s['cards_json'] ?? '[]'), true);
            if (!is_array($cards)) {
                continue;
            }
            $this->insertCardIndex($cards, [
                'id'   => $s['id'],
                'lang' => $s['lang'] ?? 'de',
                'name' => $s['name'],
                'abbr' => $s['abbreviation'],
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $cards
     * @param array{id:?string,lang:string,name:?string,abbr:?string} $set
     */
    private function insertCardIndex(array $cards, array $set): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO card_index (card_id, lang, name, name_lower, local_id, local_num, set_id, set_name, set_abbr, image)
            VALUES (:cid, :lang, :name, :nl, :lid, :lnum, :sid, :sname, :sabbr, :img)
            ON CONFLICT(card_id, lang) DO UPDATE SET
                name = excluded.name, name_lower = excluded.name_lower,
                local_id = excluded.local_id, local_num = excluded.local_num,
                set_id = excluded.set_id, set_name = excluded.set_name,
                set_abbr = excluded.set_abbr, image = excluded.image
        SQL);

        $lang = $set['lang'] ?? 'de';
        foreach ($cards as $c) {
            if (empty($c['id'])) {
                continue;
            }
            $localId = isset($c['localId']) ? (string) $c['localId'] : null;
            $localNum = ($localId !== null && is_numeric($localId)) ? (int) $localId : null;
            $name = (string) ($c['name'] ?? '');
            $stmt->execute([
                ':cid'   => $c['id'],
                ':lang'  => $lang,
                ':name'  => $name,
                ':nl'    => mb_strtolower($name),
                ':lid'   => $localId,
                ':lnum'  => $localNum,
                ':sid'   => $set['id'] ?? null,
                ':sname' => $set['name'] ?? null,
                ':sabbr' => $set['abbr'] ?? null,
                ':img'   => $c['image'] ?? null,
            ]);
        }
    }

    // -------------------------------------------------- Visueller Scanner

    /**
     * Berechnet fehlende Perceptual-Hashes (dHash) der Kartenbilder in Chargen.
     * Resumierbar: verarbeitet pro Aufruf nur bis zu $batch noch nicht
     * gehashte Karten. Der Client pollt, bis "remaining" 0 ist.
     *
     * @return array{done:int,total:int,remaining:int,processed:int,failed:int,error?:string}
     */
    public function buildPerceptualHashes(int $batch = 200): array
    {
        @set_time_limit(0);
        $this->ensureSetIndex();

        $total = (int) $this->db->query(
            'SELECT COUNT(*) FROM card_index WHERE image IS NOT NULL AND image <> ""'
        )->fetchColumn();

        if (!function_exists('imagecreatefromstring')) {
            $remaining = (int) $this->db->query(
                'SELECT COUNT(*) FROM card_index WHERE phash IS NULL AND image IS NOT NULL AND image <> ""'
            )->fetchColumn();
            return [
                'done' => $total - $remaining, 'total' => $total, 'remaining' => $remaining,
                'processed' => 0, 'failed' => 0, 'error' => 'PHP-GD ist nicht verfuegbar.',
            ];
        }

        $stmt = $this->db->prepare(<<<'SQL'
            SELECT card_id, lang, image FROM card_index
            WHERE phash IS NULL AND image IS NOT NULL AND image <> ""
            LIMIT :lim
        SQL);
        $stmt->bindValue(':lim', max(1, $batch), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // 1) PNG-Variante parallel laden (GD liest PNG ohne webp-Erweiterung).
        $pngUrls = [];
        foreach ($rows as $i => $r) {
            $pngUrls[(string) $i] = (string) (TcgdexClient::imageUrl($r['image'], 'low', 'png') ?? '');
        }
        $images = TcgdexClient::fetchImages($pngUrls);

        // 2) webp-Fallback fuer fehlende.
        $missing = [];
        foreach ($rows as $i => $r) {
            if (!isset($images[(string) $i])) {
                $missing[(string) $i] = (string) (TcgdexClient::imageUrl($r['image'], 'low', 'webp') ?? '');
            }
        }
        if ($missing !== []) {
            foreach (TcgdexClient::fetchImages($missing) as $k => $v) {
                $images[$k] = $v;
            }
        }

        $upd = $this->db->prepare(
            'UPDATE card_index SET phash = :h WHERE card_id = :cid AND lang = :lang'
        );

        $processed = 0;
        $failed = 0;
        foreach ($rows as $i => $r) {
            $bytes = $images[(string) $i] ?? null;
            $hash = ($bytes !== null) ? $this->dhashFromBytes($bytes) : null;
            if ($hash === null) {
                // Sentinel "" markiert "versucht, kein verwertbares Bild" -> wird
                // nicht erneut ausgewaehlt (garantiert Fortschritt) und nicht an
                // den Client ausgeliefert.
                $upd->execute([':h' => '', ':cid' => $r['card_id'], ':lang' => $r['lang']]);
                $failed++;
                continue;
            }
            $upd->execute([':h' => $hash, ':cid' => $r['card_id'], ':lang' => $r['lang']]);
            $processed++;
        }

        $remaining = (int) $this->db->query(
            'SELECT COUNT(*) FROM card_index WHERE phash IS NULL AND image IS NOT NULL AND image <> ""'
        )->fetchColumn();

        return [
            'done' => $total - $remaining,
            'total' => $total,
            'remaining' => $remaining,
            'processed' => $processed,
            'failed' => $failed,
        ];
    }

    /**
     * Liefert die kompakte Hash-Tabelle einer Sprache fuer den Client-Scanner.
     *
     * @return array<int,array{id:string,h:string}>
     */
    public function getHashTable(string $lang = 'de'): array
    {
        $this->ensureSetIndex();
        $lang = self::normLang($lang);
        $stmt = $this->db->prepare(
            'SELECT card_id, phash FROM card_index WHERE lang = :lang AND phash IS NOT NULL AND phash <> ""'
        );
        $stmt->execute([':lang' => $lang]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = ['id' => (string) $r['card_id'], 'h' => (string) $r['phash']];
        }
        return $out;
    }

    /** Kurzer Status (fuer Admin-UI), ohne etwas zu berechnen. */
    public function hashStatus(): array
    {
        $total = (int) $this->db->query(
            'SELECT COUNT(*) FROM card_index WHERE image IS NOT NULL AND image <> ""'
        )->fetchColumn();
        $remaining = (int) $this->db->query(
            'SELECT COUNT(*) FROM card_index WHERE phash IS NULL AND image IS NOT NULL AND image <> ""'
        )->fetchColumn();
        return ['done' => $total - $remaining, 'total' => $total, 'remaining' => $remaining];
    }

    /** Dekodiert Bildbytes und berechnet den dHash (Base64) oder null. */
    private function dhashFromBytes(string $bytes): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return null;
        }
        try {
            return $this->dhashFromGd($src);
        } finally {
            imagedestroy($src);
        }
    }

    /**
     * Difference-Hash eines GD-Bildes: Graustufen, auf (N+1) x N skalieren,
     * pro Zeile benachbarte Pixel vergleichen (links > rechts) -> N*N Bit.
     * Bits werden MSB-first in Bytes gepackt und Base64-kodiert.
     */
    private function dhashFromGd($src, int $n = self::HASH_N): string
    {
        $w = $n + 1;
        $h = $n;
        $small = imagecreatetruecolor($w, $h);
        imagecopyresampled($small, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));

        $gray = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray[$y][$x] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            }
        }
        imagedestroy($small);

        $bits = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $bits .= ($gray[$y][$x] > $gray[$y][$x + 1]) ? '1' : '0';
            }
        }

        $bytes = '';
        for ($i = 0, $len = strlen($bits); $i < $len; $i += 8) {
            $bytes .= chr((int) bindec(str_pad(substr($bits, $i, 8), 8, '0')));
        }
        return base64_encode($bytes);
    }

    // ------------------------------------------------------- Karten-Caching

    /**
     * Liefert ein vollstaendiges Kartenobjekt (in der gewuenschten Sprache):
     * erst aus dem lokalen Cache, sonst frisch von TCGdex.
     *
     * @return array<string,mixed>|null
     */
    public function getCardCached(string $id, string $lang = 'de', bool $forceFresh = false): ?array
    {
        $lang = self::normLang($lang);
        if (!$forceFresh) {
            $row = $this->db->prepare('SELECT data FROM cards WHERE id = ?');
            $row->execute([$id]);
            $cached = $row->fetchColumn();
            if ($cached) {
                $decoded = json_decode((string) $cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $card = $this->client($lang)->getCard($id);
        if ($card === null) {
            return null;
        }
        $this->storeCard($card, $lang);
        return $card;
    }

    /**
     * @param array<string,mixed> $card
     */
    private function storeCard(array $card, string $lang = 'de'): void
    {
        $now = time();
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO cards (id, name, local_id, set_id, set_name, set_total, rarity, image, variants, data, updated_at)
            VALUES (:id, :name, :local_id, :set_id, :set_name, :set_total, :rarity, :image, :variants, :data, :updated_at)
            ON CONFLICT(id) DO UPDATE SET
                name = excluded.name, local_id = excluded.local_id,
                set_id = excluded.set_id, set_name = excluded.set_name,
                set_total = excluded.set_total, rarity = excluded.rarity,
                image = excluded.image, variants = excluded.variants,
                data = excluded.data, updated_at = excluded.updated_at
        SQL);

        $stmt->execute([
            ':id'         => $card['id'],
            ':name'       => $card['name'] ?? '',
            ':local_id'   => $card['localId'] ?? null,
            ':set_id'     => $card['set']['id'] ?? null,
            ':set_name'   => $card['set']['name'] ?? null,
            ':set_total'  => $card['set']['cardCount']['official']
                ?? $card['set']['cardCount']['total'] ?? null,
            ':rarity'     => $card['rarity'] ?? null,
            ':image'      => $card['image'] ?? null,
            ':variants'   => json_encode($card['variants'] ?? new stdClass()),
            ':data'       => json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':updated_at' => $now,
        ]);

        $this->storePriceFromCard($card, $now, $lang);
    }

    /**
     * Speichert den eingebetteten Cardmarket-Preis (EUR). Fuer japanische
     * Karten ist dieser Wert unbrauchbar (TCGdex mappt JA-Karten fehlerhaft),
     * daher wird er bei lang='ja' uebersprungen - JA-Preise kommen ueber
     * TCGplayer (siehe getJpPrices()).
     *
     * @param array<string,mixed> $card
     */
    private function storePriceFromCard(array $card, int $now, string $lang = 'de'): void
    {
        if ($lang === 'ja') {
            return;
        }
        $cm = $card['pricing']['cardmarket'] ?? null;
        if (!is_array($cm)) {
            return;
        }

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO card_prices
                (card_id, currency, low, avg, trend, avg7, avg30,
                 low_holo, avg_holo, trend_holo, avg7_holo, avg30_holo,
                 source, cm_updated, fetched_at)
            VALUES
                (:card_id, :currency, :low, :avg, :trend, :avg7, :avg30,
                 :low_holo, :avg_holo, :trend_holo, :avg7_holo, :avg30_holo,
                 'cardmarket', :cm_updated, :fetched_at)
            ON CONFLICT(card_id) DO UPDATE SET
                currency = excluded.currency,
                low = excluded.low, avg = excluded.avg, trend = excluded.trend,
                avg7 = excluded.avg7, avg30 = excluded.avg30,
                low_holo = excluded.low_holo, avg_holo = excluded.avg_holo,
                trend_holo = excluded.trend_holo, avg7_holo = excluded.avg7_holo,
                avg30_holo = excluded.avg30_holo,
                cm_updated = excluded.cm_updated, fetched_at = excluded.fetched_at
        SQL);

        $stmt->execute([
            ':card_id'    => $card['id'],
            ':currency'   => $cm['unit'] ?? Config::CURRENCY,
            ':low'        => $cm['low'] ?? null,
            ':avg'        => $cm['avg'] ?? null,
            ':trend'      => $cm['trend'] ?? null,
            ':avg7'       => $cm['avg7'] ?? null,
            ':avg30'      => $cm['avg30'] ?? null,
            ':low_holo'   => $cm['low-holo'] ?? null,
            ':avg_holo'   => $cm['avg-holo'] ?? null,
            ':trend_holo' => $cm['trend-holo'] ?? null,
            ':avg7_holo'  => $cm['avg7-holo'] ?? null,
            ':avg30_holo' => $cm['avg30-holo'] ?? null,
            ':cm_updated' => $cm['updated'] ?? null,
            ':fetched_at' => $now,
        ]);
    }

    // -------------------------------------------------------- Sammlung (CRUD)

    /**
     * Fuegt eine Karte zur Sammlung hinzu (oder erhoeht die Menge).
     *
     * @return array<string,mixed>
     */
    public function addItem(
        string $cardId,
        string $catalogLang = 'de',
        string $variant = 'normal',
        string $condition = 'NM',
        string $language = 'de',
        int $quantity = 1,
        ?string $notes = null
    ): array {
        $this->requireUser();
        $catalogLang = self::normLang($catalogLang);
        $variant   = in_array($variant, self::VARIANTS, true) ? $variant : 'normal';
        $condition = in_array($condition, self::CONDITIONS, true) ? $condition : 'NM';
        $quantity  = max(1, $quantity);

        $card = $this->getCardCached($cardId, $catalogLang);
        if ($card === null) {
            throw new RuntimeException('Karte nicht gefunden: ' . $cardId);
        }

        $now = time();
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO collection_items
                (user_id, card_id, catalog_lang, variant, condition, language, quantity, notes, created_at, updated_at)
            VALUES (:uid, :card_id, :clang, :variant, :condition, :language, :quantity, :notes, :now, :now)
            ON CONFLICT(user_id, card_id, catalog_lang, variant, condition, language) DO UPDATE SET
                quantity = quantity + excluded.quantity,
                notes = COALESCE(excluded.notes, notes),
                updated_at = excluded.updated_at
        SQL);
        $stmt->execute([
            ':uid'       => $this->userId,
            ':card_id'   => $cardId,
            ':clang'     => $catalogLang,
            ':variant'   => $variant,
            ':condition' => $condition,
            ':language'  => $language,
            ':quantity'  => $quantity,
            ':notes'     => $notes,
            ':now'       => $now,
        ]);

        $idStmt = $this->db->prepare(<<<'SQL'
            SELECT id FROM collection_items
            WHERE user_id = :uid AND card_id = :cid AND catalog_lang = :clang AND variant = :v
              AND condition = :c AND language = :l
        SQL);
        $idStmt->execute([':uid' => $this->userId, ':cid' => $cardId, ':clang' => $catalogLang, ':v' => $variant, ':c' => $condition, ':l' => $language]);
        $id = (int) $idStmt->fetchColumn();

        // Preis direkt bereitstellen, damit die Sammlung sofort einen Wert
        // zeigt (DE: Cardmarket via storeCard; JA: TCGplayer via getJpPrices).
        $this->getPrices([$cardId], $catalogLang);

        return $this->getItem($id);
    }

    /**
     * @return array<string,mixed>
     */
    public function updateItem(int $id, array $fields): array
    {
        $allowed = [];
        if (isset($fields['quantity'])) {
            $qty = (int) $fields['quantity'];
            if ($qty <= 0) {
                $this->deleteItem($id);
                return ['deleted' => true, 'id' => $id];
            }
            $allowed['quantity'] = $qty;
        }
        if (isset($fields['condition']) && in_array($fields['condition'], self::CONDITIONS, true)) {
            $allowed['condition'] = $fields['condition'];
        }
        if (isset($fields['variant']) && in_array($fields['variant'], self::VARIANTS, true)) {
            $allowed['variant'] = $fields['variant'];
        }
        if (array_key_exists('notes', $fields)) {
            $allowed['notes'] = $fields['notes'];
        }

        if ($allowed === []) {
            return $this->getItem($id);
        }

        $set = [];
        $params = [':id' => $id, ':now' => time()];
        foreach ($allowed as $col => $val) {
            $set[] = "$col = :$col";
            $params[":$col"] = $val;
        }
        $set[] = 'updated_at = :now';
        $params[':uid'] = $this->requireUser();
        $sql = 'UPDATE collection_items SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :uid';
        $this->db->prepare($sql)->execute($params);

        return $this->getItem($id);
    }

    public function deleteItem(int $id): void
    {
        $this->db->prepare('DELETE FROM collection_items WHERE id = ? AND user_id = ?')
            ->execute([$id, $this->requireUser()]);
    }

    /**
     * @return array<string,mixed>
     */
    public function getItem(int $id): array
    {
        $rows = $this->fetchCollectionRows('WHERE ci.id = ? AND ci.user_id = ?', [$id, $this->requireUser()]);
        if ($rows === []) {
            throw new RuntimeException('Sammlungseintrag nicht gefunden: ' . $id);
        }
        return $rows[0];
    }

    /** Stellt sicher, dass ein Benutzer gesetzt ist, und liefert dessen ID. */
    private function requireUser(): int
    {
        if ($this->userId === null) {
            throw new RuntimeException('Für diese Aktion ist eine Anmeldung erforderlich.');
        }
        return $this->userId;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getCollection(?string $setId = null, ?string $q = null, string $sort = 'set'): array
    {
        // Ohne Benutzer (Gast) ist die serverseitige Sammlung leer.
        if ($this->userId === null) {
            return [];
        }
        $where = ['ci.user_id = ?'];
        $params = [$this->userId];
        if ($setId !== null && $setId !== '') {
            $where[] = 'c.set_id = ?';
            $params[] = $setId;
        }
        if ($q !== null && $q !== '') {
            $where[] = 'c.name LIKE ?';
            $params[] = '%' . $q . '%';
        }
        $clause = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));
        $rows = $this->fetchCollectionRows($clause, $params);
        return self::sortCollection($rows, $sort);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function sortCollection(array $rows, string $sort): array
    {
        switch ($sort) {
            case 'value':
                usort($rows, static fn($a, $b) => ($b['lineValue'] ?? 0) <=> ($a['lineValue'] ?? 0));
                break;
            case 'value_asc':
                usort($rows, static fn($a, $b) => ($a['lineValue'] ?? 0) <=> ($b['lineValue'] ?? 0));
                break;
            case 'name':
                usort($rows, static fn($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
                break;
            case 'recent':
                usort($rows, static fn($a, $b) => ($b['addedAt'] ?? 0) <=> ($a['addedAt'] ?? 0));
                break;
            case 'set':
            default:
                // bereits nach Set/Nummer sortiert.
                break;
        }
        return $rows;
    }

    /**
     * Exportiert die gesamte Sammlung als CSV-String (UTF-8 inkl. BOM, damit
     * Excel die Umlaute/Kanji korrekt anzeigt).
     */
    public function exportCsv(): string
    {
        $items = $this->getCollection(null, null, 'set');
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['Name', 'Set', 'Nummer', 'Sprache', 'Variante', 'Zustand', 'Menge', 'Einzelpreis', 'Gesamtwert', 'Waehrung']);
        foreach ($items as $it) {
            fputcsv($fh, [
                $it['name'],
                $it['setName'],
                $it['localId'],
                $it['language'],
                $it['variant'],
                $it['condition'],
                $it['quantity'],
                $it['unitPrice'],
                $it['lineValue'],
                $it['currency'],
            ]);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function fetchCollectionRows(string $clause, array $params): array
    {
        $sql = <<<SQL
            SELECT
                ci.id, ci.card_id, ci.catalog_lang, ci.variant, ci.condition, ci.language,
                ci.quantity, ci.notes, ci.created_at, ci.updated_at,
                c.name, c.local_id, c.set_id, c.set_name, c.set_total,
                c.rarity, c.image, c.variants,
                cx.image AS idx_image,
                p.currency, p.low, p.avg, p.trend, p.avg7, p.avg30,
                p.low_holo, p.avg_holo, p.trend_holo, p.fetched_at, p.cm_updated, p.source,
                o.price AS override_price
            FROM collection_items ci
            JOIN cards c ON c.id = ci.card_id
            LEFT JOIN card_prices p ON p.card_id = ci.card_id
            LEFT JOIN card_index cx ON cx.card_id = ci.card_id AND cx.lang = ci.catalog_lang
            LEFT JOIN price_overrides o ON o.card_id = ci.card_id
            $clause
            ORDER BY c.set_name, CAST(c.local_id AS INTEGER), c.name
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $variantsMeta = json_decode((string) ($r['variants'] ?? '[]'), true);
            $hasNormal = is_array($variantsMeta) && !empty($variantsMeta['normal']);
            // Manuelle Korrektur uebersteuert den Quellpreis.
            $manual = isset($r['override_price']) && $r['override_price'] !== null;
            $unit = $manual ? (float) $r['override_price'] : $this->priceForVariant($r, $r['variant'], $hasNormal);
            $qty  = (int) $r['quantity'];
            $imgBase = !empty($r['image']) ? $r['image'] : ($r['idx_image'] ?? null);
            $clang = $r['catalog_lang'] ?? 'de';
            $alt = self::altNames($clang, (string) $r['name']);
            $out[] = [
                'id'        => (int) $r['id'],
                'cardId'    => $r['card_id'],
                'lang'      => $clang,
                'name'      => $r['name'],
                'nameDe'    => $alt['de'],
                'nameEn'    => $alt['en'],
                'nameAlt'   => $alt['alt'],
                'localId'   => $r['local_id'],
                'setId'     => $r['set_id'],
                'setName'   => $r['set_name'],
                'setTotal'  => $r['set_total'] !== null ? (int) $r['set_total'] : null,
                'rarity'    => $r['rarity'],
                'image'     => TcgdexClient::imageUrl($imgBase, 'low'),
                'imageHigh' => TcgdexClient::imageUrl($imgBase, 'high'),
                'variant'   => $r['variant'],
                'condition' => $r['condition'],
                'language'  => $r['language'],
                'quantity'  => $qty,
                'notes'     => $r['notes'],
                'addedAt'   => $r['updated_at'] !== null ? (int) $r['updated_at'] : null,
                'currency'  => $r['currency'] ?? Config::CURRENCY,
                'unitPrice' => $unit,
                'lineValue' => $unit !== null ? round($unit * $qty, 2) : null,
                'priceManual' => $manual,
                'priceSource' => $r['source'] ?? null,
                'priceFetchedAt' => $r['fetched_at'] !== null ? (int) $r['fetched_at'] : null,
                'cmUpdated' => $r['cm_updated'],
                'prices'    => [
                    'low'   => $r['low'] !== null ? (float) $r['low'] : null,
                    'trend' => $r['trend'] !== null ? (float) $r['trend'] : null,
                    'avg'   => $r['avg'] !== null ? (float) $r['avg'] : null,
                    'avg7'  => $r['avg7'] !== null ? (float) $r['avg7'] : null,
                    'avg30' => $r['avg30'] !== null ? (float) $r['avg30'] : null,
                    'lowHolo'   => $r['low_holo'] !== null ? (float) $r['low_holo'] : null,
                    'trendHolo' => $r['trend_holo'] !== null ? (float) $r['trend_holo'] : null,
                    'avgHolo'   => $r['avg_holo'] !== null ? (float) $r['avg_holo'] : null,
                ],
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $r
     */
    private function priceForVariant(array $r, string $variant, bool $hasNormal): ?float
    {
        $plain = ['trend', 'avg', 'low'];
        $holo  = ['trend_holo', 'avg_holo', 'low_holo'];

        if ($variant === 'normal') {
            $order = [$plain, $holo];
        } elseif ($hasNormal) {
            $order = [$holo, $plain];
        } else {
            $order = [$plain, $holo];
        }

        foreach ($order as $group) {
            foreach ($group as $k) {
                if ($r[$k] !== null) {
                    return (float) $r[$k];
                }
            }
        }
        return null;
    }

    // --------------------------------------------------------- Preise-Refresh

    /**
     * Aktualisiert Cardmarket-Preise fuer alle Karten der Sammlung.
     *
     * @return array{updated:int,skipped:int,failed:int}
     */
    public function refreshPrices(bool $onlyStale = true): array
    {
        $rows = $this->db->prepare(
            'SELECT DISTINCT card_id, catalog_lang FROM collection_items WHERE user_id = ?'
        );
        $rows->execute([$this->requireUser()]);
        $rows = $rows->fetchAll(PDO::FETCH_ASSOC);

        $threshold = time() - Config::PRICE_TTL;
        $updated = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($rows as $row) {
            $cardId = (string) $row['card_id'];
            $lang = self::normLang((string) ($row['catalog_lang'] ?? 'de'));
            if ($onlyStale) {
                $stmt = $this->db->prepare('SELECT fetched_at, source FROM card_prices WHERE card_id = ?');
                $stmt->execute([$cardId]);
                $existing = $stmt->fetch() ?: null;
                $fresh = $existing !== null && (int) ($existing['fetched_at'] ?? 0) > $threshold;
                // JA-Karten mit noch alter (falscher) Cardmarket-Quelle trotz
                // Frische einmalig auf TCGplayer migrieren.
                $needsJpMigration = $lang === 'ja'
                    && ($existing['source'] ?? null) !== Config::JP_PRICE_SOURCE;
                if ($fresh && !$needsJpMigration) {
                    $skipped++;
                    continue;
                }
            }
            try {
                if ($lang === 'ja') {
                    // JA-Preise via TCGplayer (USD -> EUR) erzwingen.
                    $this->getJpPrices([$cardId], true);
                    $updated++;
                    continue;
                }
                $card = $this->client($lang)->getCard($cardId);
                if ($card === null) {
                    $failed++;
                    continue;
                }
                $this->storeCard($card, $lang);
                $updated++;
            } catch (Throwable $e) {
                $failed++;
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed];
    }

    // ---------------------------------------------------- Preise on-demand

    /**
     * Liefert Cardmarket-Preise fuer beliebige Karten (nicht nur Sammlung).
     * Fehlende oder veraltete Preise werden einmalig von TCGdex geholt und
     * lokal gecacht; beim naechsten Mal kommen sie sofort aus der DB.
     *
     * @param array<int,string> $ids
     * @return array<string,array<string,mixed>|null> Map cardId => Preis|null
     */
    public function getPrices(array $ids, string $lang = 'de'): array
    {
        $lang = self::normLang($lang);
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($x) => trim((string) $x), $ids),
            static fn($x) => $x !== ''
        )));
        if ($ids === []) {
            return [];
        }

        // Japanische Karten: Cardmarket/TCGdex liefert hier falsche Werte,
        // daher eigener Pfad ueber TCGplayer (USD -> EUR).
        if ($lang === 'ja') {
            return $this->getJpPrices($ids);
        }

        $now = time();
        $threshold = $now - Config::PRICE_TTL;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM card_prices WHERE card_id IN ($placeholders)");
        $stmt->execute($ids);
        $have = [];
        foreach ($stmt->fetchAll() as $r) {
            $have[(string) $r['card_id']] = $r;
        }

        // Fehlende/veraltete Karten bestimmen und ALLE parallel nachladen.
        $toFetch = [];
        foreach ($ids as $id) {
            $row = $have[$id] ?? null;
            if ($row === null || (int) ($row['fetched_at'] ?? 0) <= $threshold) {
                $toFetch[] = $id;
            }
        }

        if ($toFetch !== []) {
            try {
                $cards = $this->client($lang)->getCards($toFetch);
                foreach ($cards as $card) {
                    $this->storeCard($card, $lang);
                }
                // Auch ohne Cardmarket-Preis als "geprueft" markieren, damit
                // nicht bei jeder Ansicht erneut abgefragt wird.
                foreach ($toFetch as $id) {
                    $this->touchPrice($id, $now);
                }
                // Frisch gespeicherte Preise nachladen.
                $ph = implode(',', array_fill(0, count($toFetch), '?'));
                $reload = $this->db->prepare("SELECT * FROM card_prices WHERE card_id IN ($ph)");
                $reload->execute($toFetch);
                foreach ($reload->fetchAll() as $r) {
                    $have[(string) $r['card_id']] = $r;
                }
            } catch (Throwable $e) {
                // Netzwerkfehler -> vorhandene (ggf. alte) Werte behalten.
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->formatPriceRow($have[$id] ?? null);
        }
        return $out;
    }

    /**
     * Preis-Pfad fuer japanische Karten: holt TCGplayer-Marktpreise (USD) via
     * TCGcsv, rechnet sie in EUR um und cacht sie in card_prices (source
     * 'tcgplayer-jp'). Set-Zuordnung ueber TCGdex set.id == TCGplayer-Abk.,
     * Karten-Zuordnung ueber die Kartennummer.
     *
     * @param array<int,string> $ids
     * @return array<string,array<string,mixed>|null>
     */
    private function getJpPrices(array $ids, bool $force = false): array
    {
        $now = time();
        $threshold = $now - Config::PRICE_TTL;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM card_prices WHERE card_id IN ($placeholders)");
        $stmt->execute($ids);
        $have = [];
        foreach ($stmt->fetchAll() as $r) {
            $have[(string) $r['card_id']] = $r;
        }

        $toFetch = [];
        foreach ($ids as $id) {
            $row = $have[$id] ?? null;
            $stale = $row === null || (int) ($row['fetched_at'] ?? 0) <= $threshold;
            // Noch nicht auf TCGplayer migrierte (alte Cardmarket-)Zeilen
            // ebenfalls neu holen, auch wenn sie zeitlich "frisch" sind.
            $needsMigration = $row !== null && ($row['source'] ?? null) !== Config::JP_PRICE_SOURCE;
            if ($force || $stale || $needsMigration) {
                $toFetch[] = $id;
            }
        }

        if ($toFetch !== []) {
            // Stammdaten (set_id + local_id) bereitstellen.
            $meta = $this->cardMeta($toFetch);
            $missingMeta = array_values(array_filter($toFetch, static fn($id) => !isset($meta[$id])));
            if ($missingMeta !== []) {
                try {
                    $cards = $this->client('ja')->getCards($missingMeta);
                    foreach ($cards as $card) {
                        $this->storeCard($card, 'ja');
                    }
                } catch (Throwable $e) {
                    // ignorieren -> diese Karten bleiben ohne Preis
                }
                $meta = $this->cardMeta($toFetch);
            }

            // Nach Set gruppieren, je Set einmal Preise holen.
            $bySet = [];
            foreach ($toFetch as $id) {
                $setId = $meta[$id]['set_id'] ?? null;
                if ($setId !== null && $setId !== '') {
                    $bySet[$setId][] = $id;
                }
            }

            $rate = $this->usdToEur();
            foreach ($bySet as $setId => $setIds) {
                $priceMap = null;
                $fetchFailed = false;
                try {
                    $groupId = $this->jpClient()->resolveGroupId((string) $setId);
                    if ($groupId !== null) {
                        $priceMap = $this->jpClient()->setPrices($groupId);
                    }
                } catch (Throwable $e) {
                    // Netzwerk-/Parsefehler: bestehende Preise NICHT ueberschreiben.
                    $fetchFailed = true;
                }
                if ($fetchFailed) {
                    continue;
                }

                foreach ($setIds as $id) {
                    $localId = (string) ($meta[$id]['local_id'] ?? '');
                    $num = TcgPlayerJpClient::normalizeNumber($localId);
                    $entry = ($priceMap !== null && $num !== '') ? ($priceMap[$num] ?? null) : null;
                    if ($entry === null) {
                        // Keine Zuordnung -> als geprueft (TCGplayer) markieren,
                        // aber ohne Preis, damit nicht bei jeder Ansicht erneut
                        // versucht wird.
                        $entry = ['plain' => null, 'plainLow' => null, 'holo' => null, 'holoLow' => null];
                    }
                    $this->storeJpPrice($id, $entry, $rate, $now);
                }
            }

            // Frisch gespeicherte Preise nachladen.
            $ph = implode(',', array_fill(0, count($toFetch), '?'));
            $reload = $this->db->prepare("SELECT * FROM card_prices WHERE card_id IN ($ph)");
            $reload->execute($toFetch);
            foreach ($reload->fetchAll() as $r) {
                $have[(string) $r['card_id']] = $r;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->formatPriceRow($have[$id] ?? null);
        }
        return $out;
    }

    /**
     * Liest set_id + local_id der angegebenen Karten aus dem lokalen Cache.
     *
     * @param array<int,string> $ids
     * @return array<string,array{set_id:?string,local_id:?string}>
     */
    private function cardMeta(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, set_id, local_id FROM cards WHERE id IN ($ph)");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['id']] = [
                'set_id'   => $r['set_id'] ?? null,
                'local_id' => $r['local_id'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Schreibt einen umgerechneten TCGplayer-JP-Preis in card_prices.
     *
     * @param array{plain:?float,plainLow:?float,holo:?float,holoLow:?float} $entry
     */
    private function storeJpPrice(string $cardId, array $entry, float $rate, int $now): void
    {
        $eur = static fn(?float $usd) => $usd !== null ? round($usd * $rate, 2) : null;

        $this->db->prepare(<<<'SQL'
            INSERT INTO card_prices
                (card_id, currency, low, avg, trend, avg7, avg30,
                 low_holo, avg_holo, trend_holo, avg7_holo, avg30_holo,
                 source, cm_updated, fetched_at)
            VALUES
                (:card_id, 'EUR', :low, :avg, :trend, NULL, NULL,
                 :low_holo, :avg_holo, :trend_holo, NULL, NULL,
                 :source, NULL, :fetched_at)
            ON CONFLICT(card_id) DO UPDATE SET
                currency = 'EUR',
                low = excluded.low, avg = excluded.avg, trend = excluded.trend,
                avg7 = NULL, avg30 = NULL,
                low_holo = excluded.low_holo, avg_holo = excluded.avg_holo,
                trend_holo = excluded.trend_holo, avg7_holo = NULL, avg30_holo = NULL,
                source = excluded.source, cm_updated = NULL,
                fetched_at = excluded.fetched_at
        SQL)->execute([
            ':card_id'    => $cardId,
            ':low'        => $eur($entry['plainLow']),
            ':avg'        => $eur($entry['plain']),
            ':trend'      => $eur($entry['plain']),
            ':low_holo'   => $eur($entry['holoLow']),
            ':avg_holo'   => $eur($entry['holo']),
            ':trend_holo' => $eur($entry['holo']),
            ':source'     => Config::JP_PRICE_SOURCE,
            ':fetched_at' => $now,
        ]);
    }

    private function touchPrice(string $id, int $now): void
    {
        $this->db->prepare(<<<'SQL'
            INSERT INTO card_prices (card_id, currency, fetched_at)
            VALUES (:id, 'EUR', :now)
            ON CONFLICT(card_id) DO UPDATE SET fetched_at = excluded.fetched_at
        SQL)->execute([':id' => $id, ':now' => $now]);
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    private function formatPriceRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $trend = $row['trend'] ?? $row['trend_holo'] ?? $row['avg'] ?? $row['low'] ?? null;
        if ($trend === null) {
            return null;
        }
        return [
            'trend'     => (float) $trend,
            'avg'       => $row['avg'] !== null ? (float) $row['avg'] : null,
            'low'       => $row['low'] !== null ? (float) $row['low'] : null,
            'trendHolo' => $row['trend_holo'] !== null ? (float) $row['trend_holo'] : null,
            'currency'  => $row['currency'] ?? Config::CURRENCY,
            'source'    => $row['source'] ?? null,
        ];
    }

    // -------------------------------------------------------------- Statistik

    /**
     * @return array<string,mixed>
     */
    public function getStats(): array
    {
        $items = $this->getCollection();

        $totalQty   = 0;
        $totalValue = 0.0;
        $uniqueCards = [];
        $bySet = [];
        foreach ($items as $it) {
            $totalQty += $it['quantity'];
            $uniqueCards[$it['cardId']] = true;
            if ($it['lineValue'] !== null) {
                $totalValue += $it['lineValue'];
            }
            $setName = $it['setName'] ?? 'Unbekannt';
            if (!isset($bySet[$setName])) {
                $bySet[$setName] = ['set' => $setName, 'count' => 0, 'value' => 0.0];
            }
            $bySet[$setName]['count'] += $it['quantity'];
            $bySet[$setName]['value'] += $it['lineValue'] ?? 0.0;
        }

        usort($items, static fn($a, $b) => ($b['unitPrice'] ?? 0) <=> ($a['unitPrice'] ?? 0));
        $top = array_slice(array_values(array_filter(
            $items,
            static fn($i) => $i['unitPrice'] !== null
        )), 0, 10);

        $bySetList = array_values($bySet);
        usort($bySetList, static fn($a, $b) => $b['value'] <=> $a['value']);
        foreach ($bySetList as &$s) {
            $s['value'] = round($s['value'], 2);
        }
        unset($s);

        return [
            'totalQuantity'   => $totalQty,
            'uniqueCards'     => count($uniqueCards),
            'totalValue'      => round($totalValue, 2),
            'currency'        => Config::CURRENCY,
            'bySet'           => $bySetList,
            'topCards'        => $top,
            'distinctEntries' => count($items),
        ];
    }
}
