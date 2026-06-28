# Pokélog 🃏

Eine moderne, selbst-gehostete Web-App zum **Tracken deiner Pokémon-Karten­sammlung** –
inspiriert von *Manabox*. Inklusive **Kamera-Scan-Funktion**, **deutschen
Cardmarket-Preisen** für deutsche Karten und **Unterstützung für japanische Karten**.

Gebaut mit **PHP (ohne Build-Schritt)**, **SQLite**, **Tailwind CSS (CDN)**,
**Alpine.js** und **visueller Bilderkennung** (Perceptual-Hash) für den Scan.

---

## Features

- 📷 **Live-Scan per Kamera (visuelle Bilderkennung)** – Karte einfach vor die
  Kamera halten (kein Knopfdruck): Der Scanner berechnet live einen visuellen
  Fingerabdruck (Perceptual-Hash / dHash) des Kamerabilds und findet die
  ähnlichste Karte in einer vorberechneten Hash-Tabelle. Das ist sprach- und
  schriftunabhängig und robust gegen Holo-Glanz – deutlich zuverlässiger als
  reines Ziffern-OCR. Wählt automatisch die Haupt-Rückkamera (Zoom 1x),
  optional mit Taschenlampe.
- 🔍 **Blitzschnelle Suche (lokal, keine Live-API-Calls)** – dank lokalem
  Karten-Index über alle ~19.500 Karten. Antwortzeit typ. < 10 ms. Möglich sind:
  - **Name** (deutsch): `Glurak`, `Pikachu`, `Glurak ex`
  - **Sammlernummer** = Set-Kürzel + Nummer: `MEP 047`, `DAA 25`, `MEW 199`
    (auch `mep-47`, `MEP047` oder direkt die Set-ID `swsh3 136`)
  - **Name + Set kombiniert**: `mew PAF` (Mew aus „Paldeas Schicksale") –
    funktioniert in beliebiger Reihenfolge (`PAF mew`)

  Anschließend mit Variante, Zustand, Sprache und Anzahl zur Sammlung hinzufügen
  (Varianten + aktueller Preis werden erst beim Öffnen des Dialogs geladen).
- 🇩🇪🇯🇵 **Deutsch & Japanisch** – Umschalter oben rechts wechselt den Katalog
  zwischen deutschen und japanischen Karten. Suche, Set-Browser und Hinzufügen
  arbeiten jeweils sprachbewusst. Japanische Karten haben japanische Namen/Bilder;
  Cardmarket-Preise existieren dort nur vereinzelt (Cardmarket listet primär
  westliche Karten).
- 🃏 **Set-Browser** – eigener Tab „Sets": alle Sets nach Serie gruppiert (mit
  Logo & Erscheinungsdatum). Set öffnen → alle Karten als Raster → direkt zur
  Sammlung hinzufügen.
- 🗂️ **Sammlung** – Übersicht mit Bildern, Mengen, Filter nach Set und Suche.
- 💶 **Deutsche Cardmarket-Preise für jede Karte** – Preise werden für jede
  angezeigte Karte (Suche, Set-Browser, Scan) on-demand von TCGdex geholt und
  lokal gecacht (TTL 24 h) – beim nächsten Mal sind sie sofort da. Die
  Sammlungspreise lassen sich zusätzlich per Knopf gebündelt aktualisieren.
- 📊 **Statistik** – Gesamtwert, Wert nach Set, wertvollste Karten.
- 👤 **Login & Mehrbenutzer** – Jede:r meldet sich an und hat eine **eigene,
  serverseitig gespeicherte Sammlung** (SQLite). Passwörter werden gehasht
  (bcrypt), die Sitzung läuft über ein HttpOnly-Cookie.
- 🛠️ **Adminpanel** – Admins legen Benutzer an, vergeben Rollen
  (Admin/Benutzer), aktivieren/deaktivieren Konten, setzen Passwörter zurück,
  löschen Konten und stoßen die Set-Verzeichnis-Aktualisierung an.
- 🙋 **Gast-Modus (ohne Login)** – Wer sich nicht anmeldet, kann die App
  trotzdem nutzen: Die Sammlung wird dann **nur lokal im Browser (localStorage)**
  gespeichert. Ein deutlicher Hinweis weist darauf hin, dass diese Daten
  **nicht gesichert** und **nicht geräteübergreifend** sind.

---

## Anmeldung, Benutzer & Gast-Modus

Beim ersten Start existiert noch kein Konto. Die App zeigt dann eine
**Erst-Einrichtung**: Lege über den Anmelde-Dialog das erste
**Administrator-Konto** an. Eine bereits vorhandene (Einzelnutzer-)Sammlung aus
einer früheren Version wird diesem ersten Admin automatisch zugeordnet.

- **Anmelden/Konto:** Button oben rechts (bzw. in der Seitenleiste).
- **Eigene Sammlung:** Nach dem Login ist die Sammlung an das Konto gebunden und
  liegt in der SQLite-Datenbank – geräteübergreifend, solange derselbe Server
  genutzt wird.
- **Adminpanel:** Als Admin erscheint der Tab **„Admin"** (Benutzer anlegen/
  verwalten, Wartung). Nicht-Admins sehen ihn nicht.
- **Gast-Modus:** Über „Ohne Login fortfahren" wird die Sammlung ausschließlich
  im `localStorage` des Browsers gehalten (nicht gesichert, nicht
  geräteübergreifend; beim Leeren des Browsers gehen die Daten verloren). Such-,
  Set-Browser-, Scan- und Preisfunktionen funktionieren auch ohne Login.

> **Hinweis:** Eine Gast-Sammlung wird beim Anmelden **nicht automatisch** in
> ein Konto übernommen – sie bleibt lokal. Wer geräteübergreifend sammeln will,
> sollte sich von Anfang an anmelden.

---

## Woher kommen die Daten?

| Daten | Quelle | Hinweis |
|------|--------|---------|
| Kartenstammdaten, Namen (DE/JA), Bilder, Sets | [TCGdex API](https://tcgdex.dev) | Kostenlos, Open Source, mehrsprachig (`/de/…`, `/ja/…`) |
| Kartenbilder (Fallback, nur DE) | TCGdex (englische Scans) | ~25 % der deutschen Karten (v. a. alte/Promo-Sets) haben kein deutsches Bild – dann wird automatisch das englische Artwork genutzt (Bild identisch). DE-Abdeckung dadurch ~98 %. Bei japanischen Karten ~52 % (TCGdex hat für sehr neue/sehr alte JA-Sets noch keine Scans); fehlt ein Bild, wird ein Platzhalter gezeigt. |
| **Cardmarket-Preise (EUR)** | In der TCGdex-Antwort eingebettet (`pricing.cardmarket`) | Cardmarket bietet keine offene API mehr an – TCGdex aggregiert die Preise und liefert sie pro Karte mit (Aktualisierung ~täglich) |

**Warum dieser Weg?** Cardmarket hat seine API geschlossen (nur noch für
zugelassene Händler/Partner). Statt Cardmarket selbst zu scrapen, nutzen wir die
von TCGdex bereits aggregierten Cardmarket-EUR-Preise. Diese werden **bei Bedarf
für jede angezeigte Karte** geholt und lokal in SQLite zwischen­gespeichert
(Standard-TTL: 24 h). So ist der Preis jederzeit für jede Karte verfügbar, ohne
beim Index-Aufbau alle ~25.000 Karten einzeln abzufragen.

---

## Voraussetzungen

- **PHP ≥ 8.1** mit den Erweiterungen `pdo_sqlite`, `sqlite3`, `curl` und `gd`
  (alles in Standard-PHP enthalten). `gd` wird für die Berechnung der visuellen
  Karten-Fingerabdrücke (Scanner) benötigt.
- Internetverbindung (für TCGdex-Abfragen und die CDN-Skripte).

Prüfen:

```bash
php -v
php -m
```

---

## Starten

Vom Projektordner aus den eingebauten PHP-Webserver starten und auf den
`public/`-Ordner zeigen lassen:

```bash
php -S localhost:8000 -t public
```

Dann im Browser öffnen: **http://localhost:8000**

Die SQLite-Datenbank wird beim ersten Aufruf automatisch unter `data/pokelog.sqlite`
angelegt.

> **Einmaliger Index-Aufbau:** Beim ersten Such-/Set-Vorgang baut die App einmalig
> einen lokalen Karten-Index für **beide Sprachen (DE + JA)** auf (alle Sets von
> TCGdex, inkl. englischem Bild-Fallback für Deutsch). Das dauert **~1–2 Minuten**
> und passiert nur einmal – danach läuft alles komplett lokal und sofort. Neu
> erschienene Sets lassen sich später über den Button **„Verzeichnis aktualisieren"**
> (Sets-Tab) oder `POST api.php?action=sets.rebuild` nachladen.

> **Kamera-Scan:** Der Browser erlaubt Kamerazugriff nur über `http://localhost`
> oder `https://`. Lokal funktioniert `localhost` direkt. Für den Zugriff vom
> Handy im selben Netz brauchst du HTTPS (z. B. via Reverse-Proxy oder
> `ngrok`/`caddy`).

---

## Projektstruktur

```
Pokelog/
├── public/
│   ├── index.php          # HTML-Shell (Tailwind + Alpine, kein Build)
│   ├── api.php            # JSON-API (Routing über ?action=)
│   └── assets/
│       └── app.js         # Frontend-Logik inkl. Kamera-Scan/OCR
├── src/
│   ├── Config.php             # Konfiguration (Sprache, TTL, Pfade)
│   ├── Database.php           # PDO-SQLite + Schema-Migration
│   ├── Auth.php               # Login/Sessions + Benutzerverwaltung
│   ├── TcgdexClient.php       # HTTP-Client zur TCGdex-API
│   └── CollectionRepository.php  # Sammlung (pro Benutzer) + Preis-Caching
├── data/                  # SQLite-DB (wird automatisch erzeugt, gitignored)
└── README.md
```

---

## API-Überblick

Alle Endpunkte liegen unter `public/api.php?action=…` und liefern JSON.

| Methode | Aktion | Beschreibung |
|--------|--------|--------------|
| `GET`  | `auth.me` | Aktueller Anmeldestatus (`user`) + ob noch keine Konten existieren (`needsSetup`) |
| `POST` | `auth.login` | Anmelden (`username`, `password`) |
| `POST` | `auth.logout` | Abmelden |
| `POST` | `auth.setup` | Erstes Admin-Konto anlegen (nur solange kein Benutzer existiert) |
| `GET`  | `admin.users` | **(Admin)** Alle Benutzer auflisten |
| `POST` | `admin.users` | **(Admin)** Benutzer anlegen (`username`, `password`, `role`) |
| `PATCH`| `admin.user&id=…` | **(Admin)** Benutzer ändern (`password`, `role`, `isActive`) |
| `DELETE`| `admin.user&id=…` | **(Admin)** Benutzer inkl. Sammlung löschen |
| `GET`  | `search&q=Glurak&lang=de` | Smarte Suche: erkennt automatisch Name vs. Sammlernummer. `lang=de\|ja` wählt den Katalog |
| `GET`  | `sets&lang=de` | Alle Sets der Sprache (nach Serie gruppierbar, mit Logo/Release/Kartenzahl) |
| `GET`  | `set&id=sv03.5&lang=de` | Alle Karten eines Sets (für den Set-Browser) |
| `POST` | `sets.rebuild` | Set-Verzeichnis **DE + JA** neu aufbauen (bei neuen Sets, ~1–2 Min.) |
| `GET`  | `scan.hashes&lang=de` | Visuelle Fingerabdruck-Tabelle (Perceptual-Hash) für den Scanner |
| `POST` | `scan.hashes.build` | **(Admin)** Fehlende Bild-Fingerabdrücke berechnen (resumierbar, Charge à 200) |
| `GET`  | `scan.hashes.status` | **(Admin)** Fortschritt der Fingerabdruck-Berechnung (`done`/`total`/`remaining`) |
| `GET`  | `card&id=swsh3-136&lang=de` | Einzelne Karte (gecached) |
| `GET`  | `prices&ids=a,b,c&lang=de` | Cardmarket-Preise für beliebige Karten (holt fehlende nach & cacht sie) |
| `GET`  | `collection` | Sammlung abrufen (Filter: `&set=`, `&q=`) |
| `POST` | `collection` | Karte hinzufügen (`cardId`, `catalogLang`, `variant`, `condition`, `language`, `quantity`) |
| `PATCH`| `item&id=…` | Eintrag ändern (z. B. `quantity`) |
| `DELETE`| `item&id=…` | Eintrag löschen |
| `POST` | `prices.refresh` | Cardmarket-Preise der Sammlung aktualisieren (`onlyStale: true/false`) |
| `GET`  | `stats` | Statistik (Gesamtwert, nach Set, Top-Karten) |

> **Berechtigungen:** `collection`, `item`, `stats`, `export`, `prices.refresh`
> und `override` erfordern eine **Anmeldung** (Sammlung ist pro Benutzer
> getrennt). `sets.rebuild` ist **Admins** vorbehalten. Die Katalog-Endpunkte
> (`search`, `sets`, `set`, `index`, `card`, `prices`, `owned`) sind ohne Login
> nutzbar – im Gast-Modus liefert `owned` allerdings nichts, da die Gast-
> Sammlung nur im Browser liegt.

---

## Hinweise zum Scan

Der Scanner arbeitet **live** und per **visueller Bilderkennung** – einfach die
Karte vor die Kamera halten, es ist **kein Knopfdruck** nötig. Technik dahinter:

- **Perceptual-Hash (dHash):** Jede Karte bekommt **einmalig serverseitig** einen
  kompakten visuellen Fingerabdruck (Graustufen → 13×12-Raster → 144-Bit-Hash),
  berechnet aus dem TCGdex-Kartenbild. Diese Hash-Tabelle wird im `card_index`
  gespeichert.
- **Live im Browser:** Der ausgerichtete Kartenausschnitt (63:88-Rahmen) wird mit
  exakt demselben Verfahren gehasht und per **Hamming-Distanz** gegen die
  geladene Tabelle gematcht. Frame-Voting + Distanz-Schwelle + Abstand zum
  zweitbesten Treffer verhindern Fehlerkennungen.
- **Vorteile:** sprach- und schriftunabhängig (DE/JA), robust gegen Holo-Glanz,
  ohne OCR-Latenz – das Matching läuft rein lokal in Millisekunden. Optisch sehr
  ähnliche Karten (z. B. andere Rarität) werden als kurze Trefferliste angeboten.

**Einmaliger Aufbau:** Die Fingerabdrücke müssen einmal berechnet werden – als
**Admin** im Tab **„Admin" → „Scanner-Fingerabdrücke berechnen"** (oder
`POST api.php?action=scan.hashes.build`, resumierbar). Es werden dabei alle
Kartenbilder einmal geladen; das dauert je nach Verbindung einige Minuten und
ist danach dauerhaft gespeichert (neue Sets nur nachrechnen).

Kamera-Details: Pokélog wählt automatisch die **Haupt-Rückkamera** (keine Tele-/
Ultraweit-Linse) und setzt den **Zoom auf 1x** zurück. Wo unterstützt, lässt sich
die **Taschenlampe** zuschalten. Für beste Ergebnisse die **ganze Karte**
formatfüllend und gerade in den Rahmen halten. Klappt es mal nicht: „Foto
erzwingen" matcht den aktuellen Frame sofort, oder einfach über die **Suche**
hinzufügen.

---

## Lizenz & Daten

Kartendaten & Bilder stammen von TCGdex (Community-Projekt). Pokémon und
Cardmarket sind Marken der jeweiligen Eigentümer. Dieses Projekt ist ein privates
Sammlungs-Tool ohne kommerzielle Absicht.
