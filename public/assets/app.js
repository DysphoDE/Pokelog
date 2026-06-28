/* Pokélog – Frontend-Logik (Alpine.js Komponente).
 * Kein Build-Schritt: laeuft direkt im Browser.
 */

function pokelog() {
    return {
        // -------------------------------------------------- Allgemeiner Zustand
        tabs: [
            { id: 'home',       label: 'Start',     icon: '🏠', sym: 'home' },
            { id: 'collection', label: 'Sammlung',  icon: '🗂️', sym: 'style' },
            { id: 'scan',       label: 'Scannen',   icon: '📷', sym: 'qr_code_scanner' },
            { id: 'search',     label: 'Suchen',    icon: '🔍', sym: 'search' },
            { id: 'sets',       label: 'Sets',      icon: '🃏', sym: 'auto_awesome_motion' },
            { id: 'stats',      label: 'Statistik', icon: '📊', sym: 'monitoring' },
        ],
        // Gruppierte Navigation fuer die Sidebar (Admin wird separat ergaenzt).
        navGroups: [
            { label: '',          items: ['home'] },
            { label: 'Sammlung',  items: ['collection', 'stats'] },
            { label: 'Entdecken', items: ['search', 'sets'] },
        ],
        tab: 'home',
        loading: false,
        refreshing: false,
        toast: '',

        // -------------------------------------------------- Authentifizierung
        // auth.user = null  -> Gast-Modus (Sammlung nur im localStorage).
        auth: { user: null, needsSetup: false, checked: false },
        authModal: false,
        authView: 'login',          // 'login' | 'setup' | 'account'
        authForm: { username: '', password: '' },
        authBusy: false,
        authError: '',

        // -------------------------------------------------- Adminpanel
        adminUsers: [],
        adminLoading: false,
        adminForm: { username: '', password: '', role: 'user' },
        adminBusy: false,
        adminError: '',

        // Kein globaler Sprachmodus mehr: Die Suche findet beide Kataloge.
        // Such-Filter: 'all' (DE+JA), 'de' oder 'ja'.
        searchLang: localStorage.getItem('pokelog_searchlang') || 'all',
        searchLangs: [
            { id: 'all', label: 'Alle', flag: '🌐' },
            { id: 'de', label: 'Deutsch', flag: '🇩🇪' },
            { id: 'ja', label: 'Japanisch', flag: '🇯🇵' },
        ],
        // Sprache der Set-Ansicht (Sets sind katalogspezifisch).
        setsLang: localStorage.getItem('pokelog_setslang') || 'de',
        // Monoton steigende Tokens, um veraltete Async-Antworten zu verwerfen.
        _tok: { search: 0, sets: 0, setView: 0, card: 0 },
        // Platzhalter (Inline-SVG) fuer Karten ohne Bild (helles Zenith-Dex-Theme).
        placeholder: 'data:image/svg+xml;utf8,' + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" width="245" height="342" viewBox="0 0 245 342">' +
            '<rect width="245" height="342" rx="14" fill="#f3f3f6"/>' +
            '<circle cx="122" cy="150" r="46" fill="none" stroke="#dadadc" stroke-width="6"/>' +
            '<line x1="76" y1="150" x2="168" y2="150" stroke="#dadadc" stroke-width="6"/>' +
            '<circle cx="122" cy="150" r="15" fill="#ffffff" stroke="#dadadc" stroke-width="6"/>' +
            '<text x="122" y="250" fill="#956d67" font-family="sans-serif" font-size="18" text-anchor="middle">Kein Bild</text></svg>'
        ),

        // -------------------------------------------------- Sammlung
        collection: [],
        collectionQuery: '',
        collectionSet: '',
        collectionSort: 'set',
        exporting: false,
        setOptions: [],
        stats: { totalValue: 0, totalQuantity: 0, uniqueCards: 0, bySet: [], topCards: [] },

        // -------------------------------------------------- Suche (clientseitig)
        searchQuery: '',
        searchResults: [],
        searching: false,
        searchMode: 'name',
        searchSet: null,
        searchNote: '',
        searchTotal: 0,
        rebuildingSets: false,
        buildingHashes: false,
        hashProgress: '',
        // Lokaler Such-Index je Sprache (einmalig geladen, dann Sofortsuche).
        index: { de: null, ja: null },
        indexLoading: false,
        _abbrSet: { de: null, ja: null },
        // Besitz-Map (cardId => Menge) je Katalogsprache fuer Live-Markierung.
        ownedMap: { de: {}, ja: {} },

        // -------------------------------------------------- Karten-Detailseite
        cardView: null,     // { loading, lang, base, card, owned, names, price, override, related }
        overrideInput: '',
        savingOverride: false,
        // Zuletzt angesehene Karten (lokal persistiert).
        recent: JSON.parse(localStorage.getItem('pokelog_recent') || '[]'),

        // -------------------------------------------------- Sets-Browser
        sets: [],
        setGroups: [],
        setsLoading: false,
        setsLoadedLang: null,
        setFilter: '',
        // Set-Ansicht: 'tcg' = klassisches Sammelkartenspiel, 'pocket' = Handyspiel.
        setsView: 'tcg',
        openSetData: null,      // { set, cards } des geoeffneten Sets
        setCardsLoading: false,

        // -------------------------------------------------- Hinzufuegen (in Detailseite)
        conditions: ['M', 'NM', 'EX', 'GD', 'LP', 'PL', 'PO'],
        addCard: null,
        addBusy: false,
        addForm: { variant: 'normal', condition: 'NM', language: 'de', quantity: 1, catalogLang: 'de' },

        // -------------------------------------------------- Scan
        cameraActive: false,
        scanning: false,            // manueller Einzel-Scan laeuft
        scanLive: false,            // Live-Erkennung aktiv
        scanProgress: '',
        scanError: '',
        scanHint: 'Kamera wird gestartet …',
        scanStatus: 'idle',         // idle | scanning | found | nomatch
        ocrText: '',
        ocrSummary: '',
        scanMatches: [],
        scanHashLoading: false,     // Fingerabdruck-Tabelle wird geladen
        torchOn: false,
        torchSupported: false,
        _stream: null,
        _scanRun: false,
        _scanTimer: null,
        _scanBusy: false,
        _camDenied: false,
        _lastId: '',
        _cooldownUntil: 0,
        _hashVotes: [],                    // zuletzt erkannte Karten-IDs (Frame-Voting)
        _hc: null,                         // Offscreen-Canvas fuer den dHash
        _hashByLang: {},                   // geladene Fingerabdruck-Tabellen je Sprache
        scanHashes: null,                  // aktive Tabelle {ids, data, stride, lang}
        _idxMap: {},                       // id -> Index-Zeile je Sprache
        _popcount: null,                   // Lookup-Tabelle fuer Bit-Zaehlung

        // ============================================================ Lifecycle
        async init() {
            // Routing: Tab, Set und Karte werden im URL-Hash abgebildet, damit
            // jede Detailseite verlinkbar ist (Deeplinks). Back/Forward, Geste und
            // manuelles Bearbeiten der URL fuehren ueber applyRoute() zum
            // korrekten Zustand.
            window.addEventListener('popstate', () => this.applyRoute());
            window.addEventListener('hashchange', () => this.applyRoute());
            this.registerServiceWorker();

            // Anmeldestatus ermitteln (entscheidet Server- vs. Gast-Modus).
            await this.loadAuth();

            await this.loadCollection();
            await this.loadStats();
            // Besitz-Maps + Such-Indizes beider Kataloge im Hintergrund laden,
            // damit die Suche sofort beide Sprachen findet.
            this.loadOwned('de');
            this.loadOwned('ja');
            this.loadIndex('de').then(() => this.loadIndex('ja'));

            // Deeplink/Tab aus der aktuellen URL anwenden (oeffnet ggf. Detail).
            this.bootRoute();
        },

        // ============================================================ Auth
        // True, solange kein Benutzer angemeldet ist (Sammlung nur lokal).
        isGuest() { return !this.auth.user; },
        isAdmin() { return !!(this.auth.user && this.auth.user.role === 'admin'); },
        userInitial() { return (this.auth.user && this.auth.user.username || '?').trim().charAt(0).toUpperCase() || '?'; },

        async loadAuth() {
            try {
                const data = await this.api('?action=auth.me');
                this.auth.user = data.user || null;
                this.auth.needsSetup = !!data.needsSetup;
            } catch (e) {
                this.auth.user = null;
            } finally {
                this.auth.checked = true;
                this.syncTabs();
            }
        },

        // Ergaenzt/entfernt den Admin-Tab je nach Rolle.
        syncTabs() {
            const hasAdmin = this.tabs.some((t) => t.id === 'admin');
            if (this.isAdmin() && !hasAdmin) {
                this.tabs.push({ id: 'admin', label: 'Admin', icon: '🛠️', sym: 'admin_panel_settings' });
            } else if (!this.isAdmin() && hasAdmin) {
                this.tabs = this.tabs.filter((t) => t.id !== 'admin');
                if (this.tab === 'admin') this.setTab('collection');
            }
        },

        // Oeffnet das Auth-Overlay im passenden Modus.
        openAuth() {
            this.authError = '';
            this.authForm = { username: '', password: '' };
            if (this.auth.user) this.authView = 'account';
            else this.authView = this.auth.needsSetup ? 'setup' : 'login';
            this.authModal = true;
        },
        closeAuth() { this.authModal = false; this.authError = ''; },

        async doLogin() {
            this.authBusy = true; this.authError = '';
            try {
                const data = await this.api('?action=auth.login', {
                    method: 'POST',
                    body: JSON.stringify({ username: this.authForm.username, password: this.authForm.password }),
                });
                await this.onAuthChanged(data.user, `Willkommen, ${data.user.username}!`);
            } catch (e) {
                this.authError = e.message;
            } finally {
                this.authBusy = false;
            }
        },

        async doSetup() {
            this.authBusy = true; this.authError = '';
            try {
                const data = await this.api('?action=auth.setup', {
                    method: 'POST',
                    body: JSON.stringify({ username: this.authForm.username, password: this.authForm.password }),
                });
                this.auth.needsSetup = false;
                await this.onAuthChanged(data.user, 'Admin-Konto erstellt ✓');
            } catch (e) {
                this.authError = e.message;
            } finally {
                this.authBusy = false;
            }
        },

        async doLogout() {
            try { await this.api('?action=auth.logout', { method: 'POST' }); } catch (e) { /* egal */ }
            const wasGuestTab = this.tab;
            this.auth.user = null;
            this.syncTabs();
            this.closeAuth();
            this.showToast('Abgemeldet.');
            // Zurueck in den Gast-Modus: lokale Sammlung neu berechnen.
            this._guestLoaded = false;
            this.ownedMap = { de: {}, ja: {} };
            await this.loadCollection();
            await this.loadStats();
            if (wasGuestTab === 'admin') this.setTab('collection');
        },

        // Nach erfolgreichem Login/Setup: Server-Modus aktivieren und neu laden.
        async onAuthChanged(user, msg) {
            this.auth.user = user;
            this.syncTabs();
            this.closeAuth();
            this.showToast(msg);
            this.ownedMap = { de: {}, ja: {} };
            this.loadOwned('de'); this.loadOwned('ja');
            await this.loadCollection();
            await this.loadStats();
        },

        // ============================================================ Routing
        // Liest den aktuellen Hash und liefert das Ziel der Ansicht.
        // Formate: "#sets" (Tab), "#set/<lang>/<id>", "#card/<lang>/<id>".
        routeFromHash() {
            const raw = (location.hash || '').replace(/^#/, '');
            if (!raw) return { tab: 'home' };
            const parts = raw.split('/');
            const kind = parts[0];
            if (kind === 'card' && parts.length >= 3) {
                return { kind: 'card', lang: parts[1] === 'ja' ? 'ja' : 'de', id: this._decodeId(parts.slice(2).join('/')) };
            }
            if (kind === 'set' && parts.length >= 3) {
                return { kind: 'set', lang: parts[1] === 'ja' ? 'ja' : 'de', id: this._decodeId(parts.slice(2).join('/')) };
            }
            if (this.tabs.some((t) => t.id === kind)) return { tab: kind };
            return { tab: 'home' };
        },

        _decodeId(s) { try { return decodeURIComponent(s); } catch (e) { return s; } },
        _cardHash(id, lang) { return 'card/' + (lang === 'ja' ? 'ja' : 'de') + '/' + encodeURIComponent(id); },
        _setHash(id, lang) { return 'set/' + (lang === 'ja' ? 'ja' : 'de') + '/' + encodeURIComponent(id); },

        // Setzt den Hash (Standard: neuer History-Eintrag) und gleicht den
        // Zustand ab. Gleicher Hash -> nur abgleichen (kein Doppel-Eintrag).
        navigate(hash, replace = false) {
            const cur = (location.hash || '').replace(/^#/, '');
            if (cur === hash) { this.applyRoute(); return; }
            const url = '#' + hash;
            if (replace) history.replaceState({ pl: hash }, '', url);
            else history.pushState({ pl: hash }, '', url);
            this.applyRoute();
        },

        // Gleicht den App-Zustand (Tab/Set/Karte) an den aktuellen Hash an.
        // Idempotent: bereits offene Ansichten werden nicht neu geladen.
        applyRoute() {
            const r = this.routeFromHash();
            if (r.kind === 'card') {
                if (!this.cardView || !this.cardView.base || this.cardView.base.id !== r.id) {
                    this.openCard({ id: r.id, lang: r.lang });
                }
                return;
            }
            // Kein Karten-Hash mehr -> offene Karte schliessen (Token bumpen,
            // damit eine noch laufende Detailabfrage verworfen wird).
            if (this.cardView) { this.cardView = null; this._tok.card++; }

            if (r.kind === 'set') {
                if (!this.openSetData || !this.openSetData.set || this.openSetData.set.id !== r.id) {
                    this.openSetView({ id: r.id, lang: r.lang });
                }
                return;
            }
            if (this.openSetData) { this.openSetData = null; this._tok.setView++; }

            this.activateTab(r.tab || 'home');
        },

        // Beim Start: fuer Deeplinks einen sinnvollen Zurueck-Stack erzeugen
        // (Eltern-Liste + Detail), damit der Zurueck-Button in der App bleibt.
        bootRoute() {
            const r = this.routeFromHash();
            if (r.kind === 'card') {
                this.navigate('search', true);
                this.navigate(this._cardHash(r.id, r.lang));
            } else if (r.kind === 'set') {
                this.navigate('sets', true);
                this.navigate(this._setHash(r.id, r.lang));
            } else {
                this.navigate(r.tab || 'home', true);
            }
        },

        // Wechselt den Tab (per Hash, damit verlinkbar). Overlays schliessen sich.
        setTab(id) { this.navigate(id); },

        // Fuehrt die Tab-Wechsel-Nebenwirkungen aus (ohne den Hash zu setzen).
        activateTab(id) {
            if (!this.tabs.some((t) => t.id === id)) id = 'home';
            if (id !== 'scan' && this.cameraActive) this.stopCamera();
            const changed = this.tab !== id;
            this.tab = id;
            if (!changed) return;
            if (id === 'home' || id === 'stats') this.loadStats();
            if (id === 'collection') this.loadCollection();
            if (id === 'sets') this.loadSets();
            if (id === 'admin') this.loadUsers();
            // Scan-Tab: Kamera automatisch starten (sofern nicht zuvor abgelehnt).
            if (id === 'scan' && !this.cameraActive && !this._camDenied) {
                this.$nextTick(() => this.startCamera());
            }
        },

        // Kopiert den Deeplink der aktuellen Ansicht in die Zwischenablage.
        async copyLink() {
            const url = location.href;
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(url);
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
                    document.body.appendChild(ta); ta.select();
                    document.execCommand('copy'); document.body.removeChild(ta);
                }
                this.showToast('Link kopiert ✓');
            } catch (e) {
                this.showToast('Link: ' + url);
            }
        },

        // Tab-Metadaten per ID (fuer die gruppierte Navigation).
        tabById(id) { return this.tabs.find((t) => t.id === id) || { id, label: id, sym: 'circle' }; },

        // Tageszeit-abhaengige Begruessung fuer die Startseite.
        greeting() {
            const h = new Date().getHours();
            if (h < 5) return 'Gute Nacht';
            if (h < 11) return 'Guten Morgen';
            if (h < 17) return 'Guten Tag';
            if (h < 22) return 'Guten Abend';
            return 'Gute Nacht';
        },

        // Zuletzt hinzugefuegte Sammlungs-Eintraege (Kopie, nach Datum sortiert).
        homeRecentAdded(n = 12) {
            return [...this.collection]
                .filter((it) => it.addedAt)
                .sort((a, b) => (b.addedAt || 0) - (a.addedAt || 0))
                .slice(0, n);
        },

        // Wertvollste Karte (Highlight im Hero), falls vorhanden.
        heroCard() {
            const top = this.stats && this.stats.topCards;
            return (top && top.length) ? top[0] : null;
        },

        // Anzahl verschiedener Sets in der Sammlung.
        collectionSetCount() {
            return (this.stats && this.stats.bySet) ? this.stats.bySet.length : 0;
        },

        // Setzt den Such-Filter (Alle/DE/JA) und sucht neu.
        setSearchLang(id) {
            if (!['all', 'de', 'ja'].includes(id) || this.searchLang === id) return;
            this.searchLang = id;
            localStorage.setItem('pokelog_searchlang', id);
            this._tok.search++;
            if (this.searchQuery && this.searchQuery.trim().length >= 1) this.runSearch();
            // Scanner laeuft -> Fingerabdruck-Tabelle der neuen Sprache laden.
            if (this.cameraActive) this.ensureHashes();
        },

        // Wechselt die Sprache der Set-Ansicht.
        setSetsLang(id) {
            if (!['de', 'ja'].includes(id) || this.setsLang === id) return;
            this.setsLang = id;
            localStorage.setItem('pokelog_setslang', id);
            this._tok.sets++; this._tok.setView++;
            this.openSetData = null;
            this.loadSets(true);
        },

        async registerServiceWorker() {
            if (!('serviceWorker' in navigator)) return;
            try { await navigator.serviceWorker.register('sw.js'); } catch (e) { /* optional */ }
        },

        // ============================================================ API-Helfer
        async api(path, options = {}) {
            const res = await fetch('api.php' + path, {
                headers: { 'Content-Type': 'application/json' },
                ...options,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
            return data;
        },

        showToast(msg) {
            this.toast = msg;
            clearTimeout(this._toastTimer);
            this._toastTimer = setTimeout(() => (this.toast = ''), 2600);
        },

        // ============================================================ Sammlung
        async loadCollection() {
            if (this.isGuest()) {
                // Preise nur einmal (bzw. via refreshPrices) holen; Filter/Sortierung
                // arbeiten danach rein lokal auf der bereits bewerteten Sammlung.
                if (this._guestLoaded) { this.guestApplyView(); return; }
                return this.guestRecompute();
            }
            this.loading = true;
            try {
                const params = new URLSearchParams({ action: 'collection' });
                if (this.collectionSet) params.set('set', this.collectionSet);
                if (this.collectionQuery) params.set('q', this.collectionQuery);
                if (this.collectionSort) params.set('sort', this.collectionSort);
                const data = await this.api('?' + params.toString());
                this.collection = data.items || [];
                this.buildSetOptions();
            } catch (e) {
                this.showToast('Fehler beim Laden: ' + e.message);
            } finally {
                this.loading = false;
            }
        },

        buildSetOptions() {
            // Set-Optionen nur ergaenzen, nicht ersetzen (damit Filter erhalten bleibt).
            const seen = new Map(this.setOptions.map((s) => [s.id, s]));
            for (const it of this.collection) {
                if (it.setId && !seen.has(it.setId)) {
                    seen.set(it.setId, { id: it.setId, name: it.setName || it.setId });
                }
            }
            this.setOptions = [...seen.values()].sort((a, b) => a.name.localeCompare(b.name));
        },

        async loadStats() {
            if (this.isGuest()) { this.guestComputeStats(); return; }
            try {
                const data = await this.api('?action=stats');
                this.stats = data.stats;
            } catch (e) {
                /* still */
            }
        },

        async changeQty(item, delta) {
            if (this.isGuest()) return this.guestChangeQty(item, delta);
            const newQty = item.quantity + delta;
            try {
                const data = await this.api('?action=item&id=' + item.id, {
                    method: 'PATCH',
                    body: JSON.stringify({ quantity: newQty }),
                });
                if (data.deleted) {
                    this.collection = this.collection.filter((i) => i.id !== item.id);
                } else if (data.item) {
                    const idx = this.collection.findIndex((i) => i.id === item.id);
                    if (idx >= 0) this.collection[idx] = data.item;
                }
                this.loadStats();
            } catch (e) {
                this.showToast('Konnte Menge nicht ändern: ' + e.message);
            }
        },

        exportCsv() {
            if (this.isGuest()) return this.guestExport();
            // Direkter Download ueber den Browser (CSV-Endpoint).
            window.location.href = 'api.php?action=export';
            this.showToast('Sammlung wird als CSV exportiert…');
        },

        async refreshPrices() {
            if (this.isGuest()) {
                this.refreshing = true;
                try {
                    // Gast: Preise frisch vom (oeffentlichen) Preis-Endpoint holen.
                    await this.guestRecompute(true);
                    this.showToast('Preise aktualisiert.');
                } finally { this.refreshing = false; }
                return;
            }
            this.refreshing = true;
            try {
                const data = await this.api('?action=prices.refresh', {
                    method: 'POST',
                    body: JSON.stringify({ onlyStale: false }),
                });
                const r = data.result || {};
                this.showToast(`Preise aktualisiert: ${r.updated} erneuert, ${r.skipped} aktuell`);
                await this.loadCollection();
                await this.loadStats();
            } catch (e) {
                this.showToast('Preis-Update fehlgeschlagen: ' + e.message);
            } finally {
                this.refreshing = false;
            }
        },

        // ============================================================ Sofortsuche
        // Laedt den kompakten Such-Index einer Sprache genau einmal und bereitet
        // ihn fuer die clientseitige Suche auf (lowercase-Felder, Kuerzel-Set).
        async loadIndex(lang) {
            lang = (lang === 'ja') ? 'ja' : 'de';
            if (this.index[lang]) return this.index[lang];
            this.indexLoading = true;
            try {
                const data = await this.api('?action=index&lang=' + lang);
                const rows = data.cards || [];
                const abbr = new Set();
                for (const r of rows) {
                    // Vorberechnete, kleingeschriebene Suchfelder.
                    r._n = (r.n || '').toLowerCase();
                    r._a = ((r.en || '') + ' ' + (r.de || '')).toLowerCase().trim();
                    r._c = (r.a || '').toLowerCase();
                    if (r._c) abbr.add(r._c);
                }
                this.index[lang] = rows;
                this._abbrSet[lang] = abbr;
                return rows;
            } catch (e) {
                this.showToast('Such-Index konnte nicht geladen werden: ' + e.message);
                return [];
            } finally {
                this.indexLoading = false;
            }
        },

        async loadOwned(lang) {
            lang = (lang === 'ja') ? 'ja' : 'de';
            if (this.isGuest()) { this.ownedMap[lang] = this.guestOwnedMap(lang); return; }
            try {
                const data = await this.api('?action=owned&lang=' + lang);
                this.ownedMap[lang] = data.owned || {};
            } catch (e) { /* Besitz-Markierung ist optional */ }
        },

        hasJP(s) { return /[\u3040-\u30ff\u4e00-\u9faf]/.test(s); },

        // Haupteinstieg: blitzschnelle, rein lokale Suche ueber beide Kataloge.
        async runSearch() {
            const raw = this.searchQuery.trim();
            this.searchMode = 'name';
            this.searchSet = null;
            this.searchNote = '';
            if (raw.length < 1) {
                this.searchResults = [];
                this.searchTotal = 0;
                this.searching = false;
                return;
            }

            // Welche Kataloge durchsuchen? Filter + Kanji-Autoerkennung.
            let langs;
            if (this.searchLang === 'de') langs = ['de'];
            else if (this.searchLang === 'ja') langs = ['ja'];
            else langs = this.hasJP(raw) ? ['ja', 'de'] : ['de', 'ja'];

            const tok = ++this._tok.search;
            const need = langs.filter((l) => !this.index[l]);
            if (need.length) {
                this.searching = true;
                await Promise.all(need.map((l) => this.loadIndex(l)));
                if (tok !== this._tok.search) return; // Eingabe hat sich geaendert
                this.searching = false;
            }

            const scored = [];
            for (const l of langs) {
                const rows = this.index[l];
                if (rows) this.scoreInto(raw, l, rows, scored);
            }
            scored.sort((a, b) => b.score - a.score || a.r._n.length - b.r._n.length);

            this.searchTotal = scored.length;
            this.searchResults = scored.slice(0, 80)
                .map((s) => this.idxToCard(s.r, s.lang, this.ownedMap[s.lang] || {}));

            // Erkanntes Set (fuer den Hinweis-Chip) aus dem ersten Treffer ableiten.
            const top = scored[0];
            if (top && top.codeTok) {
                this.searchMode = top.mode;
                this.searchSet = { name: top.r.s, abbr: top.r.a || top.codeTok.toUpperCase() };
            } else if (top) {
                this.searchMode = top.mode;
            }
            this.fillPrices(this.searchResults);
        },

        // Ranking-Engine fuer einen Katalog: erkennt Name, Set-Kuerzel + Nummer
        // und Kombinationen. Schreibt bewertete Treffer in `out`.
        scoreInto(raw, lang, rows, out) {
            const owned = this.ownedMap[lang] || {};
            const abbrSet = this._abbrSet[lang] || new Set();
            const q = raw.toLowerCase();
            let tokens = q.split(/\s+/).filter(Boolean);

            // "mep047" -> "mep 047" aufspalten, wenn das Praefix ein Kuerzel ist.
            if (tokens.length === 1) {
                const m = tokens[0].match(/^([a-z]{1,5})[-]?(\d{1,4})$/i);
                if (m && abbrSet.has(m[1])) tokens = [m[1], m[2]];
            }

            let numTok = null, codeTok = null;
            const nameToks = [];
            for (const t of tokens) {
                if (numTok === null && /^\d{1,4}$/.test(t)) { numTok = t; continue; }
                if (codeTok === null && abbrSet.has(t)) { codeTok = t; continue; }
                nameToks.push(t);
            }
            const nameQ = nameToks.join(' ');
            const numNorm = numTok !== null ? String(parseInt(numTok, 10)) : null;
            const numHard = numTok !== null && nameToks.length === 0;
            const codeHard = codeTok !== null;
            let mode = 'name';
            if (codeTok && numTok && !nameQ) mode = 'number';
            else if (codeTok && nameQ) mode = 'combo';
            else if (numHard) mode = 'number';

            for (const r of rows) {
                if (codeHard && r._c !== codeTok) continue;

                const localMatch = numTok !== null &&
                    (r.l === numTok || String(parseInt(r.l, 10)) === numNorm);
                if (numHard && !localMatch) continue;

                let score = 0;
                if (nameQ) {
                    const hay = lang === 'ja' ? (r._n + ' ' + r._a) : r._n;
                    let ok = true;
                    for (const t of nameToks) { if (!hay.includes(t)) { ok = false; break; } }
                    if (!ok) continue;
                    const primary = r._n + (r._a ? ' ' + r._a : '');
                    if (r._n === nameQ || r._a === nameQ) score += 1000;
                    else if (primary.startsWith(nameQ)) score += 600;
                    else if (new RegExp('\\b' + this._rx(nameQ)).test(primary)) score += 300;
                    else score += 120;
                    score -= Math.min(40, r._n.length);
                } else if (!codeHard && !numHard) {
                    continue;
                }
                if (codeTok && r._c === codeTok) score += 200;
                if (localMatch) score += (numHard ? 400 : 150);
                if ((owned[r.id] || 0) > 0) score += 5;
                // DE bei Gleichstand leicht bevorzugen (gewohnter Markt).
                if (lang === 'de') score += 1;

                out.push({ r, score, lang, mode, codeTok });
            }
        },

        // Regex-Escape fuer dynamische Wortgrenzen-Suche.
        _rx(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); },

        // Index-Eintrag -> Anzeigeobjekt (kompatibel zu den Karten-Templates).
        idxToCard(r, lang, owned) {
            return {
                id: r.id,
                lang,
                name: r.n,
                nameEn: r.en || null,
                nameDe: r.de || null,
                nameAlt: null,
                set: r.s,
                setId: r.sid,
                setTotal: r.t || null,
                abbr: r.a || null,
                localId: r.l,
                image: r.img ? r.img + '/low.webp' : null,
                owned: owned[r.id] || 0,
                pricing: null,
            };
        },

        // Enter in der Suche: oeffnet direkt den ersten Treffer.
        searchEnter() {
            if (this.searchResults.length) this.openCard(this.searchResults[0]);
        },

        // ============================================================ Sets-Browser
        async loadSets(force = false) {
            if (this.setsLoadedLang === this.setsLang && !force) return;
            this.setsLoading = true;
            const tok = ++this._tok.sets;
            try {
                const data = await this.api('?action=sets&lang=' + this.setsLang);
                if (tok !== this._tok.sets) return;
                this.sets = data.sets || [];
                this.setsLoadedLang = this.setsLang;
                this.groupSets();
            } catch (e) {
                if (tok === this._tok.sets) this.showToast('Sets konnten nicht geladen werden: ' + e.message);
            } finally {
                if (tok === this._tok.sets) this.setsLoading = false;
            }
        },

        // Erkennt Sets des Handyspiels „Pokémon TCG Pocket" (eigene Serie).
        isPocketSet(s) {
            const ser = s.serie || '';
            return /pocket/i.test(ser) || ser.includes('ポケット');
        },

        // Anzahl Sets einer Ansicht ('tcg'|'pocket') im aktuellen Katalog.
        setCount(view) {
            const wantPocket = view === 'pocket';
            return this.sets.filter((s) => this.isPocketSet(s) === wantPocket).length;
        },

        // Wechselt zwischen klassischen Sets und Pocket-Sets.
        setSetsView(view) {
            if (this.setsView === view) return;
            this.setsView = view;
            this.openSetData = null;
            this.groupSets();
        },

        groupSets() {
            const q = this.setFilter.trim().toLowerCase();
            const wantPocket = this.setsView === 'pocket';
            const groups = new Map();
            for (const s of this.sets) {
                // Pocket-Sets nur in der Pocket-Ansicht, sonst ausblenden.
                if (this.isPocketSet(s) !== wantPocket) continue;
                const hay = `${s.name} ${s.nameEn || ''} ${s.nameRomaji || ''} ${s.abbr || ''} ${s.id}`.toLowerCase();
                if (q && !hay.includes(q)) continue;
                const key = s.serie || 'Sonstige';
                if (!groups.has(key)) groups.set(key, []);
                groups.get(key).push(s);
            }
            // Reihenfolge der Serien nach neuestem Set in der Gruppe.
            this.setGroups = [...groups.entries()]
                .map(([serie, items]) => ({ serie, items }))
                .sort((a, b) => (b.items[0]?.releaseDate || '').localeCompare(a.items[0]?.releaseDate || ''));
        },

        async openSetView(set) {
            this.setCardsLoading = true;
            this.openSetData = { set, cards: [] };
            const tok = ++this._tok.setView;
            const setLang = set.lang || this.setsLang;
            // Deeplink setzen (dedupliziert, wenn der Aufruf vom Router stammt).
            this.navigate(this._setHash(set.id, setLang));
            try {
                const data = await this.api('?action=set&lang=' + setLang + '&id=' + encodeURIComponent(set.id));
                if (tok !== this._tok.setView) return;
                this.openSetData = { set: data.set || set, cards: data.cards || [] };
                this.fillPrices(this.openSetData.cards);
            } catch (e) {
                if (tok === this._tok.setView) {
                    this.showToast('Set konnte nicht geladen werden: ' + e.message);
                    this.openSetData = null;
                }
            } finally {
                if (tok === this._tok.setView) this.setCardsLoading = false;
            }
        },

        closeSetView() {
            // Ueber die History schliessen, damit der Zurueck-Stack konsistent
            // bleibt; der bootRoute()-Stack haelt uns bei Deeplinks in der App.
            history.back();
        },

        // Holt fehlende Cardmarket-Preise fuer eine Liste angezeigter Karten
        // nach (in kleinen Bloecken) und traegt sie reaktiv ein. Preise werden
        // serverseitig gecacht, beim naechsten Mal sind sie sofort da.
        async fillPrices(list) {
            if (!Array.isArray(list) || !list.length) return;
            // Fehlende Preise nach Katalogsprache der jeweiligen Karte gruppieren.
            const byLang = {};
            for (const r of list) {
                if (!r || !r.id || r._priced || (r.pricing && r.pricing.trend != null)) continue;
                const l = r.lang || 'de';
                (byLang[l] = byLang[l] || []).push(r.id);
            }
            const chunkSize = 15;
            const chunks = [];
            for (const [l, ids] of Object.entries(byLang)) {
                for (let i = 0; i < ids.length; i += chunkSize) {
                    chunks.push({ lang: l, ids: ids.slice(i, i + chunkSize) });
                }
            }
            if (!chunks.length) return;

            let next = 0;
            const worker = async () => {
                while (next < chunks.length) {
                    const c = chunks[next++];
                    if (this._priceListStale(list)) return; // Ansicht gewechselt
                    try {
                        const data = await this.api('?action=prices&lang=' + c.lang + '&ids=' + encodeURIComponent(c.ids.join(',')));
                        const map = data.prices || {};
                        for (const r of list) {
                            if (r && Object.prototype.hasOwnProperty.call(map, r.id)) {
                                if (map[r.id]) r.pricing = map[r.id];
                                r._priced = true;
                            }
                        }
                    } catch (e) { /* still – Preise sind optional */ }
                }
            };
            await Promise.all(Array.from({ length: Math.min(3, chunks.length) }, worker));
        },

        // Wurde inzwischen die Ansicht gewechselt? Dann Preis-Nachladen stoppen.
        _priceListStale(list) {
            if (list === this.searchResults) return false;
            if (this.openSetData && list === this.openSetData.cards) return false;
            if (list === this.scanMatches) return false;
            if (this.cardView && this.cardView.related &&
                (list === this.cardView.related.set || list === this.cardView.related.prints)) return false;
            return true;
        },

        async rebuildSets() {
            this.rebuildingSets = true;
            this.showToast('Set-Verzeichnis wird aktualisiert… (dauert etwas)');
            try {
                const data = await this.api('?action=sets.rebuild', { method: 'POST' });
                const r = data.result || {};
                this.showToast(`Set-Verzeichnis aktualisiert: ${r.sets} Sets (${r.cards} Karten)`);
                if (this.searchQuery) this.runSearch();
                if (this.tab === 'sets') { this.openSetData = null; await this.loadSets(true); }
            } catch (e) {
                this.showToast('Aktualisierung fehlgeschlagen: ' + e.message);
            } finally {
                this.rebuildingSets = false;
            }
        },

        // Berechnet die visuellen Fingerabdruecke (Perceptual-Hash) aller
        // Kartenbilder fuer den Scanner. Laeuft serverseitig in Chargen; der
        // Client pollt, bis nichts mehr offen ist. Resumierbar.
        async buildScanHashes() {
            if (this.buildingHashes) return;
            this.buildingHashes = true;
            this.hashProgress = '';
            this.showToast('Scanner-Fingerabdrücke werden berechnet… (kann einige Minuten dauern)');
            try {
                let prevRemaining = Infinity;
                while (true) {
                    const data = await this.api('?action=scan.hashes.build', {
                        method: 'POST',
                        body: JSON.stringify({ batch: 200 }),
                    });
                    const r = data.result || {};
                    if (r.error) { this.showToast(r.error); break; }
                    const total = r.total || 0;
                    this.hashProgress = total ? (r.done + ' / ' + total) : '…';
                    if (!r.remaining || r.remaining <= 0) {
                        this.showToast('Scanner-Fingerabdrücke fertig: ' + (r.done || 0) + ' Karten.');
                        // Frische Tabellen erzwingen (Cache leeren).
                        this._hashByLang = {};
                        this.scanHashes = null;
                        break;
                    }
                    // Kein Fortschritt mehr -> abbrechen (Sicherheitsnetz).
                    if (r.remaining >= prevRemaining) {
                        this.showToast('Abbruch ohne Fortschritt bei ' + r.remaining + ' offenen Karten.');
                        this._hashByLang = {};
                        this.scanHashes = null;
                        break;
                    }
                    prevRemaining = r.remaining;
                }
            } catch (e) {
                this.showToast('Fingerabdruck-Berechnung fehlgeschlagen: ' + e.message);
            } finally {
                this.buildingHashes = false;
                this.hashProgress = '';
            }
        },

        // ============================================================ Karten-Detail
        // Oeffnet die Detailseite einer Karte (Stammdaten, Preis, Besitz,
        // verwandte Karten) und bereitet das Hinzufuegen-Formular vor.
        async openCard(card) {
            const cardLang = card.lang || 'de';
            this.cardView = { loading: true, lang: cardLang, base: card, card: null, owned: card.owned || 0, names: null, price: card.pricing || null, override: null, related: { set: [], prints: [] } };
            this.overrideInput = '';
            // Deeplink setzen (dedupliziert, wenn der Aufruf vom Router stammt).
            this.navigate(this._cardHash(card.id, cardLang));
            this.pushRecent(card, cardLang);
            // Hinzufuegen-Formular vorbelegen (wird nach Laden ggf. verfeinert).
            // Druck-Sprache = Katalog der Karte (JA-Karten sind eigene Karten).
            this.addCard = {
                id: card.id, lang: cardLang, name: card.name,
                nameDe: card.nameDe || null, nameEn: card.nameEn || null,
                image: card.image, set: card.set || card.setName || null,
                setId: card.setId || null, setTotal: card.setTotal || null,
                localId: card.localId || null, variants: ['normal'],
            };
            this.addForm = { variant: 'normal', condition: 'NM', language: cardLang === 'ja' ? 'ja' : 'de', quantity: 1, catalogLang: cardLang };

            const tok = ++this._tok.card;
            try {
                const data = await this.api('?action=card&lang=' + cardLang + '&id=' + encodeURIComponent(card.id));
                if (tok !== this._tok.card) return; // andere Karte geoeffnet
                const c = data.card || {};
                const vs = [];
                if (c.variants) {
                    if (c.variants.normal) vs.push('normal');
                    if (c.variants.holo) vs.push('holo');
                    if (c.variants.reverse) vs.push('reverse');
                }
                this.addCard.variants = vs.length ? vs : ['normal'];
                this.addForm.variant = this.addCard.variants[0];
                if (!this.addCard.image && c.image) this.addCard.image = c.image + '/high.webp';
                // Set-Infos fuer den Gast-Modus (localStorage) ergaenzen.
                if (c.set) {
                    this.addCard.setId = this.addCard.setId || c.set.id || null;
                    this.addCard.set = this.addCard.set || c.set.name || null;
                    const ct = c.set.cardCount || {};
                    this.addCard.setTotal = this.addCard.setTotal || ct.official || ct.total || null;
                }
                // Stammdaten ergaenzen: Bei einem Deeplink kennt die Karte nur
                // ID + Sprache; Name/Nummer/Bild kommen erst aus der Detailabfrage.
                if (!card.name && c.name) card.name = c.name;
                if ((card.localId == null || card.localId === '') && c.localId != null) card.localId = c.localId;
                if (!card.image && c.image) card.image = c.image + '/low.webp';
                if (!card.set && c.set && c.set.name) card.set = c.set.name;
                this.addCard.name = this.addCard.name || c.name || card.name || '';
                if (!this.addCard.localId && c.localId != null) this.addCard.localId = c.localId;
                // Zuletzt-angesehen mit vollstaendigen Daten aktualisieren.
                this.pushRecent(card, cardLang);

                this.cardView = {
                    loading: false, lang: data.lang || cardLang, base: card,
                    card: c, owned: data.owned || 0, names: data.names || null,
                    price: data.price || null, override: data.override ?? null,
                    related: data.related || { set: [], prints: [] },
                };
                this.overrideInput = data.override != null ? String(data.override) : '';
                this.fillPrices(this.cardView.related.set);
                this.fillPrices(this.cardView.related.prints);
            } catch (e) {
                if (tok === this._tok.card) {
                    this.showToast('Karte konnte nicht geladen werden: ' + e.message);
                    if (this.cardView) this.cardView.loading = false;
                }
            }
        },

        // Grosses Bild der Detailseite (high-res), faellt auf Listenbild zurueck.
        cardHero() {
            const cv = this.cardView;
            if (!cv) return this.placeholder;
            if (cv.card && cv.card.image) return cv.card.image + '/high.webp';
            return (cv.base && cv.base.image) || this.placeholder;
        },

        closeCard() {
            // Ueber die History schliessen; der bootRoute()-Stack haelt uns bei
            // Deeplinks in der App (statt die Seite ganz zu verlassen).
            history.back();
        },

        // Setzt eine manuelle Preis-Korrektur (uebersteuert die Quelle).
        async saveOverride() {
            if (!this.cardView || !this.addCard) return;
            const price = parseFloat(String(this.overrideInput).replace(',', '.'));
            if (isNaN(price) || price < 0) { this.showToast('Bitte einen gültigen Preis eingeben.'); return; }
            if (this.isGuest()) {
                this.guestSetOverride(this.addCard.id, price);
                this.cardView.override = price;
                this.showToast('Eigener Preis gespeichert ✓');
                await this.guestRecompute();
                return;
            }
            this.savingOverride = true;
            try {
                const data = await this.api('?action=override', {
                    method: 'POST',
                    body: JSON.stringify({ cardId: this.addCard.id, price }),
                });
                this.cardView.override = data.override ?? price;
                this.showToast('Eigener Preis gespeichert ✓');
                await this.loadCollection();
                await this.loadStats();
            } catch (e) {
                this.showToast('Speichern fehlgeschlagen: ' + e.message);
            } finally {
                this.savingOverride = false;
            }
        },

        async clearOverride() {
            if (!this.cardView || !this.addCard) return;
            if (this.isGuest()) {
                this.guestSetOverride(this.addCard.id, null);
                this.cardView.override = null;
                this.overrideInput = '';
                this.showToast('Eigener Preis entfernt.');
                await this.guestRecompute();
                return;
            }
            this.savingOverride = true;
            try {
                await this.api('?action=override', {
                    method: 'POST',
                    body: JSON.stringify({ cardId: this.addCard.id, price: null }),
                });
                this.cardView.override = null;
                this.overrideInput = '';
                this.showToast('Eigener Preis entfernt.');
                await this.loadCollection();
                await this.loadStats();
            } catch (e) {
                this.showToast('Entfernen fehlgeschlagen: ' + e.message);
            } finally {
                this.savingOverride = false;
            }
        },

        // Cardmarket-Suchlink zum echten Preis-Check. Bei JA mit EN/DE-Name,
        // und mit Sammlernummer ergaenzt -> deutlich praezisere Treffer.
        _priceQuery() {
            const cv = this.cardView;
            if (!cv) return '';
            const name = (cv.names && (cv.names.en || cv.names.de)) || cv.base.name || '';
            const localId = (cv.card && cv.card.localId) || cv.base.localId || '';
            return (name + ' ' + localId).trim();
        },

        // Cardmarket-Suche (EUR-Marktwert hierzulande) – fuer DE und JP.
        cardmarketUrl() {
            return 'https://www.cardmarket.com/de/Pokemon/Products/Search?searchString=' + encodeURIComponent(this._priceQuery());
        },

        // TCGplayer-Suche (Japan-Markt, USD) – Preisquelle fuer JP-Karten.
        tcgplayerUrl() {
            return 'https://www.tcgplayer.com/search/pokemon-japan/product?productLineName=pokemon-japan&q=' + encodeURIComponent(this._priceQuery());
        },

        // Merkt die zuletzt angesehenen Karten (lokal, max. 12).
        pushRecent(card, lang) {
            const entry = {
                id: card.id, lang: lang || card.lang || 'de', name: card.name,
                nameEn: card.nameEn || null, nameDe: card.nameDe || null,
                set: card.set || card.setName || null, localId: card.localId || null,
                image: card.image || null,
            };
            this.recent = [entry, ...this.recent.filter((r) => r.id !== entry.id)].slice(0, 12);
            try { localStorage.setItem('pokelog_recent', JSON.stringify(this.recent)); } catch (e) { /* egal */ }
        },

        clearRecent() {
            this.recent = [];
            try { localStorage.removeItem('pokelog_recent'); } catch (e) { /* egal */ }
        },

        async confirmAdd() {
            if (!this.addCard) return;
            if (this.isGuest()) return this.guestAdd();
            this.addBusy = true;
            const name = this.addCard.name;
            const qty = Math.max(1, parseInt(this.addForm.quantity, 10) || 1);
            try {
                await this.api('?action=collection', {
                    method: 'POST',
                    body: JSON.stringify({
                        cardId: this.addCard.id,
                        catalogLang: this.addCard.lang || this.addForm.catalogLang || 'de',
                        variant: this.addForm.variant,
                        condition: this.addForm.condition,
                        language: this.addForm.language,
                        quantity: qty,
                    }),
                });
                this.bumpOwned(this.addCard.id, qty, this.addCard.lang);
                if (this.cardView && this.cardView.base && this.cardView.base.id === this.addCard.id) {
                    this.cardView.owned = (this.cardView.owned || 0) + qty;
                }
                this.showToast(name + ' hinzugefügt ✓ (×' + qty + ')');
                await this.loadCollection();
                await this.loadStats();
            } catch (e) {
                this.showToast('Hinzufügen fehlgeschlagen: ' + e.message);
            } finally {
                this.addBusy = false;
            }
        },

        // Erhoeht die owned-Anzeige in laufenden Listen + Besitz-Map ohne Neuladen.
        bumpOwned(cardId, qty, lang) {
            lang = (lang === 'ja') ? 'ja' : 'de';
            const map = this.ownedMap[lang] || (this.ownedMap[lang] = {});
            map[cardId] = (map[cardId] || 0) + qty;
            const lists = [this.searchResults, this.scanMatches, this.recent,
                this.openSetData && this.openSetData.cards,
                this.cardView && this.cardView.related && this.cardView.related.set,
                this.cardView && this.cardView.related && this.cardView.related.prints].filter(Boolean);
            for (const list of lists) {
                for (const r of list) {
                    if (r && r.id === cardId) r.owned = (r.owned || 0) + qty;
                }
            }
            if (this.openSetData && this.openSetData.set && this.openSetData.cards) {
                const oc = this.openSetData.cards.filter((c) => (c.owned || 0) > 0).length;
                this.openSetData.set.ownedCount = oc;
            }
        },

        // ============================================================ Gast-Sammlung
        // Ohne Login wird die Sammlung ausschliesslich im localStorage gehalten.
        // Sie ist NICHT geraeteuebergreifend und nicht gesichert.
        _GUEST_KEY: 'pokelog_guest_collection',
        _GUEST_OVR_KEY: 'pokelog_guest_overrides',
        _guestValued: [],
        _guestLoaded: false,

        guestRead() {
            try { return JSON.parse(localStorage.getItem(this._GUEST_KEY) || '[]') || []; }
            catch (e) { return []; }
        },
        guestWrite(arr) {
            try { localStorage.setItem(this._GUEST_KEY, JSON.stringify(arr)); } catch (e) { /* Speicher voll? */ }
        },
        guestOverridesRead() {
            try { return JSON.parse(localStorage.getItem(this._GUEST_OVR_KEY) || '{}') || {}; }
            catch (e) { return {}; }
        },
        guestSetOverride(cardId, price) {
            const o = this.guestOverridesRead();
            if (price == null) delete o[cardId]; else o[cardId] = price;
            try { localStorage.setItem(this._GUEST_OVR_KEY, JSON.stringify(o)); } catch (e) { /* egal */ }
        },
        _guestId() { return Date.now() * 1000 + Math.floor(Math.random() * 1000); },

        guestOwnedMap(lang) {
            lang = (lang === 'ja') ? 'ja' : 'de';
            const map = {};
            for (const e of this.guestRead()) {
                if ((e.catalogLang || 'de') !== lang) continue;
                map[e.cardId] = (map[e.cardId] || 0) + (e.quantity || 0);
            }
            return map;
        },

        _unitForVariant(p, variant) {
            if (!p) return null;
            const pick = (...keys) => { for (const k of keys) { if (p[k] != null && !isNaN(p[k])) return p[k]; } return null; };
            if (variant === 'holo' || variant === 'reverse') return pick('trendHolo', 'trend', 'avg', 'low');
            return pick('trend', 'avg', 'low', 'trendHolo');
        },

        _sortItems(items, sort) {
            const arr = [...items];
            switch (sort) {
                case 'value': arr.sort((a, b) => (b.lineValue || 0) - (a.lineValue || 0)); break;
                case 'value_asc': arr.sort((a, b) => (a.lineValue || 0) - (b.lineValue || 0)); break;
                case 'name': arr.sort((a, b) => (a.name || '').localeCompare(b.name || '')); break;
                case 'recent': arr.sort((a, b) => (b.addedAt || 0) - (a.addedAt || 0)); break;
                default: arr.sort((a, b) =>
                    (a.setName || '').localeCompare(b.setName || '') ||
                    (parseInt(a.localId, 10) || 0) - (parseInt(b.localId, 10) || 0)); break;
            }
            return arr;
        },

        // Baut Werte/Preise der Gast-Sammlung neu auf (holt fehlende Preise vom
        // oeffentlichen Preis-Endpoint) und aktualisiert collection/stats/owned.
        async guestRecompute(force = false) {
            const store = this.guestRead();
            const overrides = this.guestOverridesRead();

            // Distinct cardIds je Katalogsprache -> Preise holen.
            const byLang = {};
            for (const e of store) {
                const l = (e.catalogLang === 'ja') ? 'ja' : 'de';
                (byLang[l] = byLang[l] || new Set()).add(e.cardId);
            }
            const priceMap = {};
            for (const [l, set] of Object.entries(byLang)) {
                const ids = [...set];
                for (let i = 0; i < ids.length; i += 40) {
                    const chunk = ids.slice(i, i + 40);
                    try {
                        const data = await this.api('?action=prices&lang=' + l + '&ids=' + encodeURIComponent(chunk.join(',')));
                        Object.assign(priceMap, data.prices || {});
                    } catch (e) { /* Preise optional */ }
                }
            }

            this._guestValued = store.map((e) => {
                const p = priceMap[e.cardId] || null;
                const hasOvr = Object.prototype.hasOwnProperty.call(overrides, e.cardId);
                const unit = hasOvr ? overrides[e.cardId] : this._unitForVariant(p, e.variant);
                return {
                    id: e.id, cardId: e.cardId, lang: e.catalogLang || 'de',
                    name: e.name, nameDe: e.nameDe || null, nameEn: e.nameEn || null, nameAlt: null,
                    localId: e.localId || null, setId: e.setId || null, setName: e.setName || null,
                    setTotal: e.setTotal || null, rarity: null,
                    image: e.image || null, imageHigh: e.imageHigh || e.image || null,
                    variant: e.variant, condition: e.condition, language: e.language,
                    quantity: e.quantity, notes: e.notes || null, addedAt: e.addedAt || null,
                    currency: (p && p.currency) || 'EUR',
                    unitPrice: unit, lineValue: unit != null ? Math.round(unit * e.quantity * 100) / 100 : null,
                    priceManual: hasOvr,
                    prices: p ? { low: p.low ?? null, trend: p.trend ?? null, avg: p.avg ?? null, trendHolo: p.trendHolo ?? null } : {},
                };
            });

            // Besitz-Maps neu aufbauen (autoritativ).
            this.ownedMap = { de: this.guestOwnedMap('de'), ja: this.guestOwnedMap('ja') };
            this._guestLoaded = true;
            this.guestApplyView();
            this.guestComputeStats();
        },

        // Filtert/sortiert die bereits bewertete Gast-Sammlung (ohne Preisabruf).
        guestApplyView() {
            const seen = new Map();
            for (const it of this._guestValued) {
                if (it.setId && !seen.has(it.setId)) seen.set(it.setId, { id: it.setId, name: it.setName || it.setId });
            }
            this.setOptions = [...seen.values()].sort((a, b) => a.name.localeCompare(b.name));

            let view = this._guestValued;
            if (this.collectionSet) view = view.filter((i) => i.setId === this.collectionSet);
            if (this.collectionQuery) {
                const q = this.collectionQuery.toLowerCase();
                view = view.filter((i) => (i.name || '').toLowerCase().includes(q));
            }
            this.collection = this._sortItems(view, this.collectionSort);
        },

        guestComputeStats() {
            const rows = this._guestValued || [];
            let totalQty = 0, totalValue = 0;
            const uniq = new Set();
            const bySet = new Map();
            for (const it of rows) {
                totalQty += it.quantity;
                uniq.add(it.cardId);
                if (it.lineValue != null) totalValue += it.lineValue;
                const key = it.setName || 'Unbekannt';
                if (!bySet.has(key)) bySet.set(key, { set: key, count: 0, value: 0 });
                const b = bySet.get(key);
                b.count += it.quantity;
                b.value += it.lineValue || 0;
            }
            const bySetList = [...bySet.values()].map((s) => ({ ...s, value: Math.round(s.value * 100) / 100 }))
                .sort((a, b) => b.value - a.value);
            const top = rows.filter((i) => i.unitPrice != null)
                .sort((a, b) => (b.unitPrice || 0) - (a.unitPrice || 0)).slice(0, 10);
            this.stats = {
                totalQuantity: totalQty, uniqueCards: uniq.size,
                totalValue: Math.round(totalValue * 100) / 100, currency: 'EUR',
                bySet: bySetList, topCards: top, distinctEntries: rows.length,
            };
        },

        guestAdd() {
            const c = this.addCard;
            if (!c) return;
            const qty = Math.max(1, parseInt(this.addForm.quantity, 10) || 1);
            const lang = c.lang || this.addForm.catalogLang || 'de';
            const variant = this.addForm.variant, condition = this.addForm.condition, language = this.addForm.language;
            const store = this.guestRead();
            const match = (e) => e.cardId === c.id && (e.catalogLang || 'de') === lang &&
                e.variant === variant && e.condition === condition && e.language === language;
            let entry = store.find(match);
            if (entry) {
                entry.quantity += qty; entry.addedAt = Date.now();
            } else {
                store.push({
                    id: this._guestId(), cardId: c.id, catalogLang: lang,
                    name: c.name, nameDe: c.nameDe || null, nameEn: c.nameEn || null,
                    setId: c.setId || null, setName: c.set || c.setName || null,
                    setTotal: c.setTotal || null, localId: c.localId || null,
                    image: c.image || null, imageHigh: null,
                    variant, condition, language, quantity: qty, notes: null, addedAt: Date.now(),
                });
            }
            this.guestWrite(store);
            this.bumpOwned(c.id, qty, lang);
            if (this.cardView && this.cardView.base && this.cardView.base.id === c.id) {
                this.cardView.owned = (this.cardView.owned || 0) + qty;
            }
            this.showToast(c.name + ' lokal gespeichert ✓ (×' + qty + ')');
            this.guestRecompute();
        },

        guestChangeQty(item, delta) {
            const store = this.guestRead();
            const idx = store.findIndex((e) => e.id === item.id);
            if (idx < 0) return;
            const lang = store[idx].catalogLang || 'de';
            const nq = store[idx].quantity + delta;
            if (nq <= 0) store.splice(idx, 1);
            else { store[idx].quantity = nq; store[idx].addedAt = Date.now(); }
            this.guestWrite(store);
            if (nq <= 0) this.collection = this.collection.filter((i) => i.id !== item.id);
            this.bumpOwned(item.cardId, delta, lang);
            this.guestRecompute();
        },

        guestExport() {
            const rows = this._guestValued || [];
            if (!rows.length) { this.showToast('Sammlung ist leer.'); return; }
            const esc = (v) => {
                const s = (v == null ? '' : String(v));
                return /[",\n;]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
            };
            const head = ['Name', 'Set', 'Nummer', 'Sprache', 'Variante', 'Zustand', 'Menge', 'Einzelpreis', 'Gesamtwert', 'Waehrung'];
            const lines = [head.join(',')];
            for (const it of rows) {
                lines.push([it.name, it.setName, it.localId, it.language, it.variant, it.condition,
                    it.quantity, it.unitPrice ?? '', it.lineValue ?? '', it.currency].map(esc).join(','));
            }
            const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = 'pokelog-sammlung.csv'; a.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            this.showToast('Sammlung als CSV exportiert (lokal).');
        },

        // ============================================================ Adminpanel
        async loadUsers() {
            if (!this.isAdmin()) return;
            this.adminLoading = true;
            try {
                const data = await this.api('?action=admin.users');
                this.adminUsers = data.users || [];
            } catch (e) {
                this.showToast('Benutzer konnten nicht geladen werden: ' + e.message);
            } finally {
                this.adminLoading = false;
            }
        },

        async adminCreateUser() {
            this.adminBusy = true; this.adminError = '';
            try {
                await this.api('?action=admin.users', {
                    method: 'POST',
                    body: JSON.stringify({
                        username: this.adminForm.username,
                        password: this.adminForm.password,
                        role: this.adminForm.role,
                    }),
                });
                this.showToast('Benutzer „' + this.adminForm.username + '“ angelegt ✓');
                this.adminForm = { username: '', password: '', role: 'user' };
                await this.loadUsers();
            } catch (e) {
                this.adminError = e.message;
            } finally {
                this.adminBusy = false;
            }
        },

        async adminUpdateUser(user, fields) {
            try {
                await this.api('?action=admin.user&id=' + user.id, {
                    method: 'PATCH',
                    body: JSON.stringify(fields),
                });
                await this.loadUsers();
            } catch (e) {
                this.showToast('Aktualisierung fehlgeschlagen: ' + e.message);
            }
        },

        adminToggleRole(user) {
            this.adminUpdateUser(user, { role: user.role === 'admin' ? 'user' : 'admin' });
        },
        adminToggleActive(user) {
            this.adminUpdateUser(user, { isActive: !user.isActive });
        },
        adminResetPassword(user) {
            const pw = window.prompt('Neues Passwort für „' + user.username + '“ (mind. 6 Zeichen):');
            if (pw == null) return;
            if (pw.length < 6) { this.showToast('Passwort zu kurz (mind. 6 Zeichen).'); return; }
            this.adminUpdateUser(user, { password: pw });
            this.showToast('Passwort von „' + user.username + '“ geändert ✓');
        },
        async adminDeleteUser(user) {
            if (!window.confirm('Benutzer „' + user.username + '“ inkl. Sammlung wirklich löschen?')) return;
            try {
                await this.api('?action=admin.user&id=' + user.id, { method: 'DELETE' });
                this.showToast('Benutzer gelöscht.');
                await this.loadUsers();
            } catch (e) {
                this.showToast('Löschen fehlgeschlagen: ' + e.message);
            }
        },

        // ============================================================ Scan
        // Startet die Kamera (Rueckkamera bevorzugt) und die Live-Erkennung.
        async startCamera() {
            this.scanError = '';
            this.scanHint = 'Kamera wird gestartet …';
            this.scanStatus = 'scanning';
            try {
                // Moeglichst hohe Aufloesung der Hauptkamera anfordern.
                this._stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1920 }, height: { ideal: 1080 },
                    },
                    audio: false,
                });
                const video = this.$refs.video;
                video.srcObject = this._stream;
                await video.play();
                this.cameraActive = true;
                this._camDenied = false;

                // Beste Rueckkamera waehlen + Zoom/Fokus/Torch konfigurieren.
                await this.tuneCamera();

                // Fingerabdruck-Tabelle laden (einmalig) und Live-Loop starten.
                await this.ensureHashes();
                this.startScanLoop();
            } catch (e) {
                this._camDenied = (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError'));
                this.scanStatus = 'idle';
                this.scanHint = '';
                this.scanError = 'Kamera nicht verfügbar: ' + (e && e.message ? e.message : e) +
                    '. Hinweis: Kamerazugriff funktioniert nur über HTTPS oder http://localhost.';
            }
        },

        // Waehlt unter mehreren Linsen die Haupt-Rueckkamera (keine Tele-/Ultraweit-/
        // Tiefen-Linse) und setzt Zoom auf 1x sowie kontinuierlichen Autofokus.
        async tuneCamera() {
            try {
                const track = this._stream && this._stream.getVideoTracks()[0];
                if (!track) return;

                // Geraeteliste (Labels erst nach erteilter Freigabe verfuegbar).
                let devices = [];
                try { devices = await navigator.mediaDevices.enumerateDevices(); } catch (e) { /* egal */ }
                const cams = devices.filter((d) => d.kind === 'videoinput');
                if (cams.length > 1) {
                    const bad = /(ultra|wide|tele|zoom|depth|tiefe|mono|infrared|ir\b|truedepth)/i;
                    const back = /(back|rück|ruck|rear|environment|hinten|world)/i;
                    const backs = cams.filter((c) => back.test(c.label));
                    const pool = backs.length ? backs : cams;
                    // Bevorzugt eine Rueckkamera ohne "tele/ultra/zoom/…" im Namen.
                    let pick = pool.find((c) => back.test(c.label) && !bad.test(c.label))
                        || pool.find((c) => !bad.test(c.label))
                        || pool[0];
                    const current = track.getSettings ? track.getSettings().deviceId : null;
                    if (pick && pick.deviceId && pick.deviceId !== current) {
                        this._stream.getTracks().forEach((t) => t.stop());
                        this._stream = await navigator.mediaDevices.getUserMedia({
                            video: {
                                deviceId: { exact: pick.deviceId },
                                width: { ideal: 1920 }, height: { ideal: 1080 },
                            },
                            audio: false,
                        });
                        const video = this.$refs.video;
                        video.srcObject = this._stream;
                        await video.play();
                    }
                }

                // Faehigkeiten der finalen Spur anwenden.
                const t2 = this._stream.getVideoTracks()[0];
                const caps = t2.getCapabilities ? t2.getCapabilities() : {};
                const adv = [];
                // Zoom auf Minimum (1x) zuruecksetzen -> verhindert "Tele/Zoom"-Effekt.
                if (caps.zoom && typeof caps.zoom.min === 'number') {
                    const s = t2.getSettings ? t2.getSettings() : {};
                    if (!s.zoom || s.zoom > caps.zoom.min) adv.push({ zoom: caps.zoom.min });
                }
                // Kontinuierlicher Autofokus, falls unterstuetzt.
                if (Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) {
                    adv.push({ focusMode: 'continuous' });
                }
                this.torchSupported = !!caps.torch;
                if (adv.length) {
                    try { await t2.applyConstraints({ advanced: adv }); } catch (e) { /* nicht kritisch */ }
                }
            } catch (e) { /* Tuning ist best effort */ }
        },

        // Taschenlampe (falls von der Kamera unterstuetzt) umschalten.
        async toggleTorch() {
            const track = this._stream && this._stream.getVideoTracks()[0];
            if (!track || !this.torchSupported) return;
            try {
                this.torchOn = !this.torchOn;
                await track.applyConstraints({ advanced: [{ torch: this.torchOn }] });
            } catch (e) {
                this.torchOn = false;
                this.showToast('Taschenlampe nicht verfügbar.');
            }
        },

        stopCamera() {
            this._scanRun = false;
            if (this._scanTimer) { clearTimeout(this._scanTimer); this._scanTimer = null; }
            if (this._stream) {
                this._stream.getTracks().forEach((t) => t.stop());
                this._stream = null;
            }
            this.cameraActive = false;
            this.scanLive = false;
            this.torchOn = false;
            this.scanStatus = 'idle';
            this.scanHint = '';
        },

        // ---- Visuelles Matching (Perceptual-Hash / dHash) -----------------
        // Muss exakt mit der Server-Logik in CollectionRepository::dhashFromGd
        // uebereinstimmen: Graustufen, (N+1) x N, Zeilenvergleich, MSB-first.
        _HASH_N: 12,
        _HASH_STRIDE: 18,              // ceil(12*12/8)
        _HASH_MAX_DIST: 38,            // max. Hamming-Distanz fuer einen Treffer
        _HASH_MIN_MARGIN: 4,           // Mindestabstand zum zweitbesten Kandidaten

        // Laedt die Fingerabdruck-Tabelle der aktiven Katalogsprache (gecacht).
        async ensureHashes() {
            const lang = this.searchLang === 'ja' ? 'ja' : 'de';
            if (this._hashByLang[lang]) { this.scanHashes = this._hashByLang[lang]; return this.scanHashes; }
            this.scanHashLoading = true;
            this.scanHint = 'Karten-Fingerabdrücke werden geladen …';
            try {
                if (!this.index[lang]) await this.loadIndex(lang);
                const data = await this.api('?action=scan.hashes&lang=' + lang);
                const cards = data.cards || [];
                const stride = this._HASH_STRIDE;
                const ids = new Array(cards.length);
                const flat = new Uint8Array(cards.length * stride);
                let n = 0;
                for (const c of cards) {
                    const bytes = this._b64ToBytes(c.h);
                    if (!bytes || bytes.length !== stride) continue;
                    ids[n] = c.id;
                    flat.set(bytes, n * stride);
                    n++;
                }
                ids.length = n;
                const tbl = { ids, data: flat.subarray(0, n * stride), stride, lang };
                this._hashByLang[lang] = tbl;
                this.scanHashes = tbl;
                if (n === 0) {
                    this.scanError = 'Noch keine Karten-Fingerabdrücke vorhanden. ' +
                        'Ein Administrator muss sie im Adminbereich einmalig berechnen.';
                }
                return tbl;
            } catch (e) {
                this.scanError = 'Karten-Fingerabdrücke konnten nicht geladen werden: ' + e.message;
                return null;
            } finally {
                this.scanHashLoading = false;
            }
        },

        _b64ToBytes(b64) {
            try {
                const bin = atob(b64);
                const out = new Uint8Array(bin.length);
                for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
                return out;
            } catch (e) { return null; }
        },

        // id -> Index-Zeile (fuer idxToCard), je Sprache gecacht.
        _indexRow(lang, id) {
            let m = this._idxMap[lang];
            if (!m) {
                m = {};
                for (const r of (this.index[lang] || [])) m[r.id] = r;
                this._idxMap[lang] = m;
            }
            return m[id] || null;
        },

        _ensurePopcount() {
            if (this._popcount) return this._popcount;
            const t = new Uint8Array(256);
            for (let i = 0; i < 256; i++) {
                let v = i, c = 0;
                while (v) { c += v & 1; v >>= 1; }
                t[i] = c;
            }
            this._popcount = t;
            return t;
        },

        // Berechnet den dHash des aktuell sichtbaren 63:88-Rahmenausschnitts.
        _frameDHash() {
            const video = this.$refs.video;
            const vw = video && video.videoWidth, vh = video && video.videoHeight;
            if (!vw || !vh) return null;
            const aspect = 63 / 88;
            let sw, sh, sx, sy;
            if (vw / vh > aspect) { sh = vh; sw = Math.round(vh * aspect); sx = Math.round((vw - sw) / 2); sy = 0; }
            else { sw = vw; sh = Math.round(vw / aspect); sx = 0; sy = Math.round((vh - sh) / 2); }

            const N = this._HASH_N, W = N + 1, H = N;
            if (!this._hc) this._hc = document.createElement('canvas');
            const c = this._hc; c.width = W; c.height = H;
            const ctx = c.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(video, sx, sy, sw, sh, 0, 0, W, H);
            let d;
            try { d = ctx.getImageData(0, 0, W, H).data; } catch (e) { return null; }

            const gray = new Float32Array(W * H);
            for (let i = 0, p = 0; i < d.length; i += 4, p++) {
                gray[p] = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
            }
            const bytes = new Uint8Array(this._HASH_STRIDE);
            let bit = 0;
            for (let y = 0; y < H; y++) {
                for (let x = 0; x < N; x++) {
                    if (gray[y * W + x] > gray[y * W + x + 1]) bytes[bit >> 3] |= (0x80 >> (bit & 7));
                    bit++;
                }
            }
            return bytes;
        },

        // Sucht die aehnlichste Karte (kleinste Hamming-Distanz) in der Tabelle.
        _matchHash(frame) {
            const tbl = this.scanHashes;
            if (!tbl || !tbl.ids.length || !frame) return null;
            const pc = this._ensurePopcount();
            const data = tbl.data, stride = tbl.stride, n = tbl.ids.length;
            let best = -1, bestD = 1e9, second = 1e9;
            for (let k = 0; k < n; k++) {
                const off = k * stride;
                let dist = 0;
                for (let i = 0; i < stride; i++) dist += pc[frame[i] ^ data[off + i]];
                if (dist < bestD) { second = bestD; bestD = dist; best = k; }
                else if (dist < second) { second = dist; }
            }
            return { id: tbl.ids[best], lang: tbl.lang, dist: bestD, second };
        },

        // Wandelt eine Treffer-ID in ein anzeigbares Kartenobjekt um.
        _cardFromMatch(id, lang) {
            const row = this._indexRow(lang, id);
            if (!row) return null;
            return this.idxToCard(row, lang, this.ownedMap[lang] || {});
        },

        startScanLoop() {
            if (this._scanRun) return;
            this._scanRun = true;
            this.scanLive = true;
            this._lastId = '';
            this._hashVotes = [];
            this.scanStatus = 'scanning';
            this.scanHint = 'Halte die ganze Karte ausgerichtet in den Rahmen.';
            this.scanLoop();
        },

        // Treffer verwerfen und Live-Erkennung fortsetzen.
        rescan() {
            this.scanMatches = [];
            this.scanError = '';
            this.ocrSummary = '';
            this._lastId = '';
            this._hashVotes = [];
            this._cooldownUntil = 0;
            this.scanStatus = 'scanning';
            this.scanHint = 'Halte die ganze Karte ausgerichtet in den Rahmen.';
            if (this.cameraActive && !this._scanRun) this.startScanLoop();
        },

        async scanLoop() {
            if (!this._scanRun) return;
            try { await this.scanTick(); } catch (e) { /* einzelne Frames duerfen fehlschlagen */ }
            if (this._scanRun) this._scanTimer = setTimeout(() => this.scanLoop(), 160);
        },

        // Live-Erkennung: dHash des Rahmenausschnitts gegen die Hash-Tabelle.
        async scanTick() {
            if (!this.cameraActive || this._scanBusy) return;
            // Pause, solange die Detailansicht offen ist.
            if (this.cardView) return;
            const video = this.$refs.video;
            if (!video || !video.videoWidth) return;
            if (!this.scanHashes || !this.scanHashes.ids.length) return;
            if (Date.now() < this._cooldownUntil) return;

            this._scanBusy = true;
            try {
                const frame = this._frameDHash();
                const m = this._matchHash(frame);

                // Nur ausreichend klare und eindeutige Treffer in die Wahl geben.
                const ok = m && m.dist <= this._HASH_MAX_DIST &&
                    (m.second - m.dist) >= this._HASH_MIN_MARGIN;
                const cand = ok ? m.id : null;

                // Frame-Voting gegen Flackern/Fehltreffer.
                this._hashVotes.push(cand);
                if (this._hashVotes.length > 4) this._hashVotes.shift();
                const counts = {};
                for (const v of this._hashVotes) if (v) counts[v] = (counts[v] || 0) + 1;
                let best = null, bestC = 0;
                for (const k in counts) if (counts[k] > bestC) { best = k; bestC = counts[k]; }

                if (!best || bestC < 2) {
                    this._lastId = '';
                    if (this.scanStatus !== 'found') {
                        this.scanStatus = 'scanning';
                        this.scanHint = cand
                            ? 'Karte wird geprüft … ruhig halten.'
                            : 'Halte die ganze Karte ausgerichtet in den Rahmen.';
                    }
                    return;
                }

                if (best === this._lastId && this.scanMatches.length) return; // schon gezeigt

                const candidates = this._collectCandidates(frame, m.dist)
                    .map((h) => this._cardFromMatch(h.id, m.lang))
                    .filter(Boolean);
                if (!candidates.length) {
                    this.scanStatus = 'nomatch';
                    this.scanHint = 'Karte erkannt, aber nicht im Index gefunden. Katalogsprache prüfen.';
                    return;
                }

                this._lastId = best;
                this.ocrSummary = 'Übereinstimmung ' + this._matchConfidence(m.dist) + '%';
                this.scanMatches = candidates;
                this.fillPrices(this.scanMatches);
                this.scanStatus = 'found';
                this.scanHint = candidates.length === 1
                    ? 'Treffer: ' + candidates[0].name
                    : candidates.length + ' mögliche Treffer – tippe die richtige Karte an.';
                if (navigator.vibrate) navigator.vibrate(40);
                this._cooldownUntil = Date.now() + 1200;
            } finally {
                this._scanBusy = false;
            }
        },

        // Sammelt alle Karten innerhalb eines kleinen Distanz-Fensters um den
        // besten Treffer (optisch sehr aehnliche Karten = andere Rarität etc.).
        _collectCandidates(frame, bestD) {
            const tbl = this.scanHashes;
            if (!tbl || !frame) return [];
            const pc = this._ensurePopcount();
            const data = tbl.data, stride = tbl.stride, n = tbl.ids.length;
            const limit = bestD + 6;
            const hits = [];
            for (let k = 0; k < n; k++) {
                const off = k * stride;
                let dist = 0;
                for (let i = 0; i < stride; i++) dist += pc[frame[i] ^ data[off + i]];
                if (dist <= limit) hits.push({ id: tbl.ids[k], dist });
            }
            hits.sort((a, b) => a.dist - b.dist);
            return hits.slice(0, 12);
        },

        // Distanz (Bits) -> grobe Prozent-Aehnlichkeit fuer die Anzeige.
        _matchConfidence(dist) {
            const bits = this._HASH_STRIDE * 8;
            return Math.max(0, Math.round((1 - dist / bits) * 100));
        },

        // Manueller Einzel-Scan ("Foto erzwingen"): matcht sofort den aktuellen
        // Frame, auch wenn die Live-Schwelle (noch) nicht erreicht wurde, und
        // zeigt die naechstgelegenen Kandidaten zur Auswahl.
        async captureAndScan() {
            if (!this.cameraActive || !this.scanHashes) return;
            this.scanning = true;
            this.scanError = '';
            this.scanProgress = '…';
            this._lastId = '';
            this._hashVotes = [];
            this._cooldownUntil = Date.now() + 1200;

            try {
                const frame = this._frameDHash();
                const m = this._matchHash(frame);
                if (!m || m.dist > this._HASH_MAX_DIST + 14) {
                    this.scanStatus = 'nomatch';
                    this.scanError = 'Keine passende Karte erkannt. Karte gerade ausrichten, näher heran und Glanz vermeiden.';
                    return;
                }
                const candidates = this._collectCandidates(frame, m.dist)
                    .map((h) => this._cardFromMatch(h.id, m.lang))
                    .filter(Boolean);
                if (!candidates.length) {
                    this.scanStatus = 'nomatch';
                    this.scanError = 'Karte erkannt, aber nicht im Index gefunden. Katalogsprache prüfen.';
                    return;
                }
                this._lastId = m.id;
                this.ocrSummary = 'Übereinstimmung ' + this._matchConfidence(m.dist) + '%';
                this.scanMatches = candidates;
                this.fillPrices(this.scanMatches);
                this.scanStatus = 'found';
                this.scanHint = candidates.length === 1
                    ? 'Treffer: ' + candidates[0].name
                    : candidates.length + ' mögliche Treffer – tippe die richtige Karte an.';
            } catch (e) {
                this.scanError = 'Scan fehlgeschlagen: ' + e.message;
            } finally {
                this.scanning = false;
                this.scanProgress = '';
            }
        },

        // Globale Top-Bar-Suche: wechselt in den Such-Tab und sucht.
        topSearch() {
            if (this.tab !== 'search') this.setTab('search');
            this.runSearch();
        },

        // Untertitel je nach aktivem Tab fuer den Seitenkopf.
        pageSubtitle() {
            switch (this.tab) {
                case 'collection':
                    return `${this.stats.totalQuantity} Karten · ${this.stats.uniqueCards} verschiedene im Wert von ${this.fmtMoney(this.stats.totalValue)}.`;
                case 'scan':
                    return 'Richte die Karte im Rahmen aus und scanne sie automatisch.';
                case 'search':
                    return 'Finde Karten per Name oder Set-Kürzel + Nummer.';
                case 'sets':
                    return 'Durchstöbere alle Sets und füge Karten hinzu.';
                case 'stats':
                    return 'Auswertung deiner Sammlung nach Wert und Set.';
                case 'admin':
                    return 'Benutzer anlegen und verwalten.';
                default:
                    return '';
            }
        },

        // ============================================================ Helfer
        fmtMoney(v) {
            if (v === null || v === undefined || isNaN(v)) return '–';
            return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v);
        },

        variantLabel(v) {
            return { normal: 'Normal', holo: 'Holo', reverse: 'Reverse' }[v] || v;
        },

        // Sinnvolle Druck-Sprachen je nach Katalog. Westliche Karten teilen sich
        // dieselbe TCGdex-ID (gleicher Cardmarket-Preis); JA ist ein eigener
        // Katalog mit eigenen Karten -> dort nur Japanisch.
        printLangs() {
            if (this.cardView && this.cardView.lang === 'ja') {
                return [{ id: 'ja', label: 'Japanisch' }];
            }
            return [
                { id: 'de', label: 'Deutsch' },
                { id: 'en', label: 'Englisch' },
                { id: 'fr', label: 'Französisch' },
                { id: 'it', label: 'Italienisch' },
                { id: 'es', label: 'Spanisch' },
                { id: 'pt', label: 'Portugiesisch' },
            ];
        },

        // Oeffnet die Detailseite aus einem Sammlungs-Eintrag (eigene Datenform).
        openCollectionItem(it) {
            this.openCard({
                id: it.cardId,
                lang: it.lang || 'de',
                name: it.name,
                nameDe: it.nameDe || null,
                nameEn: it.nameEn || null,
                image: it.image || null,
                set: it.setName || null,
                localId: it.localId || null,
                owned: 0,
            });
        },

        // Kompakte Preis-Kennzahlen fuer die Detailseite.
        priceStats(p) {
            if (!p) return [];
            const out = [];
            const add = (label, v) => { if (v !== null && v !== undefined && !isNaN(v)) out.push({ label, value: this.fmtMoney(v) }); };
            add('Trend', p.trend);
            add('Ø', p.avg);
            add('Ab', p.low);
            add('Ø 7 Tage', p.avg7);
            add('Ø 30 Tage', p.avg30);
            if (p.trendHolo) add('Trend Holo', p.trendHolo);
            return out;
        },

        // Kurzes Energie-Kuerzel fuer Attacken-Kosten (erster Buchstabe).
        energyAbbr(t) {
            return (t || '?').toString().trim().charAt(0).toUpperCase() || '?';
        },

        // Sekundaerer Name fuer japanische Karten: bevorzugt DE/EN-Pokémon-Name,
        // sonst eine Romaji-Lesehilfe. Bei deutschen Karten leer.
        altName(c) {
            if (!c || c.lang !== 'ja') return '';
            if (c.nameDe || c.nameEn) {
                return [c.nameDe, c.nameEn].filter(Boolean).join(' · ');
            }
            return c.nameAlt || '';
        },

        // Lesbarer Set-Titel fuer japanische Sets: echter englischer Titel,
        // sonst Romaji-Lesehilfe. Bei deutschen Sets leer.
        setAlt(s) {
            if (!s) return '';
            return s.nameEn || s.nameRomaji || '';
        },

        // Haupttitel eines Sets: bei JA der englische/lesbare Titel (gross),
        // sonst der Originalname.
        setTitle(s) {
            if (!s) return '';
            if (s.lang === 'ja' && this.setAlt(s)) return this.setAlt(s);
            return s.name || '';
        },

        // Untertitel: bei JA der originale Kanji-Name (klein), sonst leer.
        setSub(s) {
            if (!s || s.lang !== 'ja') return '';
            return this.setAlt(s) ? s.name : '';
        },

        barWidth(value) {
            const max = Math.max(...(this.stats.bySet || []).map((s) => s.value), 0.01);
            return Math.max(3, Math.round((value / max) * 100));
        },
    };
}
