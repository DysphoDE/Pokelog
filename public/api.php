<?php

declare(strict_types=1);

/**
 * Schlanke JSON-API fuer Pokelog.
 *
 * Routing ueber den Query-Parameter ?action= in Kombination mit der
 * HTTP-Methode. Antworten sind immer JSON.
 */

require_once __DIR__ . '/../src/CollectionRepository.php';
require_once __DIR__ . '/../src/Auth.php';

// Session starten, bevor irgendeine Ausgabe erfolgt (setzt ggf. das Cookie).
Auth::start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Sendet eine JSON-Antwort und beendet das Skript.
 *
 * @param mixed $data
 */
function respond($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): void
{
    respond(['error' => $message], $status);
}

/**
 * Liest und dekodiert den JSON-Body eines Requests.
 *
 * @return array<string,mixed>
 */
function jsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

/** Liest die gewuenschte Katalogsprache (de|ja) aus dem Request. */
function reqLang(): string
{
    $l = (string) ($_GET['lang'] ?? 'de');
    return in_array($l, CollectionRepository::LANGS, true) ? $l : 'de';
}

try {
    $repo = new CollectionRepository();
    $repo->setUserId(Auth::userId());

    switch ($action) {
        // ----------------------------------------------------- Authentifizierung
        case 'auth.me':
            if ($method !== 'GET') {
                fail('Methode nicht erlaubt', 405);
            }
            respond([
                'user'       => Auth::currentUser(),
                'needsSetup' => Auth::needsSetup(),
            ]);
            break;

        case 'auth.login':
            if ($method !== 'POST') {
                fail('Methode nicht erlaubt', 405);
            }
            $body = jsonBody();
            $user = Auth::login((string) ($body['username'] ?? ''), (string) ($body['password'] ?? ''));
            respond(['user' => $user]);
            break;

        case 'auth.logout':
            if ($method !== 'POST') {
                fail('Methode nicht erlaubt', 405);
            }
            Auth::logout();
            respond(['ok' => true]);
            break;

        case 'auth.setup':
            // Erst-Einrichtung: nur erlaubt, solange noch kein Benutzer existiert.
            if ($method !== 'POST') {
                fail('Methode nicht erlaubt', 405);
            }
            if (!Auth::needsSetup()) {
                fail('Einrichtung bereits abgeschlossen.', 409);
            }
            $body = jsonBody();
            Auth::createUser((string) ($body['username'] ?? ''), (string) ($body['password'] ?? ''), 'admin');
            $user = Auth::login((string) ($body['username'] ?? ''), (string) ($body['password'] ?? ''));
            respond(['user' => $user], 201);
            break;

        // ----------------------------------------------------- Adminpanel
        case 'admin.users':
            Auth::requireAdmin();
            if ($method === 'GET') {
                respond(['users' => Auth::listUsers()]);
            }
            if ($method === 'POST') {
                $body = jsonBody();
                $user = Auth::createUser(
                    (string) ($body['username'] ?? ''),
                    (string) ($body['password'] ?? ''),
                    (string) ($body['role'] ?? 'user')
                );
                respond(['user' => $user], 201);
            }
            fail('Methode nicht erlaubt', 405);
            break;

        case 'admin.user':
            Auth::requireAdmin();
            $uid = (int) ($_GET['id'] ?? 0);
            if ($uid <= 0) {
                fail('Ungueltige id');
            }
            if ($method === 'PATCH' || $method === 'POST') {
                respond(['user' => Auth::updateUser($uid, jsonBody())]);
            }
            if ($method === 'DELETE') {
                Auth::deleteUser($uid);
                respond(['deleted' => true, 'id' => $uid]);
            }
            fail('Methode nicht erlaubt', 405);
            break;

        case 'search':
            if ($method !== 'GET') {
                fail('Methode nicht erlaubt', 405);
            }
            $q = trim((string) ($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) {
                respond(['results' => [], 'mode' => 'name', 'lang' => reqLang()]);
            }
            $res = $repo->smartSearch($q, reqLang());
            respond($res);
            break;

        case 'sets':
            if ($method !== 'GET') {
                fail('Methode nicht erlaubt', 405);
            }
            respond(['sets' => $repo->listSets(reqLang()), 'lang' => reqLang()]);
            break;

        case 'owned':
            if ($method !== 'GET') {
                fail('Methode nicht erlaubt', 405);
            }
            respond(['lang' => reqLang(), 'owned' => $repo->ownedMap(reqLang())]);
            break;

        case 'index':
            if ($method !== 'GET') {
                fail('Methode nicht erlaubt', 405);
            }
            // Kompakter Such-Index fuer die clientseitige Sofortsuche.
            // Darf gecacht werden (Service Worker + Browser).
            header('Cache-Control: public, max-age=3600');
            respond(['lang' => reqLang(), 'cards' => $repo->searchIndex(reqLang())]);
            break;

        case 'set':
            if ($method !== 'GET') {
                fail('Methode nicht erlaubt', 405);
            }
            $setId = trim((string) ($_GET['id'] ?? ''));
            if ($setId === '') {
                fail('Parameter "id" fehlt');
            }
            $res = $repo->getSetCards($setId, reqLang());
            if ($res['set'] === null && $res['cards'] === []) {
                fail('Set nicht gefunden', 404);
            }
            respond($res);
            break;

        case 'prices':
            if ($method !== 'GET') {
                fail('Methode nicht erlaubt', 405);
            }
            $ids = array_filter(explode(',', (string) ($_GET['ids'] ?? '')));
            respond(['prices' => $repo->getPrices($ids, reqLang())]);
            break;

        case 'sets.rebuild':
            Auth::requireAdmin();
            if ($method !== 'POST') {
                fail('Methode nicht erlaubt', 405);
            }
            respond(['result' => $repo->rebuildSetIndex()]);
            break;

        case 'card':
            if ($method !== 'GET') {
                fail('Methode nicht erlaubt', 405);
            }
            $id = trim((string) ($_GET['id'] ?? ''));
            if ($id === '') {
                fail('Parameter "id" fehlt');
            }
            $detail = $repo->getCardDetail($id, reqLang());
            if ($detail === null) {
                fail('Karte nicht gefunden', 404);
            }
            respond($detail);
            break;

        case 'override':
            Auth::requireAuth();
            if ($method !== 'POST') {
                fail('Methode nicht erlaubt', 405);
            }
            $body = jsonBody();
            $cardId = trim((string) ($body['cardId'] ?? ''));
            if ($cardId === '') {
                fail('cardId fehlt');
            }
            // price = null/'' loescht die Korrektur.
            $price = array_key_exists('price', $body) && $body['price'] !== null && $body['price'] !== ''
                ? (float) $body['price']
                : null;
            $repo->setOverride($cardId, $price);
            respond(['cardId' => $cardId, 'override' => $repo->getOverride($cardId)]);
            break;

        case 'collection':
            Auth::requireAuth();
            if ($method === 'GET') {
                $setId = $_GET['set'] ?? null;
                $q = $_GET['q'] ?? null;
                $sort = (string) ($_GET['sort'] ?? 'set');
                respond(['items' => $repo->getCollection($setId, $q, $sort)]);
            }
            if ($method === 'POST') {
                $body = jsonBody();
                $cardId = trim((string) ($body['cardId'] ?? ''));
                if ($cardId === '') {
                    fail('cardId fehlt');
                }
                $item = $repo->addItem(
                    $cardId,
                    (string) ($body['catalogLang'] ?? $body['lang'] ?? 'de'),
                    (string) ($body['variant'] ?? 'normal'),
                    (string) ($body['condition'] ?? 'NM'),
                    (string) ($body['language'] ?? 'de'),
                    (int) ($body['quantity'] ?? 1),
                    isset($body['notes']) ? (string) $body['notes'] : null
                );
                respond(['item' => $item], 201);
            }
            fail('Methode nicht erlaubt', 405);
            break;

        case 'item':
            Auth::requireAuth();
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                fail('Ungueltige id');
            }
            if ($method === 'PATCH' || $method === 'POST') {
                $item = $repo->updateItem($id, jsonBody());
                respond(['item' => $item]);
            }
            if ($method === 'DELETE') {
                $repo->deleteItem($id);
                respond(['deleted' => true, 'id' => $id]);
            }
            fail('Methode nicht erlaubt', 405);
            break;

        case 'prices.refresh':
            Auth::requireAuth();
            if ($method !== 'POST') {
                fail('Methode nicht erlaubt', 405);
            }
            $body = jsonBody();
            $onlyStale = !empty($body['onlyStale']) || ($_GET['onlyStale'] ?? '') === '1';
            // Standard: alle erneuern, wenn nicht ausdruecklich nur veraltete.
            $result = $repo->refreshPrices($onlyStale);
            respond(['result' => $result]);
            break;

        case 'stats':
            Auth::requireAuth();
            if ($method !== 'GET') {
                fail('Methode nicht erlaubt', 405);
            }
            respond(['stats' => $repo->getStats()]);
            break;

        case 'export':
            Auth::requireAuth();
            if ($method !== 'GET') {
                fail('Methode nicht erlaubt', 405);
            }
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="pokelog-sammlung.csv"');
            header('Cache-Control: no-store');
            echo $repo->exportCsv();
            exit;

        default:
            fail('Unbekannte Aktion: ' . $action, 404);
    }
} catch (AuthException $e) {
    // Anmeldung/Berechtigung: 401 bzw. 403.
    $status = $e->getCode();
    fail($e->getMessage(), ($status === 401 || $status === 403) ? $status : 401);
} catch (RuntimeException $e) {
    // Erwartbare Eingabe-/Logikfehler (z. B. falsches Passwort).
    fail($e->getMessage(), 400);
} catch (Throwable $e) {
    fail('Serverfehler: ' . $e->getMessage(), 500);
}
