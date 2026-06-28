<?php

declare(strict_types=1);

// index.php dient nur als statische Shell. Die gesamte Logik laeuft ueber
// die JSON-API (api.php) und das Frontend (assets/app.js).
?>
<!DOCTYPE html>
<html lang="de" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Pokélog – Pokémon-Karten Sammlung</title>
    <meta name="description" content="Tracke deine Pokémon-Kartensammlung mit Scan-Funktion und deutschen Cardmarket-Preisen.">
    <meta name="theme-color" content="#f9f9fc">

    <!-- Tailwind CSS per Play-CDN (kein Build-Schritt) -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        // Zenith-Dex Design-System (siehe design/zenith_dex/DESIGN.md)
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        'surface': '#f9f9fc',
                        'surface-dim': '#dadadc',
                        'surface-bright': '#f9f9fc',
                        'surface-container-lowest': '#ffffff',
                        'surface-container-low': '#f3f3f6',
                        'surface-container': '#eeeef0',
                        'surface-container-high': '#e8e8ea',
                        'surface-container-highest': '#e2e2e5',
                        'surface-variant': '#e2e2e5',
                        'on-surface': '#1a1c1e',
                        'on-surface-variant': '#603e39',
                        'inverse-surface': '#2f3133',
                        'inverse-on-surface': '#f0f0f3',
                        'outline': '#956d67',
                        'outline-variant': '#ebbbb4',
                        'primary': '#bc0100',
                        'on-primary': '#ffffff',
                        'primary-container': '#eb0000',
                        'on-primary-container': '#fffbff',
                        'primary-fixed': '#ffdad4',
                        'primary-fixed-dim': '#ffb4a8',
                        'secondary': '#0001c0',
                        'on-secondary': '#ffffff',
                        'secondary-container': '#080cff',
                        'tertiary': '#6d5e00',
                        'on-tertiary': '#ffffff',
                        'tertiary-container': '#c4ab00',
                        'on-tertiary-container': '#4a3f00',
                        'tertiary-fixed': '#ffe24a',
                        'error': '#ba1a1a',
                        'on-error': '#ffffff',
                        'error-container': '#ffdad6',
                        'on-error-container': '#93000a',
                        'background': '#f9f9fc',
                        'on-background': '#1a1c1e',
                        'success': '#198754',
                    },
                    borderRadius: {
                        'DEFAULT': '0.5rem',
                        'sm': '0.25rem',
                        'md': '0.75rem',
                        'lg': '1rem',
                        'xl': '1.5rem',
                        'full': '9999px',
                    },
                    spacing: {
                        'base': '8px',
                        'gutter': '16px',
                        'stack-sm': '4px',
                        'stack-md': '12px',
                        'stack-lg': '32px',
                        'container-padding': '24px',
                    },
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'ui-monospace', 'monospace'],
                    },
                    fontSize: {
                        'display-lg': ['48px', { lineHeight: '56px', letterSpacing: '-0.02em', fontWeight: '800' }],
                        'headline-lg': ['32px', { lineHeight: '40px', letterSpacing: '-0.01em', fontWeight: '700' }],
                        'title-md': ['20px', { lineHeight: '28px', fontWeight: '600' }],
                        'body-lg': ['16px', { lineHeight: '24px', fontWeight: '400' }],
                        'body-sm': ['14px', { lineHeight: '20px', fontWeight: '400' }],
                        'label-mono': ['12px', { lineHeight: '16px', letterSpacing: '0.05em', fontWeight: '500' }],
                    },
                },
            },
        };
    </script>

    <!-- Fonts: Plus Jakarta Sans + JetBrains Mono + Material Symbols -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">

    <!-- Alpine.js fuer Reaktivitaet (kein Build-Schritt) -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>

    <!-- Tesseract.js fuer OCR beim Scannen -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js"></script>

    <link rel="preconnect" href="https://assets.tcgdex.net">
    <link rel="dns-prefetch" href="https://assets.tcgdex.net">

    <!-- PWA: installierbar + Offline-/Bild-Cache -->
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Pokélog">
    <style>
        [x-cloak] { display: none !important; }
        html { scroll-behavior: smooth; }
        body { background-color: #f9f9fc; }

        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            user-select: none;
        }
        .fill-icon { font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 24; }

        /* Premium-Karten-Hover */
        .zx-card { transition: transform .3s ease, box-shadow .3s ease; }
        .zx-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.12); }
        .zx-shadow { box-shadow: 0 4px 20px rgba(0,0,0,0.05); }

        /* Typ-/Daten-Chips */
        .type-fire { background-color: rgba(235, 0, 0, 0.10); color: #c80d0d; }
        .type-water { background-color: rgba(0, 1, 192, 0.10); color: #0001c0; }
        .type-grass { background-color: rgba(25, 135, 84, 0.12); color: #198754; }
        .type-electric { background-color: rgba(255, 226, 74, 0.22); color: #4a3f00; }

        /* Scanner-Ecken + Scanline (aus Mockup) */
        .scanner-corners::before, .scanner-corners::after,
        .scanner-corners-bottom::before, .scanner-corners-bottom::after {
            content: ''; position: absolute; width: 36px; height: 36px;
        }
        .scanner-corners::before { top: 0; left: 0; border-top: 4px solid #bc0100; border-left: 4px solid #bc0100; border-top-left-radius: 14px; }
        .scanner-corners::after { top: 0; right: 0; border-top: 4px solid #bc0100; border-right: 4px solid #bc0100; border-top-right-radius: 14px; }
        .scanner-corners-bottom::before { bottom: 0; left: 0; border-bottom: 4px solid #bc0100; border-left: 4px solid #bc0100; border-bottom-left-radius: 14px; }
        .scanner-corners-bottom::after { bottom: 0; right: 0; border-bottom: 4px solid #bc0100; border-right: 4px solid #bc0100; border-bottom-right-radius: 14px; }
        @keyframes scanline { 0% { top: 0; opacity: 0; } 10% { opacity: 1; } 90% { opacity: 1; } 100% { top: 100%; opacity: 0; } }
        .scan-line { animation: scanline 3s linear infinite; }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background-color: #dadadc; border-radius: 9999px; }

        /* Weiches Einblenden der Kartenbilder */
        .zx-img { opacity: 0; transition: opacity .4s ease; }
        .zx-img.img-in { opacity: 1; }

        /* Skeleton-Shimmer fuer Ladezustaende */
        @keyframes zx-shimmer { 0% { background-position: -480px 0; } 100% { background-position: 480px 0; } }
        .zx-skel {
            background: #eeeef0 linear-gradient(90deg, #eeeef0 0, #f5f5f8 50px, #eeeef0 100px) 0 0 / 600px 100%;
            animation: zx-shimmer 1.3s infinite linear;
        }
        /* Sanfte Section-Wechsel */
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-background text-on-background font-sans antialiased min-h-screen overflow-x-hidden">
<div x-data="pokelog()" x-init="init()" x-cloak class="min-h-screen">

    <!-- ============================ SIDE-NAV (Desktop) ============================ -->
    <nav class="hidden md:flex flex-col h-screen w-72 fixed left-0 top-0 py-8 bg-surface border-r border-outline-variant z-50 overflow-y-auto">
        <!-- Brand -->
        <div class="px-6 mb-8 flex items-center gap-3">
            <div class="h-12 w-12 rounded-lg bg-gradient-to-b from-primary-container to-primary grid place-items-center shadow-sm ring-1 ring-primary/30 shrink-0">
                <div class="h-4 w-4 rounded-full bg-white ring-2 ring-on-surface"></div>
            </div>
            <div class="min-w-0">
                <h1 class="text-title-md font-extrabold text-primary leading-tight">Pokélog</h1>
                <p class="font-mono text-label-mono text-on-surface-variant">Card Collection</p>
            </div>
        </div>

        <!-- Nav-Items -->
        <div class="flex-1 px-4 space-y-1">
            <template x-for="t in tabs" :key="t.id">
                <button @click="setTab(t.id)"
                    class="w-full flex items-center gap-3 px-4 py-3 rounded-md transition-colors duration-200"
                    :class="tab === t.id
                        ? 'text-primary font-bold border-r-4 border-primary bg-primary-fixed/40'
                        : 'text-on-surface-variant hover:text-primary hover:bg-surface-container-low'">
                    <span class="material-symbols-outlined" :class="tab === t.id ? 'fill-icon' : ''" x-text="t.sym"></span>
                    <span class="font-mono text-label-mono" x-text="t.label"></span>
                </button>
            </template>
        </div>

        <!-- Scan-CTA -->
        <div class="px-4 mt-4">
            <button @click="setTab('scan')"
                class="w-full bg-primary text-on-primary font-bold uppercase tracking-wide text-sm py-3 rounded-lg shadow-sm hover:shadow-md hover:-translate-y-0.5 active:scale-95 transition-all flex items-center justify-center gap-2">
                <span class="material-symbols-outlined">qr_code_scanner</span>
                Karte scannen
            </button>
        </div>

        <!-- Profil + Sammlungsdaten -->
        <div class="px-4 mt-6">
            <div class="rounded-xl border border-outline-variant bg-surface-container-low p-3.5 zx-shadow">
                <!-- Kopf: angemeldet -->
                <template x-if="auth.user">
                    <div class="flex items-center gap-3">
                        <div class="h-10 w-10 rounded-full bg-gradient-to-br from-secondary to-primary grid place-items-center text-on-primary font-extrabold shadow-sm ring-1 ring-primary/30 shrink-0" x-text="userInitial()"></div>
                        <div class="min-w-0 flex-1">
                            <p class="text-body-sm font-bold text-on-surface truncate leading-tight" x-text="auth.user.username"></p>
                            <span class="inline-flex items-center gap-1 font-mono text-[9px] uppercase tracking-wider px-1.5 py-0.5 rounded-full mt-1"
                                :class="isAdmin() ? 'bg-primary-fixed/60 text-primary' : 'bg-surface-container text-on-surface-variant'">
                                <span class="material-symbols-outlined text-[12px]" x-text="isAdmin() ? 'shield_person' : 'person'"></span>
                                <span x-text="isAdmin() ? 'Admin' : 'Benutzer'"></span>
                            </span>
                        </div>
                        <button @click="doLogout()" title="Abmelden" class="h-8 w-8 grid place-items-center rounded-full text-on-surface-variant hover:bg-surface-variant hover:text-error transition-colors shrink-0">
                            <span class="material-symbols-outlined text-[18px]">logout</span>
                        </button>
                    </div>
                </template>
                <!-- Kopf: Gast -->
                <template x-if="!auth.user">
                    <div class="flex items-center gap-3">
                        <div class="h-10 w-10 rounded-full bg-surface-container grid place-items-center text-on-surface-variant shrink-0">
                            <span class="material-symbols-outlined">person</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-body-sm font-bold text-on-surface leading-tight">Gast</p>
                            <span class="inline-flex items-center gap-1 font-mono text-[9px] uppercase tracking-wider px-1.5 py-0.5 rounded-full mt-1 bg-tertiary-fixed/60 text-on-tertiary-container">
                                <span class="material-symbols-outlined text-[12px]">cloud_off</span> nur lokal
                            </span>
                        </div>
                    </div>
                </template>

                <!-- Sammlungsdaten -->
                <div class="grid grid-cols-3 gap-1 mt-3 pt-3 border-t border-outline-variant text-center">
                    <div class="min-w-0">
                        <p class="font-mono text-[8px] uppercase tracking-wider text-on-surface-variant">Wert</p>
                        <p class="font-mono text-[13px] font-bold text-secondary tabular-nums truncate" x-text="fmtMoney(stats.totalValue)"></p>
                    </div>
                    <div class="min-w-0 border-x border-outline-variant">
                        <p class="font-mono text-[8px] uppercase tracking-wider text-on-surface-variant">Karten</p>
                        <p class="font-mono text-[13px] font-bold text-on-surface tabular-nums" x-text="stats.totalQuantity"></p>
                    </div>
                    <div class="min-w-0">
                        <p class="font-mono text-[8px] uppercase tracking-wider text-on-surface-variant">Versch.</p>
                        <p class="font-mono text-[13px] font-bold text-on-surface tabular-nums" x-text="stats.uniqueCards"></p>
                    </div>
                </div>

                <!-- Login-CTA (nur Gast) -->
                <template x-if="!auth.user">
                    <button @click="openAuth()" class="w-full mt-3 flex items-center justify-center gap-2 px-3 py-2 rounded-lg bg-primary text-on-primary font-bold text-sm hover:shadow-md transition-all">
                        <span class="material-symbols-outlined text-[18px]">login</span>
                        <span x-text="auth.needsSetup ? 'Einrichten' : 'Anmelden'"></span>
                    </button>
                </template>
            </div>
        </div>
    </nav>

    <!-- ============================ TOP-BAR (Desktop) ============================ -->
    <header class="hidden md:flex justify-between items-center h-16 px-8 fixed top-0 right-0 w-[calc(100%-18rem)] z-40 bg-surface/80 backdrop-blur-md border-b border-outline-variant">
        <div class="flex-1 flex items-center gap-4">
            <div class="relative w-full max-w-md group">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant group-focus-within:text-primary transition-colors">search</span>
                <input type="search" x-model="searchQuery" @input="topSearch()" @keydown.enter.prevent="searchEnter()"
                    placeholder="Karte, Set oder Pokémon suchen…"
                    class="w-full bg-surface-container-low border-b-2 border-transparent focus:border-primary focus:ring-0 rounded-t-md pl-10 pr-4 py-2 text-body-sm placeholder:text-on-surface-variant outline-none transition-all">
            </div>
        </div>
        <div class="flex items-center gap-3">
            <button @click="refreshPrices()" :disabled="refreshing"
                class="font-mono text-label-mono px-4 py-2 rounded-full bg-surface-container hover:bg-surface-variant text-on-surface transition-colors flex items-center gap-2 disabled:opacity-50">
                <span class="material-symbols-outlined text-[18px]" :class="refreshing ? 'animate-spin' : ''">sync</span>
                <span x-show="!refreshing">Preise</span>
                <span x-show="refreshing">Lädt…</span>
            </button>
            <div class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-container">
                <span class="material-symbols-outlined text-[18px] text-secondary fill-icon">account_balance_wallet</span>
                <span class="font-mono text-label-mono font-bold text-secondary tabular-nums" x-text="fmtMoney(stats.totalValue)"></span>
            </div>
            <button @click="openAuth()"
                class="font-mono text-label-mono px-3 py-2 rounded-full transition-colors flex items-center gap-2"
                :class="auth.user ? 'bg-success/10 text-success hover:bg-success/20' : 'bg-primary text-on-primary hover:shadow-md'"
                :title="auth.user ? (auth.user.username + ' · abmelden/Konto') : 'Anmelden'">
                <span class="material-symbols-outlined text-[18px]" :class="auth.user ? 'fill-icon' : ''" x-text="auth.user ? 'account_circle' : 'login'"></span>
                <span x-text="auth.user ? auth.user.username : 'Anmelden'"></span>
            </button>
        </div>
    </header>

    <!-- ============================ TOP-BAR (Mobile) ============================ -->
    <header class="md:hidden sticky top-0 z-40 bg-surface/90 backdrop-blur-md border-b border-outline-variant">
        <div class="flex items-center justify-between gap-2 px-4 py-3">
            <div class="flex items-center gap-2 min-w-0">
                <div class="h-9 w-9 shrink-0 rounded-lg bg-gradient-to-b from-primary-container to-primary grid place-items-center shadow-sm ring-1 ring-primary/30">
                    <div class="h-3 w-3 rounded-full bg-white ring-2 ring-on-surface"></div>
                </div>
                <h1 class="text-title-md font-extrabold text-primary leading-none truncate">Pokélog</h1>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <div class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-full bg-surface-container">
                    <span class="material-symbols-outlined text-[16px] text-secondary fill-icon">account_balance_wallet</span>
                    <span class="font-mono text-[11px] font-bold text-secondary tabular-nums" x-text="fmtMoney(stats.totalValue)"></span>
                </div>
                <button @click="openAuth()" class="h-9 w-9 grid place-items-center rounded-full transition-colors"
                    :class="auth.user ? 'bg-success/10 text-success' : 'bg-primary text-on-primary'"
                    :title="auth.user ? auth.user.username : 'Anmelden'">
                    <span class="material-symbols-outlined text-[20px]" :class="auth.user ? 'fill-icon' : ''" x-text="auth.user ? 'account_circle' : 'login'"></span>
                </button>
            </div>
        </div>
    </header>

    <!-- ============================ MAIN ============================ -->
    <main class="md:ml-72 md:pt-24 px-4 md:px-8 pt-5 md:pb-10 pb-28">

        <!-- Seiten-Kopf -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-3 mb-6 md:mb-8">
            <div class="min-w-0">
                <h2 class="text-3xl md:text-display-lg font-extrabold text-on-background tracking-tight truncate" x-text="tabs.find(t => t.id === tab)?.label || 'Pokélog'"></h2>
                <p class="text-body-sm md:text-body-lg text-on-surface-variant mt-1" x-text="pageSubtitle()"></p>
            </div>
        </div>

        <!-- Gast-Hinweis: Sammlung nur lokal -->
        <div x-show="isGuest() && (tab === 'collection' || tab === 'stats')" x-cloak
            class="mb-6 rounded-xl border border-tertiary/40 bg-tertiary-fixed/30 px-4 py-3 flex flex-col sm:flex-row sm:items-center gap-3">
            <span class="material-symbols-outlined text-tertiary shrink-0">warning</span>
            <div class="flex-1 min-w-0">
                <p class="text-body-sm font-semibold text-on-surface">Gast-Modus – Sammlung nur in diesem Browser</p>
                <p class="text-[12px] text-on-surface-variant leading-snug">
                    Deine Sammlung wird ausschließlich lokal (localStorage) gespeichert: <span class="font-semibold">nicht gesichert</span> und <span class="font-semibold">nicht geräteübergreifend</span>. Beim Leeren des Browsers gehen die Daten verloren. Melde dich an, um sie sicher in der Datenbank zu speichern.
                </p>
            </div>
            <button @click="openAuth()" class="shrink-0 px-4 py-2 rounded-lg bg-primary text-on-primary text-sm font-bold hover:shadow-md transition-all flex items-center justify-center gap-2">
                <span class="material-symbols-outlined text-[18px]" x-text="auth.needsSetup ? 'admin_panel_settings' : 'login'"></span>
                <span x-text="auth.needsSetup ? 'Einrichten' : 'Anmelden'"></span>
            </button>
        </div>

        <!-- ============================ SAMMLUNG ============================ -->
        <section x-show="tab === 'collection'" class="space-y-stack-lg">
            <!-- Statistik-Bento -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-gutter">
                <div class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-4 md:p-5">
                    <div class="flex items-center gap-2 mb-2 text-on-surface-variant">
                        <span class="material-symbols-outlined fill-icon text-[18px] text-secondary">payments</span>
                        <p class="font-mono text-label-mono uppercase truncate">Gesamtwert</p>
                    </div>
                    <p class="text-xl md:text-2xl font-extrabold text-secondary tabular-nums truncate" x-text="fmtMoney(stats.totalValue)"></p>
                </div>
                <div class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-4 md:p-5">
                    <div class="flex items-center gap-2 mb-2 text-on-surface-variant">
                        <span class="material-symbols-outlined fill-icon text-[18px] text-primary">style</span>
                        <p class="font-mono text-label-mono uppercase truncate">Karten gesamt</p>
                    </div>
                    <p class="text-xl md:text-2xl font-extrabold tabular-nums" x-text="stats.totalQuantity"></p>
                </div>
                <div class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-4 md:p-5">
                    <div class="flex items-center gap-2 mb-2 text-on-surface-variant">
                        <span class="material-symbols-outlined fill-icon text-[18px] text-tertiary">auto_awesome</span>
                        <p class="font-mono text-label-mono uppercase truncate">Verschiedene</p>
                    </div>
                    <p class="text-xl md:text-2xl font-extrabold tabular-nums" x-text="stats.uniqueCards"></p>
                </div>
                <button @click="refreshPrices()" :disabled="refreshing"
                    class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-4 md:p-5 text-left hover:border-primary transition-colors disabled:opacity-50 group">
                    <div class="flex items-center gap-2 mb-2 text-on-surface-variant">
                        <span class="material-symbols-outlined text-[18px] text-primary" :class="refreshing ? 'animate-spin' : ''">sync</span>
                        <p class="font-mono text-label-mono uppercase truncate">Preise</p>
                    </div>
                    <p class="text-xl md:text-2xl font-extrabold text-primary group-hover:underline" x-text="refreshing ? 'Lädt…' : 'Update'"></p>
                </button>
            </div>

            <!-- Filter-Leiste -->
            <div class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-4 flex flex-col md:flex-row gap-3 md:items-center">
                <div class="relative flex-1 min-w-[180px]">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">search</span>
                    <input type="search" x-model.debounce.300ms="collectionQuery" @input="loadCollection()"
                        placeholder="In Sammlung suchen…"
                        class="w-full bg-surface-container-low border-none rounded-lg pl-10 pr-4 py-2.5 text-body-sm focus:ring-2 focus:ring-primary outline-none transition-all">
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="material-symbols-outlined text-on-surface-variant text-[20px]">filter_list</span>
                    <select x-model="collectionSet" @change="loadCollection()"
                        class="bg-surface-container-low border-none rounded-lg py-2.5 pl-3 pr-8 text-body-sm focus:ring-2 focus:ring-primary outline-none cursor-pointer">
                        <option value="">Alle Sets</option>
                        <template x-for="s in setOptions" :key="s.id">
                            <option :value="s.id" x-text="s.name"></option>
                        </template>
                    </select>
                    <span class="material-symbols-outlined text-on-surface-variant text-[20px]">sort</span>
                    <select x-model="collectionSort" @change="loadCollection()"
                        class="bg-surface-container-low border-none rounded-lg py-2.5 pl-3 pr-8 text-body-sm focus:ring-2 focus:ring-primary outline-none cursor-pointer">
                        <option value="set">Set &amp; Nummer</option>
                        <option value="value">Wert (hoch→niedrig)</option>
                        <option value="value_asc">Wert (niedrig→hoch)</option>
                        <option value="name">Name (A–Z)</option>
                        <option value="recent">Zuletzt hinzugefügt</option>
                    </select>
                    <button @click="exportCsv()" :disabled="!collection.length"
                        class="font-mono text-label-mono px-3 py-2.5 rounded-lg bg-surface-container hover:bg-surface-variant text-on-surface transition-colors flex items-center gap-1.5 disabled:opacity-40"
                        title="Sammlung als CSV exportieren">
                        <span class="material-symbols-outlined text-[18px]">download</span>
                        <span class="hidden sm:inline">Export</span>
                    </button>
                </div>
            </div>

            <!-- Leerer Zustand -->
            <template x-if="!loading && collection.length === 0">
                <div class="text-center py-20 rounded-xl border-2 border-dashed border-outline-variant bg-surface-container/30">
                    <div class="w-16 h-16 mx-auto rounded-full bg-surface-container grid place-items-center text-primary mb-4">
                        <span class="material-symbols-outlined text-[32px]">style</span>
                    </div>
                    <p class="text-title-md font-semibold text-on-background">Deine Sammlung ist noch leer.</p>
                    <p class="text-body-sm text-on-surface-variant mt-1">Scanne eine Karte oder suche sie, um zu starten.</p>
                    <div class="mt-5 flex gap-2 justify-center">
                        <button @click="setTab('scan')" class="px-4 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-bold uppercase tracking-wide flex items-center gap-2 hover:shadow-md transition-all">
                            <span class="material-symbols-outlined text-[20px]">qr_code_scanner</span> Scannen
                        </button>
                        <button @click="setTab('search')" class="px-4 py-2.5 rounded-lg bg-surface-container hover:bg-surface-variant text-on-surface text-sm font-bold flex items-center gap-2 transition-colors">
                            <span class="material-symbols-outlined text-[20px]">search</span> Suchen
                        </button>
                    </div>
                </div>
            </template>

            <!-- Karten-Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-3 md:gap-gutter">
                <template x-for="it in collection" :key="it.id">
                    <article class="zx-card bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow overflow-hidden flex flex-col group">
                        <div class="p-3 flex-1 cursor-pointer" @click="openCollectionItem(it)" title="Details anzeigen">
                            <div class="relative aspect-[63/88] w-full bg-surface-container-low rounded-lg overflow-hidden mb-3">
                                <img :src="it.image || placeholder" @error="$event.target.src = placeholder" @load="$event.target.classList.add('img-in')" :alt="it.name" loading="lazy" decoding="async"
                                    class="zx-img absolute inset-0 h-full w-full object-contain p-1 group-hover:scale-[1.03] transition-transform duration-500">
                                <div class="absolute top-2 right-2 flex flex-col items-end gap-1">
                                    <span class="bg-surface/90 backdrop-blur text-on-surface font-mono text-[10px] font-bold px-2 py-0.5 rounded-full border border-outline-variant shadow-sm tabular-nums" x-text="'×' + it.quantity"></span>
                                    <span x-show="it.variant !== 'normal'" class="bg-tertiary-container/80 text-on-tertiary-container font-mono text-[9px] font-bold px-2 py-0.5 rounded-full uppercase tracking-wider" x-text="variantLabel(it.variant)"></span>
                                </div>
                            </div>
                            <h3 class="text-body-lg font-semibold text-on-surface truncate leading-tight" x-text="it.name"></h3>
                            <p x-show="altName(it)" class="text-[11px] text-secondary font-medium truncate" x-text="altName(it)"></p>
                            <p class="font-mono text-[11px] text-on-surface-variant mt-0.5 truncate"
                                x-text="(it.setName || '—') + (it.localId ? ' · ' + it.localId + (it.setTotal ? '/' + it.setTotal : '') : '')"></p>
                            <div class="mt-2 flex gap-1">
                                <span class="bg-surface-container text-on-surface-variant font-mono text-[9px] px-2 py-0.5 rounded-full uppercase tracking-wider" x-text="it.condition"></span>
                                <span class="bg-surface-container text-on-surface-variant font-mono text-[9px] px-2 py-0.5 rounded-full uppercase tracking-wider" x-text="it.language"></span>
                            </div>
                        </div>
                        <div class="border-t border-outline-variant bg-surface-bright px-3 py-2.5 flex items-center justify-between">
                            <div class="min-w-0">
                                <span class="block font-mono text-[9px] text-on-surface-variant uppercase tracking-wider">
                                    Wert <span x-show="it.priceManual" class="text-tertiary" title="Manuell korrigiert">✎</span>
                                </span>
                                <span class="font-mono text-body-sm font-bold text-secondary tabular-nums" x-text="it.unitPrice !== null ? fmtMoney(it.unitPrice) : '–'"></span>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <button @click="changeQty(it, -1)" class="h-7 w-7 grid place-items-center rounded-lg bg-surface-container hover:bg-surface-variant text-on-surface transition-colors">
                                    <span class="material-symbols-outlined text-[18px]">remove</span>
                                </button>
                                <button @click="changeQty(it, 1)" class="h-7 w-7 grid place-items-center rounded-lg bg-surface-container hover:bg-surface-variant text-on-surface transition-colors">
                                    <span class="material-symbols-outlined text-[18px]">add</span>
                                </button>
                            </div>
                        </div>
                    </article>
                </template>
            </div>
        </section>

        <!-- ============================ SUCHEN ============================ -->
        <section x-show="tab === 'search'" class="space-y-5">
            <div class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-4">
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
                    <input type="search" x-model="searchQuery" @input="runSearch()" @keydown.enter.prevent="searchEnter()"
                        placeholder="Name (Glurak) oder Sammlernummer (MEP 047)…" autofocus
                        class="w-full bg-surface-container-low border-none rounded-lg pl-11 pr-11 py-3 text-body-lg focus:ring-2 focus:ring-primary outline-none transition-all">
                    <button x-show="searchQuery" @click="searchQuery=''; runSearch()" title="Leeren"
                        class="absolute right-3 top-1/2 -translate-y-1/2 h-7 w-7 grid place-items-center rounded-full text-on-surface-variant hover:bg-surface-container">
                        <span class="material-symbols-outlined text-[20px]">close</span>
                    </button>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <div class="flex rounded-lg bg-surface-container-low border border-outline-variant p-1" role="group" aria-label="Sprachfilter">
                        <template x-for="l in searchLangs" :key="l.id">
                            <button @click="setSearchLang(l.id)"
                                class="px-3 py-1.5 rounded text-sm font-semibold transition-colors flex items-center gap-1.5"
                                :class="searchLang === l.id ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:text-on-surface'">
                                <span x-text="l.flag"></span><span x-text="l.label"></span>
                            </button>
                        </template>
                    </div>
                    <p class="text-body-sm text-on-surface-variant">
                        <span class="font-semibold text-success">Sofortsuche</span> über DE + JA · Tipp:
                        <span class="font-mono text-secondary">MEP 047</span>, <span class="font-mono text-secondary">Glurak PAF</span>, Kanji –
                        <span class="font-semibold text-on-surface">Enter</span> öffnet den ersten Treffer.
                    </p>
                </div>
            </div>

            <p x-show="searching" class="text-body-sm text-on-surface-variant flex items-center gap-2">
                <span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span> Such-Index wird vorbereitet…
            </p>
            <p x-show="!searching && searchQuery.length >= 1 && searchTotal > searchResults.length" class="font-mono text-label-mono text-on-surface-variant">
                <span x-text="searchTotal"></span> Treffer · zeige Top <span x-text="searchResults.length"></span>
            </p>

            <div x-show="!searching && (searchMode === 'number' || searchMode === 'combo') && searchSet" class="text-body-sm">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-fixed/50 border border-primary/30 text-primary font-medium">
                    <span class="material-symbols-outlined text-[16px] fill-icon">target</span>
                    Set erkannt:
                    <span class="font-semibold" x-text="searchSet ? (searchSet.name + (searchSet.abbr ? ' · ' + searchSet.abbr : '')) : ''"></span>
                </span>
            </div>

            <div x-show="!searching && searchNote" class="text-body-sm rounded-lg bg-tertiary-fixed/40 border border-tertiary/30 text-on-tertiary-container px-4 py-3 flex items-start gap-2">
                <span class="material-symbols-outlined text-[18px] shrink-0">info</span>
                <span>
                    <span x-text="searchNote"></span>
                    <button @click="rebuildSets()" :disabled="rebuildingSets" class="ml-1 underline font-semibold hover:text-tertiary disabled:opacity-50">Set-Verzeichnis aktualisieren</button>
                </span>
            </div>

            <p x-show="!searching && searchQuery.trim().length >= 1 && searchResults.length === 0" class="text-body-sm text-on-surface-variant">Keine Treffer.</p>

            <!-- Zuletzt angesehen (wenn keine Suche aktiv) -->
            <div x-show="searchQuery.trim().length < 1 && recent.length" class="space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="font-mono text-label-mono font-bold text-on-surface-variant uppercase tracking-wider flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">history</span> Zuletzt angesehen
                    </h3>
                    <button @click="clearRecent()" class="font-mono text-label-mono text-on-surface-variant hover:text-primary">leeren</button>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7 gap-3 md:gap-gutter">
                    <template x-for="r in recent" :key="'rec'+r.id">
                        <button @click="openCard(r)" class="zx-card text-left bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow overflow-hidden flex flex-col group">
                            <div class="relative aspect-[63/88] bg-surface-container-low">
                                <img :src="r.image || placeholder" @error="$event.target.src = placeholder" @load="$event.target.classList.add('img-in')" :alt="r.name" loading="lazy" decoding="async" class="zx-img absolute inset-0 h-full w-full object-contain p-1 group-hover:scale-[1.03] transition-transform duration-500">
                            </div>
                            <div class="p-3">
                                <h3 class="text-body-lg font-semibold text-on-surface truncate leading-tight" x-text="r.name"></h3>
                                <p x-show="altName(r)" class="text-[11px] text-secondary font-medium truncate" x-text="altName(r)"></p>
                                <p class="font-mono text-[11px] text-on-surface-variant truncate mt-0.5" x-text="(r.set || '—') + (r.localId ? ' · ' + r.localId : '')"></p>
                            </div>
                        </button>
                    </template>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7 gap-3 md:gap-gutter">
                <template x-for="r in searchResults" :key="r.id">
                    <button @click="openCard(r)" class="zx-card text-left bg-surface-container-lowest rounded-xl border zx-shadow overflow-hidden flex flex-col group" :class="r.owned > 0 ? 'border-success ring-1 ring-success' : 'border-outline-variant'">
                        <div class="relative aspect-[63/88] bg-surface-container-low">
                            <img :src="r.image || placeholder" @error="$event.target.src = placeholder" @load="$event.target.classList.add('img-in')" :alt="r.name" loading="lazy" decoding="async" class="zx-img absolute inset-0 h-full w-full object-contain p-1 group-hover:scale-[1.03] transition-transform duration-500">
                            <div x-show="r.owned > 0" class="absolute top-2 right-2 flex items-center gap-0.5 pl-1 pr-1.5 py-0.5 rounded-full bg-success text-white font-mono text-[10px] font-bold shadow">
                                <span class="material-symbols-outlined text-[14px] fill-icon">check</span><span x-text="r.owned"></span>
                            </div>
                            <div x-show="r.pricing && r.pricing.trend" class="absolute bottom-2 right-2 px-2 py-0.5 rounded-full bg-surface/90 backdrop-blur text-secondary font-mono text-[10px] font-bold border border-outline-variant tabular-nums" x-text="r.pricing ? fmtMoney(r.pricing.trend) : ''"></div>
                        </div>
                        <div class="p-3">
                            <h3 class="text-body-lg font-semibold text-on-surface truncate leading-tight" x-text="r.name"></h3>
                            <p x-show="altName(r)" class="text-[11px] text-secondary font-medium truncate" x-text="altName(r)"></p>
                            <p class="font-mono text-[11px] text-on-surface-variant truncate mt-0.5" x-text="(r.set || '—') + (r.localId ? ' · ' + r.localId : '')"></p>
                        </div>
                    </button>
                </template>
            </div>
        </section>

        <!-- ============================ SETS ============================ -->
        <section x-show="tab === 'sets'" class="space-y-5">
            <template x-if="!openSetData">
                <div class="space-y-5">
                    <!-- Umschalter: klassisches Sammelkartenspiel vs. Pokémon TCG Pocket -->
                    <div class="flex rounded-xl bg-surface-container-low border border-outline-variant p-1 w-full sm:w-auto sm:inline-flex">
                        <button @click="setSetsView('tcg')"
                            class="flex-1 sm:flex-none px-4 py-2 rounded-lg text-sm font-semibold transition-colors flex items-center justify-center gap-2"
                            :class="setsView === 'tcg' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:text-on-surface'">
                            <span class="material-symbols-outlined text-[18px]">style</span>
                            Sammelkartenspiel
                            <span class="font-mono text-[10px] px-1.5 py-0.5 rounded-full" :class="setsView === 'tcg' ? 'bg-on-primary/20' : 'bg-surface-container'" x-text="setCount('tcg')"></span>
                        </button>
                        <button @click="setSetsView('pocket')"
                            class="flex-1 sm:flex-none px-4 py-2 rounded-lg text-sm font-semibold transition-colors flex items-center justify-center gap-2"
                            :class="setsView === 'pocket' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:text-on-surface'">
                            <span class="material-symbols-outlined text-[18px]">smartphone</span>
                            Pokémon Pocket
                            <span class="font-mono text-[10px] px-1.5 py-0.5 rounded-full" :class="setsView === 'pocket' ? 'bg-on-primary/20' : 'bg-surface-container'" x-text="setCount('pocket')"></span>
                        </button>
                    </div>

                    <div class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-4 flex flex-wrap items-center gap-3">
                        <div class="relative flex-1 min-w-[180px]">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">search</span>
                            <input type="search" x-model.debounce.200ms="setFilter" @input="groupSets()"
                                placeholder="Set suchen (Name, Kürzel)…"
                                class="w-full bg-surface-container-low border-none rounded-lg pl-10 pr-4 py-2.5 text-body-sm focus:ring-2 focus:ring-primary outline-none transition-all">
                        </div>
                        <div class="flex rounded-lg bg-surface-container-low border border-outline-variant p-0.5" role="group" aria-label="Set-Sprache">
                            <button @click="setSetsLang('de')" class="px-3 py-1.5 rounded text-sm font-semibold transition-colors flex items-center gap-1.5"
                                :class="setsLang === 'de' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:text-on-surface'">🇩🇪 Deutsch</button>
                            <button @click="setSetsLang('ja')" class="px-3 py-1.5 rounded text-sm font-semibold transition-colors flex items-center gap-1.5"
                                :class="setsLang === 'ja' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:text-on-surface'">🇯🇵 Japanisch</button>
                        </div>
                        <span class="font-mono text-label-mono text-on-surface-variant">
                            <span x-text="setCount(setsView)"></span> Sets
                        </span>
                        <button @click="rebuildSets()" :disabled="rebuildingSets"
                            class="font-mono text-label-mono px-3 py-2 rounded-full bg-surface-container hover:bg-surface-variant text-on-surface transition-colors flex items-center gap-1.5 disabled:opacity-50"
                            title="Set-Verzeichnis (DE + JA) neu von TCGdex laden – dauert ca. 1–2 Minuten">
                            <span class="material-symbols-outlined text-[18px]" :class="rebuildingSets ? 'animate-spin' : ''">sync</span>
                            <span x-show="!rebuildingSets">Verzeichnis</span>
                            <span x-show="rebuildingSets">Aktualisiere…</span>
                        </button>
                    </div>

                    <p x-show="setsLoading" class="text-body-sm text-on-surface-variant py-10 text-center flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span> Sets werden geladen…
                    </p>

                    <template x-for="g in setGroups" :key="g.serie">
                        <div class="space-y-3">
                            <h3 class="font-mono text-label-mono font-bold text-on-surface-variant uppercase tracking-wider sticky top-16 md:top-24 bg-background/80 backdrop-blur py-2 z-10" x-text="g.serie"></h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 gap-3 md:gap-gutter">
                                <template x-for="s in g.items" :key="s.id">
                                    <button @click="openSetView(s)"
                                        class="zx-card text-left bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-4 flex items-center gap-4">
                                        <div class="h-14 w-20 shrink-0 grid place-items-center bg-surface-container-low rounded-lg overflow-hidden">
                                            <img x-show="s.logo" :src="s.logo" @error="$event.target.style.display='none'" :alt="s.name" class="max-h-12 max-w-full object-contain">
                                            <span x-show="!s.logo" class="font-mono text-label-mono font-bold text-on-surface-variant px-1 text-center" x-text="s.abbr || s.id"></span>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-body-lg font-semibold text-on-surface leading-tight truncate" :class="(s.lang === 'ja' && !s.nameEn) ? 'capitalize' : ''" x-text="setTitle(s)"></div>
                                            <div x-show="setSub(s)" class="text-[11px] text-on-surface-variant/80 leading-tight truncate" x-text="setSub(s)"></div>
                                            <div class="font-mono text-[11px] text-on-surface-variant mt-1">
                                                <span x-text="s.cardCount + ' Karten'"></span>
                                                <span x-show="s.abbr"> · <span x-text="s.abbr"></span></span>
                                            </div>
                                            <div class="font-mono text-[11px] text-on-surface-variant" x-show="s.releaseDate" x-text="s.releaseDate"></div>
                                        </div>
                                        <span class="material-symbols-outlined text-on-surface-variant">chevron_right</span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>

                    <p x-show="!setsLoading && setGroups.length === 0" class="text-body-sm text-on-surface-variant text-center py-10"
                        x-text="setsView === 'pocket' ? 'Keine Pokémon-Pocket-Sets in diesem Katalog.' : 'Keine Sets gefunden.'"></p>
                </div>
            </template>

            <template x-if="openSetData">
                <div class="space-y-4">
                    <div class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-4 flex items-center gap-3">
                        <button @click="closeSetView()" class="h-10 w-10 grid place-items-center rounded-full bg-surface-container hover:bg-surface-variant text-on-surface transition-colors shrink-0">
                            <span class="material-symbols-outlined">arrow_back</span>
                        </button>
                        <div class="h-12 w-16 shrink-0 grid place-items-center bg-surface-container-low rounded-lg overflow-hidden">
                            <img x-show="openSetData.set && openSetData.set.logo" :src="openSetData.set.logo" @error="$event.target.style.display='none'" class="max-h-10 max-w-full object-contain">
                            <img x-show="openSetData.set && !openSetData.set.logo && openSetData.set.symbol" :src="openSetData.set.symbol" @error="$event.target.style.display='none'" class="max-h-8 max-w-full object-contain">
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-title-md font-bold text-on-surface leading-tight truncate" :class="(openSetData.set && openSetData.set.lang === 'ja' && !openSetData.set.nameEn) ? 'capitalize' : ''" x-text="openSetData.set ? setTitle(openSetData.set) : ''"></h2>
                            <p x-show="openSetData.set && setSub(openSetData.set)" class="text-[11px] text-on-surface-variant/80 truncate" x-text="openSetData.set ? setSub(openSetData.set) : ''"></p>
                            <p class="font-mono text-[11px] text-on-surface-variant">
                                <span x-text="openSetData.cards.length + ' Karten'"></span>
                                <span x-show="openSetData.set && openSetData.set.releaseDate"> · <span x-text="openSetData.set.releaseDate"></span></span>
                            </p>
                        </div>
                        <!-- Sammel-Fortschritt -->
                        <div x-show="openSetData.set && openSetData.set.cardCount" class="shrink-0 text-right">
                            <div class="font-mono text-label-mono text-on-surface-variant uppercase">Gesammelt</div>
                            <div class="font-mono text-body-sm font-bold text-secondary tabular-nums">
                                <span x-text="(openSetData.set ? openSetData.set.ownedCount : 0)"></span>/<span x-text="openSetData.set ? openSetData.set.cardCount : 0"></span>
                            </div>
                            <div class="h-1.5 w-24 rounded-full bg-surface-container overflow-hidden mt-1">
                                <div class="h-full rounded-full bg-gradient-to-r from-secondary to-primary transition-all duration-500"
                                     :style="`width: ${openSetData.set && openSetData.set.cardCount ? Math.round((openSetData.set.ownedCount / openSetData.set.cardCount) * 100) : 0}%`"></div>
                            </div>
                        </div>
                    </div>

                    <div x-show="setCardsLoading" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7 gap-3 md:gap-gutter">
                        <template x-for="i in 14" :key="i">
                            <div class="bg-surface-container-lowest rounded-xl border border-outline-variant overflow-hidden">
                                <div class="aspect-[63/88] zx-skel"></div>
                                <div class="p-3 space-y-2">
                                    <div class="h-3 w-3/4 rounded zx-skel"></div>
                                    <div class="h-2 w-1/2 rounded zx-skel"></div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7 gap-3 md:gap-gutter">
                        <template x-for="r in openSetData.cards" :key="r.id">
                            <button @click="openCard(r)" class="zx-card text-left bg-surface-container-lowest rounded-xl border zx-shadow overflow-hidden flex flex-col group" :class="r.owned > 0 ? 'border-success ring-1 ring-success' : 'border-outline-variant'">
                                <div class="relative aspect-[63/88] bg-surface-container-low">
                                    <img :src="r.image || placeholder" @error="$event.target.src = placeholder" @load="$event.target.classList.add('img-in')" :alt="r.name" loading="lazy" decoding="async" class="zx-img absolute inset-0 h-full w-full object-contain p-1 group-hover:scale-[1.03] transition-transform duration-500">
                                    <div x-show="r.localId" class="absolute top-2 left-2 px-2 py-0.5 rounded-full bg-surface/90 backdrop-blur text-on-surface font-mono text-[10px] font-bold border border-outline-variant" x-text="r.localId"></div>
                                    <div x-show="r.owned > 0" class="absolute top-2 right-2 flex items-center gap-0.5 pl-1 pr-1.5 py-0.5 rounded-full bg-success text-white font-mono text-[10px] font-bold shadow">
                                        <span class="material-symbols-outlined text-[14px] fill-icon">check</span><span x-text="r.owned"></span>
                                    </div>
                                    <div x-show="r.pricing && r.pricing.trend" class="absolute bottom-2 right-2 px-2 py-0.5 rounded-full bg-surface/90 backdrop-blur text-secondary font-mono text-[10px] font-bold border border-outline-variant tabular-nums" x-text="r.pricing ? fmtMoney(r.pricing.trend) : ''"></div>
                                </div>
                                <div class="p-3">
                                    <h3 class="text-body-lg font-semibold text-on-surface truncate leading-tight" x-text="r.name"></h3>
                                    <p x-show="altName(r)" class="text-[11px] text-secondary font-medium truncate" x-text="altName(r)"></p>
                                    <p class="font-mono text-[11px] truncate mt-0.5" :class="r.owned > 0 ? 'text-success font-bold' : 'text-on-surface-variant'" x-text="r.owned > 0 ? ('In Sammlung · ×' + r.owned) : 'Tippen zum Hinzufügen'"></p>
                                </div>
                            </button>
                        </template>
                    </div>
                </div>
            </template>
        </section>

        <!-- ============================ SCANNEN ============================ -->
        <section x-show="tab === 'scan'" class="space-y-5">
            <div class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-4 md:p-6 grid lg:grid-cols-2 gap-6 items-start">
                <!-- Viewfinder -->
                <div class="relative w-full max-w-xs sm:max-w-sm mx-auto aspect-[63/88] rounded-xl overflow-hidden bg-inverse-surface">
                    <video x-ref="video" autoplay playsinline muted class="h-full w-full object-cover" :class="cameraActive ? '' : 'opacity-30'"></video>
                    <template x-if="cameraActive">
                        <div class="absolute inset-0 pointer-events-none">
                            <div class="scanner-corners absolute inset-4 z-30"></div>
                            <div class="scanner-corners-bottom absolute inset-4 z-30"></div>
                            <div class="scan-line absolute left-4 right-4 h-1 bg-primary shadow-[0_0_15px_rgba(188,1,0,0.8)] z-20"></div>
                            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 opacity-40 z-30">
                                <span class="material-symbols-outlined text-white text-3xl">add</span>
                            </div>
                        </div>
                    </template>
                    <div x-show="!cameraActive" class="absolute inset-0 flex flex-col items-center justify-center text-inverse-on-surface/70 text-body-sm gap-2 px-6 text-center">
                        <span class="material-symbols-outlined text-[40px]">photo_camera</span>
                        Kamera aus
                    </div>
                </div>
                <canvas x-ref="canvas" class="hidden"></canvas>

                <!-- Steuerung & Status -->
                <div class="flex flex-col gap-4">
                    <div>
                        <h3 class="text-title-md font-semibold text-on-background">Karte scannen</h3>
                        <p class="text-body-sm text-on-surface-variant mt-1">Richte die Karte im Rahmen aus. Die <span class="font-semibold text-on-surface">Kartennummer</span> (z. B. 136/189) muss scharf &amp; gut lesbar sein.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button x-show="!cameraActive" @click="startCamera()"
                            class="px-4 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-bold uppercase tracking-wide flex items-center gap-2 hover:shadow-md transition-all">
                            <span class="material-symbols-outlined text-[20px]">photo_camera</span> Kamera starten
                        </button>
                        <button x-show="cameraActive" @click="captureAndScan()" :disabled="scanning"
                            class="px-4 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-bold uppercase tracking-wide flex items-center gap-2 disabled:opacity-50 hover:shadow-md transition-all">
                            <span class="material-symbols-outlined text-[20px]">document_scanner</span>
                            <span x-show="!scanning">Karte scannen</span>
                            <span x-show="scanning">Analysiere… <span class="font-mono" x-text="scanProgress"></span></span>
                        </button>
                        <button x-show="cameraActive" @click="stopCamera()"
                            class="px-4 py-2.5 rounded-lg bg-surface-container hover:bg-surface-variant text-on-surface text-sm font-bold flex items-center gap-2 transition-colors">
                            <span class="material-symbols-outlined text-[20px]">stop_circle</span> Stoppen
                        </button>
                    </div>

                    <div x-show="scanError" class="text-body-sm text-on-error-container bg-error-container/60 border border-error/30 rounded-lg p-3 flex items-start gap-2">
                        <span class="material-symbols-outlined text-[18px] shrink-0">error</span>
                        <span x-text="scanError"></span>
                    </div>
                    <div x-show="ocrText" class="font-mono text-label-mono text-on-surface-variant bg-surface-container-low rounded-lg p-3 break-words">
                        Erkannt: <span class="text-on-surface" x-text="ocrSummary"></span>
                    </div>
                </div>
            </div>

            <div x-show="scanMatches.length > 0" class="space-y-3">
                <h3 class="text-title-md font-semibold text-on-background">Treffer – tippe zum Hinzufügen</h3>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7 gap-3 md:gap-gutter">
                    <template x-for="r in scanMatches" :key="r.id">
                        <button @click="openCard(r)" class="zx-card text-left bg-surface-container-lowest rounded-xl border zx-shadow overflow-hidden flex flex-col group" :class="r.owned > 0 ? 'border-success ring-1 ring-success' : 'border-outline-variant'">
                            <div class="relative aspect-[63/88] bg-surface-container-low">
                                <img :src="r.image || placeholder" @error="$event.target.src = placeholder" @load="$event.target.classList.add('img-in')" :alt="r.name" loading="lazy" decoding="async" class="zx-img absolute inset-0 h-full w-full object-contain p-1 group-hover:scale-[1.03] transition-transform duration-500">
                                <div x-show="r.owned > 0" class="absolute top-2 right-2 flex items-center gap-0.5 pl-1 pr-1.5 py-0.5 rounded-full bg-success text-white font-mono text-[10px] font-bold shadow">
                                    <span class="material-symbols-outlined text-[14px] fill-icon">check</span><span x-text="r.owned"></span>
                                </div>
                            </div>
                            <div class="p-3">
                                <h3 class="text-body-lg font-semibold text-on-surface truncate leading-tight" x-text="r.name"></h3>
                                <p x-show="altName(r)" class="text-[11px] text-secondary font-medium truncate" x-text="altName(r)"></p>
                                <p class="font-mono text-[11px] text-on-surface-variant truncate mt-0.5" x-text="(r.set || '—') + (r.localId ? ' · ' + r.localId : '')"></p>
                            </div>
                        </button>
                    </template>
                </div>
            </div>
        </section>

        <!-- ============================ STATISTIK ============================ -->
        <section x-show="tab === 'stats'" class="space-y-stack-lg">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-gutter">
                <div class="md:col-span-1 bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-6 relative overflow-hidden">
                    <div class="absolute -right-16 -top-16 w-48 h-48 bg-secondary/5 rounded-full blur-3xl pointer-events-none"></div>
                    <p class="font-mono text-label-mono text-on-surface-variant uppercase tracking-wider">Gesamtwert</p>
                    <p class="text-headline-lg font-extrabold text-secondary tabular-nums mt-1" x-text="fmtMoney(stats.totalValue)"></p>
                </div>
                <div class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-6">
                    <p class="font-mono text-label-mono text-on-surface-variant uppercase tracking-wider">Karten gesamt</p>
                    <p class="text-headline-lg font-extrabold text-on-background tabular-nums mt-1" x-text="stats.totalQuantity"></p>
                </div>
                <div class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-6">
                    <p class="font-mono text-label-mono text-on-surface-variant uppercase tracking-wider">Verschiedene Karten</p>
                    <p class="text-headline-lg font-extrabold text-on-background tabular-nums mt-1" x-text="stats.uniqueCards"></p>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-gutter items-start">
                <div x-show="stats.bySet && stats.bySet.length" class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-6">
                    <h3 class="text-title-md font-semibold text-on-background mb-4">Wert nach Set</h3>
                    <div class="space-y-3">
                        <template x-for="s in stats.bySet" :key="s.set">
                            <div>
                                <div class="flex justify-between items-baseline text-body-sm mb-1 gap-2">
                                    <span class="text-on-surface truncate min-w-0" x-text="s.set"></span>
                                    <span class="font-mono text-label-mono text-on-surface-variant tabular-nums shrink-0" x-text="fmtMoney(s.value) + ' · ' + s.count + ' St.'"></span>
                                </div>
                                <div class="h-2.5 rounded-full bg-surface-container overflow-hidden">
                                    <div class="h-full rounded-full bg-gradient-to-r from-secondary to-primary" :style="`width: ${barWidth(s.value)}%`"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="stats.topCards && stats.topCards.length" class="bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-6">
                    <h3 class="text-title-md font-semibold text-on-background mb-4">Wertvollste Karten</h3>
                    <div class="divide-y divide-outline-variant">
                        <template x-for="c in stats.topCards" :key="c.id">
                            <div class="flex items-center gap-3 py-3">
                                <img :src="c.image || placeholder" @error="$event.target.src = placeholder" class="h-14 w-10 object-contain rounded bg-surface-container-low shrink-0" :alt="c.name">
                                <div class="min-w-0 flex-1">
                                    <div class="text-body-lg font-medium text-on-surface truncate" x-text="c.name"></div>
                                    <div class="font-mono text-[11px] text-on-surface-variant truncate" x-text="(c.setName || '') + ' · ' + variantLabel(c.variant) + ' · ' + c.condition"></div>
                                </div>
                                <div class="text-right shrink-0">
                                    <div class="font-mono text-body-sm font-bold text-secondary tabular-nums" x-text="fmtMoney(c.unitPrice)"></div>
                                    <div class="font-mono text-[11px] text-on-surface-variant tabular-nums" x-text="'×' + c.quantity"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================ ADMIN ============================ -->
        <section x-show="tab === 'admin'" class="space-y-stack-lg">
            <template x-if="!isAdmin()">
                <div class="text-center py-20 rounded-xl border-2 border-dashed border-outline-variant bg-surface-container/30">
                    <span class="material-symbols-outlined text-[32px] text-on-surface-variant">lock</span>
                    <p class="text-title-md font-semibold text-on-background mt-2">Kein Zugriff</p>
                    <p class="text-body-sm text-on-surface-variant">Dieser Bereich ist nur für Administratoren.</p>
                </div>
            </template>

            <template x-if="isAdmin()">
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-gutter items-start">
                    <!-- Neuen Benutzer anlegen -->
                    <div class="xl:col-span-1 bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-5 space-y-4">
                        <h3 class="text-title-md font-semibold text-on-background flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">person_add</span> Benutzer anlegen
                        </h3>
                        <label class="block text-sm">
                            <span class="font-mono text-label-mono text-on-surface-variant uppercase">Benutzername</span>
                            <input type="text" x-model="adminForm.username" @keydown.enter.prevent="adminCreateUser()" autocomplete="off"
                                class="mt-1 w-full rounded-lg bg-surface-container-low border-none focus:ring-2 focus:ring-primary text-body-sm">
                        </label>
                        <label class="block text-sm">
                            <span class="font-mono text-label-mono text-on-surface-variant uppercase">Passwort</span>
                            <input type="password" x-model="adminForm.password" @keydown.enter.prevent="adminCreateUser()" autocomplete="new-password"
                                class="mt-1 w-full rounded-lg bg-surface-container-low border-none focus:ring-2 focus:ring-primary text-body-sm">
                        </label>
                        <label class="block text-sm">
                            <span class="font-mono text-label-mono text-on-surface-variant uppercase">Rolle</span>
                            <select x-model="adminForm.role" class="mt-1 w-full rounded-lg bg-surface-container-low border-none focus:ring-2 focus:ring-primary text-body-sm">
                                <option value="user">Benutzer</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </label>
                        <p x-show="adminError" class="text-body-sm text-error" x-text="adminError"></p>
                        <button @click="adminCreateUser()" :disabled="adminBusy || !adminForm.username || !adminForm.password"
                            class="w-full px-4 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-bold uppercase tracking-wide disabled:opacity-50 hover:shadow-md transition-all flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-[18px]">add_circle</span>
                            <span x-text="adminBusy ? '…' : 'Anlegen'"></span>
                        </button>

                        <!-- Wartung -->
                        <div class="pt-4 border-t border-outline-variant space-y-2">
                            <h4 class="font-mono text-label-mono font-bold text-on-surface-variant uppercase tracking-wider">Wartung</h4>
                            <button @click="rebuildSets()" :disabled="rebuildingSets"
                                class="w-full px-4 py-2.5 rounded-lg bg-surface-container hover:bg-surface-variant text-on-surface text-sm font-semibold disabled:opacity-50 transition-colors flex items-center justify-center gap-2"
                                title="Set-Verzeichnis (DE + JA) neu von TCGdex laden – dauert ca. 1–2 Minuten">
                                <span class="material-symbols-outlined text-[18px]" :class="rebuildingSets ? 'animate-spin' : ''">sync</span>
                                <span x-text="rebuildingSets ? 'Aktualisiere…' : 'Set-Verzeichnis neu aufbauen'"></span>
                            </button>
                            <p class="text-[11px] text-on-surface-variant leading-snug">Lädt alle Sets/Karten neu von TCGdex (DE + JA). Betrifft alle Benutzer.</p>
                        </div>
                    </div>

                    <!-- Benutzerliste -->
                    <div class="xl:col-span-2 bg-surface-container-lowest rounded-xl border border-outline-variant zx-shadow p-5">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-title-md font-semibold text-on-background flex items-center gap-2">
                                <span class="material-symbols-outlined text-secondary">group</span> Benutzer
                                <span class="font-mono text-label-mono text-on-surface-variant" x-text="'(' + adminUsers.length + ')'"></span>
                            </h3>
                            <button @click="loadUsers()" :disabled="adminLoading" class="font-mono text-label-mono px-3 py-1.5 rounded-full bg-surface-container hover:bg-surface-variant text-on-surface transition-colors flex items-center gap-1.5 disabled:opacity-50">
                                <span class="material-symbols-outlined text-[16px]" :class="adminLoading ? 'animate-spin' : ''">refresh</span> Aktualisieren
                            </button>
                        </div>

                        <div class="space-y-2">
                            <template x-for="u in adminUsers" :key="u.id">
                                <div class="rounded-lg border border-outline-variant bg-surface-container-low p-3 flex flex-col sm:flex-row sm:items-center gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-semibold text-on-surface truncate" x-text="u.username"></span>
                                            <span class="font-mono text-[10px] px-2 py-0.5 rounded-full uppercase tracking-wider"
                                                :class="u.role === 'admin' ? 'bg-primary-fixed/60 text-primary' : 'bg-surface-container text-on-surface-variant'"
                                                x-text="u.role === 'admin' ? 'Admin' : 'User'"></span>
                                            <span x-show="!u.isActive" class="font-mono text-[10px] px-2 py-0.5 rounded-full bg-error-container text-on-error-container uppercase tracking-wider">Inaktiv</span>
                                            <span x-show="u.id === (auth.user && auth.user.id)" class="font-mono text-[10px] px-2 py-0.5 rounded-full bg-surface-variant text-on-surface-variant uppercase tracking-wider">Du</span>
                                        </div>
                                        <p class="font-mono text-[11px] text-on-surface-variant mt-1">
                                            <span x-text="u.cards"></span> Karten · <span x-text="u.items"></span> Einträge ·
                                            <span x-text="u.lastLogin ? ('zuletzt ' + new Date(u.lastLogin * 1000).toLocaleDateString('de-DE')) : 'nie angemeldet'"></span>
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-1 shrink-0 flex-wrap">
                                        <button @click="adminToggleRole(u)" class="h-8 px-2.5 grid place-items-center rounded-lg bg-surface-container hover:bg-surface-variant text-on-surface transition-colors" :title="u.role === 'admin' ? 'Zu Benutzer machen' : 'Zu Admin machen'">
                                            <span class="material-symbols-outlined text-[18px]" x-text="u.role === 'admin' ? 'arrow_downward' : 'shield_person'"></span>
                                        </button>
                                        <button @click="adminToggleActive(u)" class="h-8 px-2.5 grid place-items-center rounded-lg bg-surface-container hover:bg-surface-variant text-on-surface transition-colors" :title="u.isActive ? 'Deaktivieren' : 'Aktivieren'">
                                            <span class="material-symbols-outlined text-[18px]" x-text="u.isActive ? 'toggle_on' : 'toggle_off'"></span>
                                        </button>
                                        <button @click="adminResetPassword(u)" class="h-8 px-2.5 grid place-items-center rounded-lg bg-surface-container hover:bg-surface-variant text-on-surface transition-colors" title="Passwort zurücksetzen">
                                            <span class="material-symbols-outlined text-[18px]">key</span>
                                        </button>
                                        <button @click="adminDeleteUser(u)" class="h-8 px-2.5 grid place-items-center rounded-lg bg-error-container/60 hover:bg-error-container text-on-error-container transition-colors" title="Löschen">
                                            <span class="material-symbols-outlined text-[18px]">delete</span>
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <p x-show="!adminLoading && adminUsers.length === 0" class="text-body-sm text-on-surface-variant text-center py-6">Keine Benutzer.</p>
                        </div>
                    </div>
                </div>
            </template>
        </section>
    </main>

    <!-- ============================ BOTTOM-NAV (Mobile) ============================ -->
    <nav class="md:hidden fixed bottom-0 left-0 w-full bg-surface/95 backdrop-blur-md border-t border-outline-variant z-50 shadow-[0_-4px_20px_rgba(0,0,0,0.05)]" style="padding-bottom: env(safe-area-inset-bottom);">
        <div class="flex justify-around items-center h-16">
            <button @click="setTab('collection')" class="flex flex-col items-center justify-center w-full h-full transition-colors" :class="tab === 'collection' ? 'text-primary' : 'text-on-surface-variant'">
                <span class="material-symbols-outlined text-[24px]" :class="tab === 'collection' ? 'fill-icon' : ''">style</span>
                <span class="font-mono text-[10px] mt-0.5" :class="tab === 'collection' ? 'font-bold' : ''">Sammlung</span>
            </button>
            <button @click="setTab('search')" class="flex flex-col items-center justify-center w-full h-full transition-colors" :class="tab === 'search' ? 'text-primary' : 'text-on-surface-variant'">
                <span class="material-symbols-outlined text-[24px]" :class="tab === 'search' ? 'fill-icon' : ''">search</span>
                <span class="font-mono text-[10px] mt-0.5" :class="tab === 'search' ? 'font-bold' : ''">Suchen</span>
            </button>
            <!-- Scan-FAB -->
            <div class="relative -top-5 w-full flex justify-center">
                <button @click="setTab('scan')"
                    class="w-14 h-14 rounded-full bg-primary text-on-primary shadow-[0_8px_16px_rgba(188,1,0,0.3)] grid place-items-center active:scale-95 transition-transform"
                    :class="tab === 'scan' ? 'ring-4 ring-primary-fixed' : ''">
                    <span class="material-symbols-outlined text-[28px]">qr_code_scanner</span>
                </button>
            </div>
            <button @click="setTab('sets')" class="flex flex-col items-center justify-center w-full h-full transition-colors" :class="tab === 'sets' ? 'text-primary' : 'text-on-surface-variant'">
                <span class="material-symbols-outlined text-[24px]" :class="tab === 'sets' ? 'fill-icon' : ''">auto_awesome_motion</span>
                <span class="font-mono text-[10px] mt-0.5" :class="tab === 'sets' ? 'font-bold' : ''">Sets</span>
            </button>
            <button @click="setTab('stats')" class="flex flex-col items-center justify-center w-full h-full transition-colors" :class="tab === 'stats' ? 'text-primary' : 'text-on-surface-variant'">
                <span class="material-symbols-outlined text-[24px]" :class="tab === 'stats' ? 'fill-icon' : ''">monitoring</span>
                <span class="font-mono text-[10px] mt-0.5" :class="tab === 'stats' ? 'font-bold' : ''">Statistik</span>
            </button>
        </div>
    </nav>

    <!-- ============================ KARTEN-DETAILSEITE ============================ -->
    <div x-show="cardView" x-transition.opacity @keydown.escape.window="cardView && closeCard()" class="fixed inset-0 z-[60] bg-black/60 backdrop-blur-sm overflow-y-auto" @click.self="closeCard()">
        <div class="min-h-full flex items-start justify-center p-0 sm:p-4 md:p-6">
            <div class="w-full max-w-5xl bg-surface-container-lowest border border-outline-variant sm:rounded-2xl min-h-screen sm:min-h-0 shadow-[0_12px_40px_rgba(0,0,0,0.18)]" x-show="cardView" x-transition>
                <!-- Kopfzeile -->
                <div class="sticky top-0 z-10 flex items-center gap-3 px-4 sm:px-6 py-3 bg-surface-container-lowest/95 backdrop-blur border-b border-outline-variant sm:rounded-t-2xl">
                    <button @click="closeCard()" class="h-10 w-10 grid place-items-center rounded-full bg-surface-container hover:bg-surface-variant text-on-surface transition-colors shrink-0">
                        <span class="material-symbols-outlined">arrow_back</span>
                    </button>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-title-md font-bold text-on-surface leading-tight truncate" x-text="cardView ? (cardView.base.name) : ''"></h2>
                        <p class="font-mono text-[11px] text-on-surface-variant truncate" x-text="cardView ? ((cardView.base.set || '—') + (cardView.base.localId ? ' · ' + cardView.base.localId : '')) : ''"></p>
                    </div>
                    <span x-show="cardView && cardView.owned > 0" class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-success text-white font-mono text-[11px] font-bold">
                        <span class="material-symbols-outlined text-[16px] fill-icon">check_circle</span><span x-text="'×' + (cardView ? cardView.owned : 0)"></span>
                    </span>
                </div>

                <template x-if="cardView">
                <div class="grid md:grid-cols-[minmax(0,320px)_1fr] gap-5 md:gap-8 p-4 sm:p-6">
                    <!-- Bild + Hinzufuegen -->
                    <div class="space-y-4">
                        <div class="relative aspect-[63/88] w-full max-w-[320px] mx-auto bg-surface-container-low rounded-xl overflow-hidden border border-outline-variant">
                            <img :src="cardHero()" @error="$event.target.src = placeholder" @load="$event.target.classList.add('img-in')" :alt="cardView.base.name" class="zx-img absolute inset-0 h-full w-full object-contain p-2">
                        </div>
                        <!-- Hinzufuegen-Panel -->
                        <div class="bg-surface-container-low rounded-xl border border-outline-variant p-4 space-y-3">
                            <h3 class="font-mono text-label-mono font-bold text-on-surface-variant uppercase tracking-wider">Zur Sammlung hinzufügen</h3>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="text-sm">
                                    <span class="font-mono text-label-mono text-on-surface-variant uppercase">Variante</span>
                                    <select x-model="addForm.variant" class="mt-1 w-full rounded-lg bg-surface-container-lowest border-none focus:ring-2 focus:ring-primary text-body-sm">
                                        <template x-for="v in (addCard && addCard.variants ? addCard.variants : ['normal'])" :key="v">
                                            <option :value="v" x-text="variantLabel(v)"></option>
                                        </template>
                                    </select>
                                </label>
                                <label class="text-sm">
                                    <span class="font-mono text-label-mono text-on-surface-variant uppercase">Zustand</span>
                                    <select x-model="addForm.condition" class="mt-1 w-full rounded-lg bg-surface-container-lowest border-none focus:ring-2 focus:ring-primary text-body-sm">
                                        <template x-for="c in conditions" :key="c"><option :value="c" x-text="c"></option></template>
                                    </select>
                                </label>
                                <label class="text-sm">
                                    <span class="font-mono text-label-mono text-on-surface-variant uppercase">Sprache</span>
                                    <select x-model="addForm.language" :disabled="cardView.lang === 'ja'" class="mt-1 w-full rounded-lg bg-surface-container-lowest border-none focus:ring-2 focus:ring-primary text-body-sm disabled:opacity-70">
                                        <template x-for="pl in printLangs()" :key="pl.id">
                                            <option :value="pl.id" x-text="pl.label"></option>
                                        </template>
                                    </select>
                                </label>
                                <label class="text-sm">
                                    <span class="font-mono text-label-mono text-on-surface-variant uppercase">Anzahl</span>
                                    <input type="number" min="1" x-model.number="addForm.quantity" class="mt-1 w-full rounded-lg bg-surface-container-lowest border-none focus:ring-2 focus:ring-primary text-body-sm">
                                </label>
                            </div>
                            <button @click="confirmAdd()" :disabled="addBusy" class="w-full px-4 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-bold uppercase tracking-wide disabled:opacity-50 hover:shadow-md transition-all flex items-center justify-center gap-2">
                                <span x-show="!addBusy" class="flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">add_circle</span> Hinzufügen</span>
                                <span x-show="addBusy">…</span>
                            </button>
                            <p class="text-[11px] text-on-surface-variant leading-snug">
                                Preis = Cardmarket-Trend der Karte (unabhängig von Druck-Sprache &amp; Zustand – diese werden nur als Info gespeichert).
                            </p>
                        </div>
                    </div>

                    <!-- Infos -->
                    <div class="space-y-5 min-w-0">
                        <!-- Titel + Meta -->
                        <div>
                            <h1 class="text-2xl md:text-3xl font-extrabold text-on-surface leading-tight" x-text="cardView.base.name"></h1>
                            <p x-show="cardView.names && (cardView.names.de || cardView.names.en)" class="text-body-lg text-secondary font-medium"
                               x-text="cardView.names ? [cardView.names.de, cardView.names.en].filter(Boolean).join(' · ') : ''"></p>
                            <div class="flex flex-wrap items-center gap-2 mt-2">
                                <template x-for="t in (cardView.card && cardView.card.types) || []" :key="t">
                                    <span class="px-2.5 py-1 rounded-full bg-surface-container text-on-surface font-mono text-[11px] font-semibold" x-text="t"></span>
                                </template>
                                <span x-show="cardView.card && cardView.card.hp" class="px-2.5 py-1 rounded-full bg-primary-fixed/50 text-primary font-mono text-[11px] font-bold" x-text="'HP ' + (cardView.card ? cardView.card.hp : '')"></span>
                                <span x-show="cardView.card && cardView.card.rarity" class="px-2.5 py-1 rounded-full bg-surface-container text-on-surface-variant font-mono text-[11px]" x-text="cardView.card?.rarity"></span>
                                <span x-show="cardView.card && cardView.card.stage" class="px-2.5 py-1 rounded-full bg-surface-container text-on-surface-variant font-mono text-[11px]" x-text="cardView.card?.stage"></span>
                                <span x-show="cardView.card && cardView.card.regulationMark" class="px-2.5 py-1 rounded-full bg-surface-container text-on-surface-variant font-mono text-[11px]" x-text="'Reg. ' + (cardView.card?.regulationMark || '')"></span>
                            </div>
                        </div>

                        <!-- Preis -->
                        <div class="bg-surface-container-low rounded-xl border border-outline-variant p-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <h3 class="font-mono text-label-mono font-bold text-on-surface-variant uppercase tracking-wider flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[18px] text-secondary fill-icon">sell</span> Preis
                                </h3>
                                <a :href="cardmarketUrl()" target="_blank" rel="noopener" class="font-mono text-[11px] text-secondary hover:underline flex items-center gap-1">
                                    Auf Cardmarket prüfen <span class="material-symbols-outlined text-[14px]">open_in_new</span>
                                </a>
                            </div>

                            <!-- Eigener (manueller) Preis aktiv -->
                            <div x-show="cardView.override != null" class="flex items-center justify-between rounded-lg bg-tertiary-fixed/40 border border-tertiary/30 px-3 py-2">
                                <div>
                                    <div class="font-mono text-[10px] text-on-surface-variant uppercase tracking-wide">Eigener Preis</div>
                                    <div class="font-mono text-title-md font-bold text-on-surface tabular-nums" x-text="fmtMoney(cardView.override)"></div>
                                </div>
                                <button @click="clearOverride()" :disabled="savingOverride" class="font-mono text-[11px] text-on-surface-variant hover:text-error flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[16px]">close</span> zurücksetzen
                                </button>
                            </div>

                            <!-- Quellpreise (Cardmarket) -->
                            <div x-show="cardView.price" :class="cardView.override != null ? 'opacity-60' : ''">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-mono text-[10px] text-on-surface-variant uppercase tracking-wide" x-text="cardView.override != null ? 'Quelle (Cardmarket)' : ''"></span>
                                    <span x-show="cardView.price && cardView.price.cmUpdated" class="font-mono text-[10px] text-on-surface-variant" x-text="cardView.price ? ('Stand: ' + (cardView.price.cmUpdated || '').slice(0,10)) : ''"></span>
                                </div>
                                <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                                    <template x-for="st in priceStats(cardView.price)" :key="st.label">
                                        <div class="text-center">
                                            <div class="font-mono text-[10px] text-on-surface-variant uppercase tracking-wide" x-text="st.label"></div>
                                            <div class="font-mono text-body-sm font-bold text-secondary tabular-nums" x-text="st.value"></div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <p x-show="!cardView.price && !cardView.loading && cardView.override == null" class="font-mono text-[11px] text-on-surface-variant">Kein Quellpreis verfügbar.</p>

                            <!-- Manuelle Korrektur setzen -->
                            <div class="flex items-end gap-2 pt-1 border-t border-outline-variant">
                                <label class="text-sm flex-1">
                                    <span class="font-mono text-[10px] text-on-surface-variant uppercase tracking-wide">Preis korrigieren (€)</span>
                                    <input type="number" min="0" step="0.01" x-model="overrideInput" @keydown.enter.prevent="saveOverride()" placeholder="z. B. 99.90"
                                        class="mt-1 w-full rounded-lg bg-surface-container-lowest border-none focus:ring-2 focus:ring-primary text-body-sm">
                                </label>
                                <button @click="saveOverride()" :disabled="savingOverride" class="px-3 py-2.5 rounded-lg bg-secondary text-on-secondary text-sm font-bold disabled:opacity-50 hover:shadow-md transition-all">Speichern</button>
                            </div>
                        </div>
                        <p x-show="cardView.loading" class="text-body-sm text-on-surface-variant flex items-center gap-2">
                            <span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span> Lade Details…
                        </p>

                        <!-- Beschreibung / Flavortext -->
                        <p x-show="cardView.card && cardView.card.description" class="text-body-sm italic text-on-surface-variant border-l-2 border-outline-variant pl-3" x-text="cardView.card ? cardView.card.description : ''"></p>

                        <!-- Attacken -->
                        <div x-show="cardView.card && cardView.card.attacks && cardView.card.attacks.length" class="space-y-2">
                            <h3 class="font-mono text-label-mono font-bold text-on-surface-variant uppercase tracking-wider">Attacken</h3>
                            <template x-for="(atk, i) in (cardView.card ? cardView.card.attacks : [])" :key="i">
                                <div class="bg-surface-container-low rounded-lg border border-outline-variant p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <div class="flex gap-0.5 shrink-0">
                                                <template x-for="(e, j) in (atk.cost || [])" :key="j">
                                                    <span class="h-5 w-5 grid place-items-center rounded-full bg-surface-variant text-on-surface-variant font-mono text-[10px] font-bold" :title="e" x-text="energyAbbr(e)"></span>
                                                </template>
                                            </div>
                                            <span class="font-semibold text-on-surface truncate" x-text="atk.name"></span>
                                        </div>
                                        <span x-show="atk.damage" class="font-mono font-bold text-primary tabular-nums shrink-0" x-text="atk.damage"></span>
                                    </div>
                                    <p x-show="atk.effect" class="text-body-sm text-on-surface-variant mt-1.5" x-text="atk.effect"></p>
                                </div>
                            </template>
                        </div>

                        <!-- Faehigkeiten (falls vorhanden) -->
                        <div x-show="cardView.card && cardView.card.abilities && cardView.card.abilities.length" class="space-y-2">
                            <h3 class="font-mono text-label-mono font-bold text-on-surface-variant uppercase tracking-wider">Fähigkeiten</h3>
                            <template x-for="(ab, i) in (cardView.card ? cardView.card.abilities : [])" :key="'ab'+i">
                                <div class="bg-tertiary-fixed/30 rounded-lg border border-tertiary/20 p-3">
                                    <div class="font-semibold text-on-surface"><span class="text-tertiary font-mono text-[11px] uppercase mr-1" x-text="ab.type"></span><span x-text="ab.name"></span></div>
                                    <p x-show="ab.effect" class="text-body-sm text-on-surface-variant mt-1" x-text="ab.effect"></p>
                                </div>
                            </template>
                        </div>

                        <!-- Schwaeche / Rueckzug / Illustrator -->
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <div x-show="cardView.card && cardView.card.weaknesses && cardView.card.weaknesses.length" class="bg-surface-container-low rounded-lg border border-outline-variant p-3">
                                <div class="font-mono text-[10px] text-on-surface-variant uppercase tracking-wide">Schwäche</div>
                                <div class="font-semibold text-on-surface text-body-sm" x-text="cardView.card && cardView.card.weaknesses ? cardView.card.weaknesses.map(w => w.type + ' ' + (w.value||'')).join(', ') : ''"></div>
                            </div>
                            <div x-show="cardView.card && (cardView.card.retreat || cardView.card.retreat === 0)" class="bg-surface-container-low rounded-lg border border-outline-variant p-3">
                                <div class="font-mono text-[10px] text-on-surface-variant uppercase tracking-wide">Rückzug</div>
                                <div class="font-semibold text-on-surface text-body-sm" x-text="cardView.card ? cardView.card.retreat : ''"></div>
                            </div>
                            <div x-show="cardView.card && cardView.card.illustrator" class="bg-surface-container-low rounded-lg border border-outline-variant p-3">
                                <div class="font-mono text-[10px] text-on-surface-variant uppercase tracking-wide">Illustrator</div>
                                <div class="font-semibold text-on-surface text-body-sm truncate" x-text="cardView.card ? cardView.card.illustrator : ''"></div>
                            </div>
                        </div>

                        <!-- Verwandte Drucke -->
                        <div x-show="cardView.related && cardView.related.prints && cardView.related.prints.length" class="space-y-2">
                            <h3 class="font-mono text-label-mono font-bold text-on-surface-variant uppercase tracking-wider">Andere Drucke</h3>
                            <div class="flex gap-3 overflow-x-auto pb-2 -mx-1 px-1">
                                <template x-for="r in cardView.related.prints" :key="'pr'+r.id">
                                    <button @click="openCard(r)" class="shrink-0 w-24 text-left group">
                                        <div class="relative aspect-[63/88] bg-surface-container-low rounded-lg overflow-hidden border" :class="r.owned > 0 ? 'border-success' : 'border-outline-variant'">
                                            <img :src="r.image || placeholder" @error="$event.target.src = placeholder" @load="$event.target.classList.add('img-in')" :alt="r.name" loading="lazy" class="zx-img absolute inset-0 h-full w-full object-contain p-1">
                                            <div x-show="r.pricing && r.pricing.trend" class="absolute bottom-1 right-1 px-1.5 py-0.5 rounded-full bg-surface/90 text-secondary font-mono text-[9px] font-bold tabular-nums" x-text="r.pricing ? fmtMoney(r.pricing.trend) : ''"></div>
                                        </div>
                                        <p class="font-mono text-[10px] text-on-surface-variant truncate mt-1" x-text="r.set"></p>
                                    </button>
                                </template>
                            </div>
                        </div>

                        <!-- Weitere aus diesem Set -->
                        <div x-show="cardView.related && cardView.related.set && cardView.related.set.length" class="space-y-2">
                            <h3 class="font-mono text-label-mono font-bold text-on-surface-variant uppercase tracking-wider">Weitere aus diesem Set</h3>
                            <div class="flex gap-3 overflow-x-auto pb-2 -mx-1 px-1">
                                <template x-for="r in cardView.related.set" :key="'set'+r.id">
                                    <button @click="openCard(r)" class="shrink-0 w-20 text-left group">
                                        <div class="relative aspect-[63/88] bg-surface-container-low rounded-lg overflow-hidden border" :class="r.owned > 0 ? 'border-success' : 'border-outline-variant'">
                                            <img :src="r.image || placeholder" @error="$event.target.src = placeholder" @load="$event.target.classList.add('img-in')" :alt="r.name" loading="lazy" class="zx-img absolute inset-0 h-full w-full object-contain p-1">
                                        </div>
                                        <p class="font-mono text-[10px] text-on-surface-variant truncate mt-1" x-text="(r.localId || '') + ' ' + r.name"></p>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ============================ AUTH / KONTO ============================ -->
    <div x-show="authModal" x-transition.opacity @keydown.escape.window="authModal && closeAuth()" x-cloak
        class="fixed inset-0 z-[65] bg-black/60 backdrop-blur-sm grid place-items-center p-4" @click.self="closeAuth()">
        <div class="w-full max-w-md bg-surface-container-lowest border border-outline-variant rounded-2xl shadow-[0_12px_40px_rgba(0,0,0,0.18)] p-6" x-show="authModal" x-transition>

            <!-- Kopf -->
            <div class="flex items-center gap-3 mb-5">
                <div class="h-11 w-11 rounded-lg bg-gradient-to-b from-primary-container to-primary grid place-items-center shadow-sm ring-1 ring-primary/30 shrink-0">
                    <div class="h-3.5 w-3.5 rounded-full bg-white ring-2 ring-on-surface"></div>
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-title-md font-bold text-on-surface leading-tight"
                        x-text="authView === 'account' ? 'Konto' : (authView === 'setup' ? 'Erste Einrichtung' : 'Anmelden')"></h2>
                    <p class="font-mono text-[11px] text-on-surface-variant"
                        x-text="authView === 'account' ? 'Angemeldet' : (authView === 'setup' ? 'Admin-Konto erstellen' : 'Sammlung verwalten')"></p>
                </div>
                <button @click="closeAuth()" class="h-9 w-9 grid place-items-center rounded-full bg-surface-container hover:bg-surface-variant text-on-surface transition-colors shrink-0">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <!-- Konto-Ansicht (angemeldet) -->
            <template x-if="authView === 'account' && auth.user">
                <div class="space-y-4">
                    <div class="flex items-center gap-3 p-3 rounded-xl bg-surface-container-low border border-outline-variant">
                        <span class="material-symbols-outlined text-[28px] text-success fill-icon">account_circle</span>
                        <div class="min-w-0">
                            <p class="text-body-lg font-semibold text-on-surface truncate" x-text="auth.user.username"></p>
                            <p class="font-mono text-[11px] uppercase tracking-wider text-on-surface-variant" x-text="auth.user.role === 'admin' ? 'Administrator' : 'Benutzer'"></p>
                        </div>
                    </div>
                    <button x-show="isAdmin()" @click="closeAuth(); setTab('admin')"
                        class="w-full px-4 py-2.5 rounded-lg bg-surface-container hover:bg-surface-variant text-on-surface text-sm font-bold flex items-center justify-center gap-2 transition-colors">
                        <span class="material-symbols-outlined text-[18px]">admin_panel_settings</span> Adminpanel öffnen
                    </button>
                    <button @click="doLogout()" class="w-full px-4 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-bold uppercase tracking-wide flex items-center justify-center gap-2 hover:shadow-md transition-all">
                        <span class="material-symbols-outlined text-[18px]">logout</span> Abmelden
                    </button>
                </div>
            </template>

            <!-- Login / Setup -->
            <template x-if="authView !== 'account'">
                <div class="space-y-4">
                    <p x-show="authView === 'setup'" class="text-body-sm text-on-surface-variant">
                        Es existiert noch kein Konto. Lege jetzt das erste <span class="font-semibold text-on-surface">Administrator-Konto</span> an. Eine evtl. vorhandene lokale Sammlung bleibt unberührt.
                    </p>
                    <label class="block text-sm">
                        <span class="font-mono text-label-mono text-on-surface-variant uppercase">Benutzername</span>
                        <input type="text" x-model="authForm.username" autocomplete="username"
                            @keydown.enter.prevent="authView === 'setup' ? doSetup() : doLogin()"
                            class="mt-1 w-full rounded-lg bg-surface-container-low border-none focus:ring-2 focus:ring-primary text-body-lg">
                    </label>
                    <label class="block text-sm">
                        <span class="font-mono text-label-mono text-on-surface-variant uppercase">Passwort</span>
                        <input type="password" x-model="authForm.password" :autocomplete="authView === 'setup' ? 'new-password' : 'current-password'"
                            @keydown.enter.prevent="authView === 'setup' ? doSetup() : doLogin()"
                            class="mt-1 w-full rounded-lg bg-surface-container-low border-none focus:ring-2 focus:ring-primary text-body-lg">
                    </label>
                    <p x-show="authError" class="text-body-sm text-error flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[18px]">error</span><span x-text="authError"></span>
                    </p>
                    <button x-show="authView === 'setup'" @click="doSetup()" :disabled="authBusy || !authForm.username || !authForm.password"
                        class="w-full px-4 py-3 rounded-lg bg-primary text-on-primary text-sm font-bold uppercase tracking-wide disabled:opacity-50 hover:shadow-md transition-all flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">admin_panel_settings</span>
                        <span x-text="authBusy ? '…' : 'Konto erstellen & anmelden'"></span>
                    </button>
                    <button x-show="authView === 'login'" @click="doLogin()" :disabled="authBusy || !authForm.username || !authForm.password"
                        class="w-full px-4 py-3 rounded-lg bg-primary text-on-primary text-sm font-bold uppercase tracking-wide disabled:opacity-50 hover:shadow-md transition-all flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">login</span>
                        <span x-text="authBusy ? '…' : 'Anmelden'"></span>
                    </button>
                    <button @click="closeAuth()" class="w-full px-4 py-2 rounded-lg text-on-surface-variant hover:bg-surface-container-low text-sm font-medium transition-colors flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">person_outline</span> Ohne Login fortfahren (Gast)
                    </button>
                    <p class="text-[11px] text-on-surface-variant text-center leading-snug">
                        Als Gast wird die Sammlung nur lokal im Browser gespeichert – nicht gesichert und nicht geräteübergreifend.
                    </p>
                </div>
            </template>
        </div>
    </div>

    <!-- Toast -->
    <div x-show="toast" x-transition class="fixed bottom-24 md:bottom-6 left-1/2 -translate-x-1/2 z-[70] px-4 py-3 rounded-xl bg-inverse-surface text-inverse-on-surface shadow-[0_12px_40px_rgba(0,0,0,0.12)] text-body-sm font-medium flex items-center gap-2">
        <span class="material-symbols-outlined text-[18px] text-tertiary-fixed">check_circle</span>
        <span x-text="toast"></span>
    </div>
</div>

<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>
</body>
</html>
