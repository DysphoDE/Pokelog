<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * Client fuer die kostenlose, quelloffene TCGdex-API.
 *
 * Liefert deutschsprachige Kartenstammdaten, Bilder UND die in der Antwort
 * eingebetteten Cardmarket-Preise (EUR). Damit umgehen wir die fehlende
 * offene Cardmarket-API: Wir holen die Preise nur fuer Karten, die wirklich
 * in der Sammlung landen, und speichern sie lokal.
 */
final class TcgdexClient
{
    private string $base;
    private string $lang;

    public function __construct(?string $lang = null)
    {
        $this->base = rtrim(Config::TCGDEX_BASE, '/');
        $this->lang = $lang ?? Config::LANG;
    }

    /**
     * Schnelle Namenssuche. Gibt eine Liste knapper Kartenobjekte zurueck
     * (id, localId, name, image).
     *
     * @return array<int,array<string,mixed>>
     */
    public function searchByName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }
        $url = sprintf(
            '%s/%s/cards?name=%s',
            $this->base,
            $this->lang,
            rawurlencode('like:' . $name)
        );
        $result = $this->getJson($url);
        return is_array($result) ? $result : [];
    }

    /**
     * Holt das vollstaendige Kartenobjekt inkl. pricing.cardmarket.
     *
     * @return array<string,mixed>|null
     */
    public function getCard(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        $url = sprintf('%s/%s/cards/%s', $this->base, $this->lang, rawurlencode($id));
        $result = $this->getJson($url);
        if (!is_array($result) || !isset($result['id'])) {
            return null;
        }
        return $result;
    }

    /**
     * Holt mehrere Karten gleichzeitig (parallele HTTP-Requests via curl_multi).
     * Deutlich schneller als viele Einzelabrufe, ideal fuer Preis-Nachladen.
     *
     * @param array<int,string> $ids
     * @return array<string,array<string,mixed>> Map id => Kartenobjekt
     */
    public function getCards(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($x) => trim((string) $x), $ids),
            static fn($x) => $x !== ''
        )));
        if ($ids === []) {
            return [];
        }

        $mh = curl_multi_init();
        $handles = [];
        foreach ($ids as $id) {
            $url = sprintf('%s/%s/cards/%s', $this->base, $this->lang, rawurlencode($id));
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'User-Agent: Pokelog/1.0 (+self-hosted collection tracker)',
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $id => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                $d = json_decode((string) $body, true);
                if (is_array($d) && isset($d['id'])) {
                    $out[(string) $id] = $d;
                }
            }
        }
        curl_multi_close($mh);
        return $out;
    }

    /**
     * Liste aller Sets (knapp: id, name, cardCount).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listSets(): array
    {
        $url = sprintf('%s/%s/sets', $this->base, $this->lang);
        $result = $this->getJson($url);
        return is_array($result) ? $result : [];
    }

    /**
     * Vollstaendiges Set-Objekt inkl. abbreviation, tcgOnline und Kartenliste.
     *
     * @return array<string,mixed>|null
     */
    public function getSet(string $id): ?array
    {
        $url = sprintf('%s/%s/sets/%s', $this->base, $this->lang, rawurlencode($id));
        $result = $this->getJson($url);
        if (!is_array($result) || !isset($result['id'])) {
            return null;
        }
        return $result;
    }

    /**
     * Baut aus dem Basis-Image-Feld eine konkrete Bild-URL.
     * TCGdex liefert das Feld ohne Endung: {image}/{quality}.{ext}
     */
    public static function imageUrl(?string $base, string $quality = 'high', string $ext = 'webp'): ?string
    {
        if ($base === null || $base === '') {
            return null;
        }
        return $base . '/' . $quality . '.' . $ext;
    }

    /**
     * Fuehrt eine GET-Anfrage aus und dekodiert die JSON-Antwort.
     *
     * @return mixed
     */
    private function getJson(string $url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: Pokelog/1.0 (+self-hosted collection tracker)',
            ],
        ]);

        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('Netzwerkfehler bei TCGdex: ' . $error);
        }
        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('TCGdex antwortete mit HTTP ' . $status);
        }

        $decoded = json_decode((string) $body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Ungueltige JSON-Antwort von TCGdex');
        }
        return $decoded;
    }
}
