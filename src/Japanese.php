<?php

declare(strict_types=1);

/**
 * Hilfen fuer japanische Karten:
 *  - romaji(): Transliteration von Kana (Hiragana/Katakana) nach lateinischer
 *    Schrift, damit japanische Set-/Kartennamen lesbar werden.
 *  - translate(): liefert den deutschen/englischen Pokémon-Namen zu einem
 *    japanischen Kartennamen ueber die National-Dex-Namenstabelle
 *    (src/dex_names.json, einmalig von der PokéAPI gebaut).
 */
final class Japanese
{
    /** @var array<string,array{de?:string,en?:string}>|null exakte ja->{de,en} */
    private static ?array $exact = null;
    /** @var array<int,array{name:string,de:?string,en:?string}>|null laengen-sortiert */
    private static ?array $byLen = null;
    /** @var array<string,string>|null kuratierte JA-Setnamen -> echter EN-Titel */
    private static ?array $setEn = null;

    /** Katakana-Basissilben -> Romaji. */
    private const KATA = [
        'ア' => 'a', 'イ' => 'i', 'ウ' => 'u', 'エ' => 'e', 'オ' => 'o',
        'カ' => 'ka', 'キ' => 'ki', 'ク' => 'ku', 'ケ' => 'ke', 'コ' => 'ko',
        'サ' => 'sa', 'シ' => 'shi', 'ス' => 'su', 'セ' => 'se', 'ソ' => 'so',
        'タ' => 'ta', 'チ' => 'chi', 'ツ' => 'tsu', 'テ' => 'te', 'ト' => 'to',
        'ナ' => 'na', 'ニ' => 'ni', 'ヌ' => 'nu', 'ネ' => 'ne', 'ノ' => 'no',
        'ハ' => 'ha', 'ヒ' => 'hi', 'フ' => 'fu', 'ヘ' => 'he', 'ホ' => 'ho',
        'マ' => 'ma', 'ミ' => 'mi', 'ム' => 'mu', 'メ' => 'me', 'モ' => 'mo',
        'ヤ' => 'ya', 'ユ' => 'yu', 'ヨ' => 'yo',
        'ラ' => 'ra', 'リ' => 'ri', 'ル' => 'ru', 'レ' => 're', 'ロ' => 'ro',
        'ワ' => 'wa', 'ヰ' => 'wi', 'ヱ' => 'we', 'ヲ' => 'wo', 'ン' => 'n',
        'ガ' => 'ga', 'ギ' => 'gi', 'グ' => 'gu', 'ゲ' => 'ge', 'ゴ' => 'go',
        'ザ' => 'za', 'ジ' => 'ji', 'ズ' => 'zu', 'ゼ' => 'ze', 'ゾ' => 'zo',
        'ダ' => 'da', 'ヂ' => 'ji', 'ヅ' => 'zu', 'デ' => 'de', 'ド' => 'do',
        'バ' => 'ba', 'ビ' => 'bi', 'ブ' => 'bu', 'ベ' => 'be', 'ボ' => 'bo',
        'パ' => 'pa', 'ピ' => 'pi', 'プ' => 'pu', 'ペ' => 'pe', 'ポ' => 'po',
        'ヴ' => 'vu',
    ];

    private const SMALL_Y = ['ャ' => 'a', 'ュ' => 'u', 'ョ' => 'o'];
    private const SMALL_V = ['ァ' => 'a', 'ィ' => 'i', 'ゥ' => 'u', 'ェ' => 'e', 'ォ' => 'o'];

    /**
     * Transliteriert einen Kana-haltigen String nach Romaji. Nicht-Kana
     * (Lateinbuchstaben, Ziffern, Kanji) bleiben unveraendert erhalten.
     */
    public static function romaji(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $chars = mb_str_split($s);
        $n = count($chars);
        $out = '';
        $sokuon = false; // kleines tsu -> naechsten Konsonanten verdoppeln

        for ($i = 0; $i < $n; $i++) {
            $ch = self::hiraToKata($chars[$i]);

            if ($ch === 'ッ') { $sokuon = true; continue; }

            if ($ch === 'ー') { // Langvokal: vorigen Vokal wiederholen
                $last = substr($out, -1);
                if ($last !== false && strpbrk($last, 'aeiou') !== false) {
                    $out .= $last;
                }
                continue;
            }

            // Kleiner y-Laut -> Palatalisierung der vorigen Silbe (Fallback,
            // falls nicht schon als Kombi behandelt).
            if (isset(self::SMALL_Y[$ch])) {
                $out = self::applySmallY($out, self::SMALL_Y[$ch]);
                continue;
            }
            if (isset(self::SMALL_V[$ch])) {
                $out = self::applySmallVowel($out, self::SMALL_V[$ch]);
                continue;
            }

            if (isset(self::KATA[$ch])) {
                $syll = self::KATA[$ch];
                // Kombi mit folgendem kleinen y direkt aufloesen.
                if ($i + 1 < $n) {
                    $next = self::hiraToKata($chars[$i + 1]);
                    if (isset(self::SMALL_Y[$next]) && substr($syll, -1) === 'i' && $syll !== 'i') {
                        $syll = self::palatalize($syll, self::SMALL_Y[$next]);
                        $i++;
                    }
                }
                if ($sokuon) {
                    $out .= $syll[0];
                    $sokuon = false;
                }
                $out .= $syll;
                continue;
            }

            // Unbekannt (Kanji/Latein/Ziffer/Satzzeichen) -> uebernehmen.
            $sokuon = false;
            $out .= $chars[$i];
        }

        return $out;
    }

    private static function palatalize(string $syll, string $vowel): string
    {
        if ($syll === 'shi') return 'sh' . $vowel;
        if ($syll === 'chi') return 'ch' . $vowel;
        if ($syll === 'ji')  return 'j' . $vowel;
        return substr($syll, 0, -1) . 'y' . $vowel;
    }

    private static function applySmallY(string $out, string $vowel): string
    {
        if ($out === '') return $out;
        // Letzte Silbe heuristisch finden (endet auf Vokal).
        if (preg_match('/(sh|ch|j|[kstnhmrgzdbp])i$/', $out, $m)) {
            return substr($out, 0, -strlen($m[0])) . self::palatalize($m[0], $vowel);
        }
        return $out;
    }

    private static function applySmallVowel(string $out, string $vowel): string
    {
        if ($out === '') return $vowel;
        $last = substr($out, -1);
        if (strpbrk($last, 'aeiou') !== false) {
            // 'u' (von ウ/ヴ) -> w bzw. v bleibt; sonst Konsonant + neuer Vokal.
            $base = substr($out, 0, -1);
            $prev = substr($base, -1);
            if ($last === 'u' && $prev === '') {
                return 'w' . $vowel;
            }
            return $base . $vowel;
        }
        return $out . $vowel;
    }

    /** Hiragana -> Katakana (Codepoint-Verschiebung), sonst unveraendert. */
    private static function hiraToKata(string $ch): string
    {
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp !== false && $cp >= 0x3041 && $cp <= 0x3096) {
            return mb_chr($cp + 0x60, 'UTF-8');
        }
        return $ch;
    }

    /**
     * Echter englischer Set-Titel zu einem japanischen Set-Namen (kuratiert,
     * src/set_names_en.json) oder null, falls nicht hinterlegt.
     */
    public static function setEnglish(string $jaName): ?string
    {
        if (self::$setEn === null) {
            self::$setEn = [];
            $file = __DIR__ . '/set_names_en.json';
            if (is_file($file)) {
                $d = json_decode((string) file_get_contents($file), true);
                if (is_array($d)) {
                    self::$setEn = $d;
                }
            }
        }
        return self::$setEn[trim($jaName)] ?? null;
    }

    // ---------------------------------------------------- Namens-Uebersetzung

    /**
     * Liefert {de,en} fuer einen japanischen Pokémon-Kartennamen oder null.
     *
     * @return array{de:?string,en:?string}|null
     */
    public static function translate(string $jaName, bool $deep = true): ?array
    {
        self::load();
        $name = trim($jaName);
        if ($name === '') {
            return null;
        }
        // Klammer-Zusaetze entfernen, z. B. "ピカチュウ（マスターボール）".
        $name = preg_replace('/[（(].*?[）)]/u', '', $name);
        $name = trim((string) $name);

        $tries = [$name, self::stripSuffix($name)];
        foreach (['メガ', 'アローラ', 'ガラル', 'ヒスイ', 'パルデア'] as $prefix) {
            if (mb_strpos($name, $prefix) === 0) {
                $tries[] = self::stripSuffix(mb_substr($name, mb_strlen($prefix)));
            }
        }

        foreach ($tries as $t) {
            $t = trim((string) $t);
            if ($t !== '' && isset(self::$exact[$t])) {
                return self::pack(self::$exact[$t]);
            }
        }

        // Laengster Spezies-Name, der als Teilstring vorkommt (nur bei Bedarf,
        // da das ueber alle Spezies scannt).
        if ($deep) {
            foreach (self::$byLen as $row) {
                if (mb_strlen($row['name']) >= 2 && mb_strpos($name, $row['name']) !== false) {
                    return ['de' => $row['de'], 'en' => $row['en']];
                }
            }
        }

        return null;
    }

    /**
     * Liefert japanische Pokémon-Namen zu einer deutschen/englischen Eingabe
     * (fuer die Suche im JA-Katalog per DE/EN-Name). Exakte Treffer zuerst,
     * sonst Praefix-Treffer.
     *
     * @return array<int,string>
     */
    public static function toJapanese(string $latin): array
    {
        self::load();
        $q = mb_strtolower(trim($latin));
        if (mb_strlen($q) < 2) {
            return [];
        }
        $exactHits = [];
        $prefixHits = [];
        foreach (self::$byLen as $row) {
            $ja = $row['name'];
            $de = mb_strtolower((string) ($row['de'] ?? ''));
            $en = mb_strtolower((string) ($row['en'] ?? ''));
            if ($de === $q || $en === $q) {
                $exactHits[$ja] = true;
            } elseif (($de !== '' && mb_strpos($de, $q) === 0) || ($en !== '' && mb_strpos($en, $q) === 0)) {
                $prefixHits[$ja] = true;
            }
        }
        return array_slice(array_keys($exactHits + $prefixHits), 0, 12);
    }

    private static function stripSuffix(string $name): string
    {
        // Latein-Suffixe (ex, V, VMAX, GX …) sowie Symbole am Ende entfernen.
        $name = preg_replace('/[\s・]*(VMAX|VSTAR|V-?UNION|GX|EX|ex|V|LEGEND|BREAK|Prime|δ|star|☆|★)\s*$/u', '', $name);
        return trim((string) $name);
    }

    /**
     * @param array{de?:string,en?:string} $e
     * @return array{de:?string,en:?string}
     */
    private static function pack(array $e): array
    {
        return ['de' => $e['de'] ?? null, 'en' => $e['en'] ?? null];
    }

    private static function load(): void
    {
        if (self::$exact !== null) {
            return;
        }
        self::$exact = [];
        self::$byLen = [];
        $file = __DIR__ . '/dex_names.json';
        if (!is_file($file)) {
            return;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return;
        }
        foreach ($data as $entry) {
            $de = $entry['de'] ?? null;
            $en = $entry['en'] ?? null;
            foreach (['ja', 'ja_hrkt'] as $k) {
                if (!empty($entry[$k])) {
                    self::$exact[$entry[$k]] = ['de' => $de, 'en' => $en];
                    self::$byLen[] = ['name' => $entry[$k], 'de' => $de, 'en' => $en];
                }
            }
        }
        usort(self::$byLen, static fn($a, $b) => mb_strlen($b['name']) <=> mb_strlen($a['name']));
    }
}
