<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * Client fuer TCGcsv (https://tcgcsv.com) - ein kostenloser, schluesselloser
 * Spiegel der TCGplayer-Daten. Wir nutzen ausschliesslich die Kategorie
 * "Pokemon Japan" (ID 85), um fuer japanische Karten echte Marktpreise (USD)
 * zu bekommen. Cardmarket/TCGdex liefert fuer JA-Karten unbrauchbare Preise.
 *
 * Zuordnung zu unseren TCGdex-Karten:
 *   - Set:  TCGdex set.id (z. B. "SV2a")  ==  TCGcsv group.abbreviation
 *   - Karte: TCGdex localId (z. B. "201") ==  Nummern-Praefix des Produkts
 *            (extendedData "Number", z. B. "201/165")
 *
 * Preise werden pro Group (= Set) gebuendelt geholt und im Request gecacht,
 * sodass das Nachladen vieler Karten eines Sets nur einen Roundtrip kostet.
 */
final class TcgPlayerJpClient
{
    private string $base;
    private int $category;

    /** @var array<string,int>|null Map abbreviation(lowercase) => groupId */
    private ?array $groupMap = null;

    /** @var array<int,array<string,array{plain:?float,plainLow:?float,holo:?float,holoLow:?float}>> Cache je groupId */
    private array $setCache = [];

    public function __construct()
    {
        $this->base = rtrim(Config::TCGCSV_BASE, '/');
        $this->category = Config::TCGCSV_JP_CATEGORY;
    }

    /**
     * Loest eine TCGdex-Set-ID (= Abkuerzung) in die TCGplayer-groupId auf.
     */
    public function resolveGroupId(string $setAbbr): ?int
    {
        $setAbbr = strtolower(trim($setAbbr));
        if ($setAbbr === '') {
            return null;
        }
        $this->loadGroups();
        return $this->groupMap[$setAbbr] ?? null;
    }

    /**
     * Liefert die USD-Marktpreise aller Karten eines Sets, indexiert nach der
     * normalisierten Kartennummer.
     *
     * @return array<string,array{plain:?float,plainLow:?float,holo:?float,holoLow:?float}>
     */
    public function setPrices(int $groupId): array
    {
        if (isset($this->setCache[$groupId])) {
            return $this->setCache[$groupId];
        }

        $products = $this->getJson(sprintf('%s/%d/%d/products', $this->base, $this->category, $groupId));
        $prices   = $this->getJson(sprintf('%s/%d/%d/prices', $this->base, $this->category, $groupId));

        // productId -> normalisierte Kartennummer
        $numByProduct = [];
        foreach (($products['results'] ?? []) as $p) {
            $pid = isset($p['productId']) ? (int) $p['productId'] : 0;
            if ($pid === 0) {
                continue;
            }
            $number = null;
            foreach (($p['extendedData'] ?? []) as $ext) {
                if (($ext['name'] ?? '') === 'Number') {
                    $number = (string) ($ext['value'] ?? '');
                    break;
                }
            }
            if ($number === null || $number === '') {
                continue;
            }
            $numByProduct[$pid] = self::normalizeNumber($number);
        }

        $out = [];
        foreach (($prices['results'] ?? []) as $row) {
            $pid = isset($row['productId']) ? (int) $row['productId'] : 0;
            if ($pid === 0 || !isset($numByProduct[$pid])) {
                continue;
            }
            $num = $numByProduct[$pid];
            $market = isset($row['marketPrice']) && $row['marketPrice'] !== null ? (float) $row['marketPrice'] : null;
            $low    = isset($row['lowPrice']) && $row['lowPrice'] !== null ? (float) $row['lowPrice'] : null;
            if ($market === null && $low === null) {
                continue;
            }
            $sub = (string) ($row['subTypeName'] ?? '');

            $entry = $out[$num] ?? ['plain' => null, 'plainLow' => null, 'holo' => null, 'holoLow' => null];
            if ($sub === 'Normal') {
                $entry['plain']    = $market ?? $entry['plain'];
                $entry['plainLow'] = $low ?? $entry['plainLow'];
            } elseif ($sub === 'Holofoil') {
                $entry['holo']    = $market ?? $entry['holo'];
                $entry['holoLow'] = $low ?? $entry['holoLow'];
            } else {
                // "Reverse Holofoil" o. ae. -> als Holo werten, falls noch leer.
                if ($entry['holo'] === null) {
                    $entry['holo']    = $market;
                    $entry['holoLow'] = $low;
                }
            }
            $out[$num] = $entry;
        }

        return $this->setCache[$groupId] = $out;
    }

    /**
     * Normalisiert eine Kartennummer fuer den Abgleich zwischen TCGplayer
     * ("201/165") und TCGdex (localId "201"): Praefix vor "/", gross, und bei
     * reinen Ziffern fuehrende Nullen entfernen.
     */
    public static function normalizeNumber(string $n): string
    {
        $n = trim($n);
        $slash = strpos($n, '/');
        if ($slash !== false) {
            $n = substr($n, 0, $slash);
        }
        $n = strtoupper(trim($n));
        if ($n !== '' && ctype_digit($n)) {
            $n = ltrim($n, '0');
            if ($n === '') {
                $n = '0';
            }
        }
        return $n;
    }

    /** Laedt die Group-Liste der Kategorie und baut die Abkuerzungs-Map. */
    private function loadGroups(): void
    {
        if ($this->groupMap !== null) {
            return;
        }
        $this->groupMap = [];
        $data = $this->getJson(sprintf('%s/%d/groups', $this->base, $this->category));
        foreach (($data['results'] ?? []) as $g) {
            $abbr = strtolower(trim((string) ($g['abbreviation'] ?? '')));
            $gid  = isset($g['groupId']) ? (int) $g['groupId'] : 0;
            if ($abbr !== '' && $gid !== 0) {
                $this->groupMap[$abbr] = $gid;
            }
        }
    }

    /**
     * GET + JSON-Dekodierung. Gibt bei Fehlern ein leeres Array zurueck,
     * damit der Aufrufer (Preis-Nachladen) robust weiterlaeuft.
     *
     * @return array<string,mixed>
     */
    private function getJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: Pokelog/1.0 (+self-hosted collection tracker)',
            ],
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300 || !is_string($body) || $body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
