/* Pokélog – Frontend-Logik (Alpine.js Komponente).
 * Kein Build-Schritt: laeuft direkt im Browser.
 */

function pokelog() {
    return {
        // -------------------------------------------------- Allgemeiner Zustand
        tabs: [
            { id: 'collection', label: 'Sammlung',  icon: '🗂️', sym: 'style' },
            { id: 'scan',       label: 'Scannen',   icon: '📷', sym: 'qr_code_scanner' },
            { id: 'search',     label: 'Suchen',    icon: '🔍', sym: 'search' },
            { id: 'sets',       label: 'Sets',      icon: '🃏', sym: 'auto_awesome_motion' },
            { id: 'stats',      label: 'Statistik', icon: '📊', sym: 'monitoring' },
        ],
        tab: 'collection',
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
        _popping: false,
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
        openSetData: null,      // { set, cards } des geoeffneten Sets
        setCardsLoading: false,

        // -------------------------------------------------- Hinzufuegen (in Detailseite)
        conditions: ['M', 'NM', 'EX', 'GD', 'LP', 'PL', 'PO'],
        addCard: null,
        addBusy: false,
        addForm: { variant: 'normal', condition: 'NM', language: 'de', quantity: 1, catalogLang: 'de' },

        // -------------------------------------------------- Scan
        cameraActive: false,
        scanning: false,
        scanProgress: '',
        scanError: '',
        ocrText: '',
        ocrSummary: '',
        scanMatches: [],
        _stream: null,

        // ============================================================ Lifecycle
        async init() {
            // Tab aus URL-Hash uebernehmen.
            const hash = location.hash.replace('#', '');
            if (this.tabs.some((t) => t.id === hash)) this.tab = hash;
            // Zurueck-Button (Browser/Geste): Overlays schliessen statt App zu verlassen.
            window.addEventListener('popstate', () => this.onPop());
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
        },

        // ============================================================ Auth
        // True, solange kein Benutzer angemeldet ist (Sammlung nur lokal).
        isGuest() { return !this.auth.user; },
        isAdmin() { return !!(this.auth.user && this.auth.user.role === 'admin'); },

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

        // Schliesst das oberste Overlay (Karte > Set-Detail), sonst Tab aus Hash.
        onPop() {
            this._popping = true;
            try {
                if (this.cardView) { this.cardView = null; return; }
                if (this.openSetData) { this.openSetData = null; return; }
                const hash = location.hash.replace('#', '');
                if (this.tabs.some((t) => t.id === hash)) this.tab = hash;
            } finally {
                this._popping = false;
            }
        },

        // Legt einen History-Eintrag fuer ein Overlay an (fuer den Zurueck-Button).
        pushOverlay(kind) {
            if (this._popping) return;
            history.pushState({ pl: kind }, '');
        },

        setTab(id) {
            if (id !== 'scan' && this.cameraActive) this.stopCamera();
            // Offene Overlays schliessen, damit der Zurueck-Stack sauber bleibt.
            if (this.openSetData) this.openSetData = null;
            this.tab = id;
            location.hash = id;
            if (id === 'stats') this.loadStats();
            if (id === 'collection') this.loadCollection();
            if (id === 'sets') this.loadSets();
            if (id === 'admin') this.loadUsers();
        },

        // Setzt den Such-Filter (Alle/DE/JA) und sucht neu.
        setSearchLang(id) {
            if (!['all', 'de', 'ja'].includes(id) || this.searchLang === id) return;
            this.searchLang = id;
            localStorage.setItem('pokelog_searchlang', id);
            this._tok.search++;
            if (this.searchQuery && this.searchQuery.trim().length >= 1) this.runSearch();
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

        groupSets() {
            const q = this.setFilter.trim().toLowerCase();
            const groups = new Map();
            for (const s of this.sets) {
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
            this.pushOverlay('set');
            const tok = ++this._tok.setView;
            const setLang = set.lang || this.setsLang;
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
            // Ueber die History schliessen, damit Zurueck-Stack konsistent bleibt.
            if (!this._popping) { history.back(); } else { this.openSetData = null; }
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

        // ============================================================ Karten-Detail
        // Oeffnet die Detailseite einer Karte (Stammdaten, Preis, Besitz,
        // verwandte Karten) und bereitet das Hinzufuegen-Formular vor.
        async openCard(card) {
            const cardLang = card.lang || 'de';
            this.cardView = { loading: true, lang: cardLang, base: card, card: null, owned: card.owned || 0, names: null, price: card.pricing || null, override: null, related: { set: [], prints: [] } };
            this.overrideInput = '';
            this.pushOverlay('card');
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
            if (!this._popping) { history.back(); } else { this.cardView = null; }
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
        cardmarketUrl() {
            const cv = this.cardView;
            if (!cv) return '#';
            const name = (cv.names && (cv.names.en || cv.names.de)) || cv.base.name || '';
            const localId = (cv.card && cv.card.localId) || cv.base.localId || '';
            const q = (name + ' ' + localId).trim();
            return 'https://www.cardmarket.com/de/Pokemon/Products/Search?searchString=' + encodeURIComponent(q);
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
        async startCamera() {
            this.scanError = '';
            try {
                this._stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 1920 } },
                    audio: false,
                });
                const video = this.$refs.video;
                video.srcObject = this._stream;
                await video.play();
                this.cameraActive = true;
            } catch (e) {
                this.scanError = 'Kamera nicht verfügbar: ' + e.message +
                    '. Hinweis: Kamerazugriff funktioniert nur über HTTPS oder http://localhost.';
            }
        },

        stopCamera() {
            if (this._stream) {
                this._stream.getTracks().forEach((t) => t.stop());
                this._stream = null;
            }
            this.cameraActive = false;
        },

        async captureAndScan() {
            if (!this.cameraActive) return;
            this.scanning = true;
            this.scanError = '';
            this.scanProgress = '0%';
            this.scanMatches = [];
            this.ocrText = '';
            this.ocrSummary = '';

            try {
                const video = this.$refs.video;
                const canvas = this.$refs.canvas;
                const vw = video.videoWidth || 720;
                const vh = video.videoHeight || 1280;
                canvas.width = vw;
                canvas.height = vh;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, vw, vh);

                const { data: { text } } = await Tesseract.recognize(canvas, 'deu+eng', {
                    logger: (m) => {
                        if (m.status === 'recognizing text') {
                            this.scanProgress = Math.round(m.progress * 100) + '%';
                        }
                    },
                });

                this.ocrText = text || '';
                await this.searchFromOcr(text || '');
            } catch (e) {
                this.scanError = 'Scan fehlgeschlagen: ' + e.message;
            } finally {
                this.scanning = false;
                this.scanProgress = '';
            }
        },

        // Extrahiert Nummer (z. B. 136/189) und Namenskandidaten aus dem OCR-Text.
        parseOcr(text) {
            const numMatch = text.match(/(\d{1,3})\s*\/\s*(\d{1,3})/);
            const number = numMatch
                ? { localId: String(parseInt(numMatch[1], 10)), total: parseInt(numMatch[2], 10), raw: numMatch[1] }
                : null;

            const stop = new Set([
                'POKEMON', 'POKÉMON', 'ENERGY', 'ENERGIE', 'TRAINER', 'BASIS', 'STUFE',
                'STAGE', 'ITEM', 'SUPPORTER', 'STADIUM', 'ILLUS', 'WEAKNESS', 'RESISTANCE',
                'RETREAT', 'SCHWÄCHE', 'RESISTENZ', 'RÜCKZUG', 'ATTACKE', 'ABILITY', 'FÄHIGKEIT',
            ]);
            const tokens = (text.match(/[A-Za-zÄÖÜäöüß'’\-]{3,}/g) || [])
                .map((w) => w.replace(/['’\-]+$/, ''))
                .filter((w) => w.length >= 3 && !stop.has(w.toUpperCase()));

            // Eindeutige Kandidaten, lange zuerst (Pokémon-Namen sind meist lang).
            const uniq = [];
            const seen = new Set();
            for (const w of tokens) {
                const k = w.toLowerCase();
                if (!seen.has(k)) { seen.add(k); uniq.push(w); }
            }
            uniq.sort((a, b) => b.length - a.length);
            return { number, candidates: uniq.slice(0, 5) };
        },

        async searchFromOcr(text) {
            const { number, candidates } = this.parseOcr(text);
            this.ocrSummary = (candidates.slice(0, 3).join(', ') || '–') +
                (number ? `  ·  Nr. ${number.localId}/${number.total}` : '');

            if (candidates.length === 0) {
                this.scanError = 'Kein Kartenname erkannt. Bitte näher heran und scharf stellen.';
                return;
            }

            // Bis zu 3 Kandidaten durchsuchen und Treffer sammeln.
            const collected = new Map();
            for (const cand of candidates.slice(0, 3)) {
                try {
                    const data = await this.api('?action=search&lang=de&q=' + encodeURIComponent(cand));
                    for (const r of (data.results || [])) {
                        if (!collected.has(r.id)) collected.set(r.id, r);
                    }
                } catch (e) { /* naechsten Kandidaten versuchen */ }
                if (collected.size > 0 && number) break; // mit Nummer reicht ein Treffersatz
            }

            let results = [...collected.values()];

            // Mit erkannter Kartennummer priorisieren / filtern.
            if (number && results.length) {
                const exact = results.filter((r) =>
                    String(r.localId) === number.localId &&
                    (!r.setTotal || Math.abs(r.setTotal - number.total) <= 2)
                );
                const byLocal = results.filter((r) => String(r.localId) === number.localId);
                if (exact.length) results = exact;
                else if (byLocal.length) results = byLocal;
                else {
                    results.sort((a, b) =>
                        (String(b.localId) === number.localId) - (String(a.localId) === number.localId));
                }
            }

            this.scanMatches = results.slice(0, 12);
            // Im Gast-Modus die Besitz-Markierung aus dem lokalen Speicher setzen.
            if (this.isGuest()) {
                for (const r of this.scanMatches) {
                    const m = this.ownedMap[r.lang || 'de'] || {};
                    r.owned = m[r.id] || 0;
                }
            }
            this.fillPrices(this.scanMatches);
            if (this.scanMatches.length === 0) {
                this.scanError = 'Keine passende Karte gefunden. Versuch es über die Suche.';
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
