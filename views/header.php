<!DOCTYPE html>
<html lang="id" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="theme-color" content="#ffffff">
    <meta name="color-scheme" content="light">
    <title><?= $pageTitle ?? 'MapLeads Pro : GMaps Scraper & WhatsApp Outreach' ?></title>
    
    <!-- Google Fonts: Plus Jakarta Sans & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', '-apple-system', 'BlinkMacSystemFont', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
                    },
                    colors: {
                        brand: {
                            50: '#ecfdf5',
                            100: '#d1fae5',
                            200: '#a7f3d0',
                            400: '#34d399',
                            500: '#10b981',
                            600: '#059669',
                            700: '#047857',
                            800: '#065f46',
                            900: '#064e3b',
                        }
                    },
                    boxShadow: {
                        'surface': '0 1px 3px 0 rgba(15, 23, 42, 0.06), 0 1px 2px -1px rgba(15, 23, 42, 0.04)',
                        'elevated': '0 10px 25px -5px rgba(15, 23, 42, 0.08), 0 8px 10px -6px rgba(15, 23, 42, 0.04)',
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
            --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
        }

        html {
            -webkit-tap-highlight-color: transparent;
            -webkit-text-size-adjust: 100%;
            overscroll-behavior-y: none;
            overflow-x: hidden;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
            min-height: 100dvh;
            overflow-x: hidden;
        }

        /* Mobile Native Rule: Prevent iOS input zoom by guaranteeing min 16px on coarse pointer */
        @media (pointer: coarse) {
            input, textarea, select {
                font-size: 16px !important;
            }
        }

        /* Kill 300ms click delay & disable text selection on controls only */
        button, a, [role="button"], select, .btn-press {
            touch-action: manipulation;
            user-select: none;
            -webkit-user-select: none;
            -webkit-touch-callout: none;
        }

        /* High-contrast accessible focus indicator (R-32) */
        :focus-visible {
            outline: 2px solid #059669 !important;
            outline-offset: 2px !important;
        }

        /* Emil Kowalski Button & Press Principles: instant tactile feedback */
        .btn-press {
            transition: transform 160ms var(--ease-out), background-color 160ms ease, border-color 160ms ease, opacity 160ms ease, box-shadow 160ms ease;
            will-change: transform;
        }
        .btn-press:active {
            transform: scale(0.97) !important;
        }

        /* Hover states protected for fine pointer only (no sticky hover on touch) */
        @media (hover: hover) and (pointer: fine) {
            .interactive-row:hover {
                background-color: rgba(241, 245, 249, 0.75);
            }
            .interactive-card:hover {
                border-color: rgba(203, 213, 225, 0.9);
                transform: translateY(-1px);
                box-shadow: 0 4px 6px -1px rgba(15, 23, 42, 0.06), 0 2px 4px -2px rgba(15, 23, 42, 0.04);
            }
        }

        .interactive-card {
            transition: transform 200ms var(--ease-out), border-color 200ms var(--ease-out), box-shadow 200ms var(--ease-out);
            will-change: transform;
        }

        /* Tabular figures for stats and numbers */
        .num-tabular {
            font-variant-numeric: tabular-nums;
        }

        /* Modal transitions: Never scale(0), always scale(0.95) with opacity */
        @keyframes modalEnter {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        .animate-modal-in {
            transform-origin: center;
            animation: modalEnter 200ms var(--ease-out) forwards;
        }

        /* Safe area handling for header */
        header {
            padding-top: env(safe-area-inset-top, 0px);
        }

        /* Native touch momentum scroll & prevent horizontal scroll chaining */
        .overflow-x-auto {
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-x: contain;
        }

        /* Sonner-inspired Toast notification system with safe area insets */
        #toast-container {
            position: fixed;
            bottom: calc(76px + env(safe-area-inset-bottom, 0px));
            right: 16px;
            left: 16px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
            max-width: 400px;
            margin-left: auto;
        }
        @media (min-width: 640px) {
            #toast-container {
                bottom: calc(16px + env(safe-area-inset-bottom, 0px));
            }
        }
        .sonner-toast {
            pointer-events: auto;
            transform-origin: bottom center;
            animation: toastSlideIn 240ms var(--ease-out) forwards;
            transition: transform 200ms var(--ease-out), opacity 200ms var(--ease-out);
        }
        .sonner-toast.dismissing {
            opacity: 0 !important;
            transform: translateY(12px) scale(0.95) !important;
        }
        @keyframes toastSlideIn {
            from {
                opacity: 0;
                transform: translateY(16px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Scrollbar polish */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Accessibility: respect reduced motion preferences */
        @media (prefers-reduced-motion: reduce) {
            *, ::before, ::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
            .btn-press:active {
                transform: none !important;
            }
        }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-800 min-h-screen flex flex-col selection:bg-brand-100 selection:text-brand-800">

    <!-- Global Sonner Toast Container -->
    <div id="toast-container" aria-live="polite"></div>

    <!-- Header / Navbar with Frosted Glass -->
    <header class="bg-white/85 backdrop-blur-xl border-b border-slate-200/80 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                
                <!-- Brand Identity -->
                <div class="flex items-center space-x-2.5">
                    <a href="index.php" class="flex items-center space-x-2.5 group">
                        <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-xl bg-gradient-to-tr from-brand-600 via-brand-500 to-teal-500 p-[1px] shadow-sm transition-transform duration-200 group-hover:scale-105 btn-press">
                            <div class="w-full h-full bg-white rounded-[11px] flex items-center justify-center text-brand-600">
                                <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center gap-1.5">
                                <span class="font-bold text-sm sm:text-base tracking-tight text-slate-900 block leading-tight">MapLeads</span>
                                <span class="px-1.5 py-0.5 rounded-md text-[9px] sm:text-[10px] font-extrabold tracking-wider bg-brand-50 border border-brand-200 text-brand-700 uppercase">PRO</span>
                            </div>
                            <span class="hidden sm:block text-[11px] text-slate-500 font-medium">GMaps Scraper &amp; Outreach Engine</span>
                        </div>
                    </a>
                </div>

                <!-- Mobile Quick Indicator (sm:hidden) -->
                <div class="sm:hidden flex items-center">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-semibold bg-brand-50 border border-brand-200 text-brand-700">
                        <span class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse"></span>
                        Live
                    </span>
                </div>

                <!-- Navigation Links (Segmented Style for Desktop sm:flex) -->
                <nav class="hidden sm:flex items-center p-1 rounded-xl bg-slate-100 border border-slate-200/80 space-x-1">
                    <a href="index.php" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold btn-press transition <?= ($activeNav ?? '') === 'dashboard' ? 'bg-white text-slate-900 shadow-sm border border-slate-200 font-bold' : 'text-slate-600 hover:text-slate-900 hover:bg-white/60' ?>">
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            <span>Data Prospek</span>
                        </span>
                    </a>
                    <a href="search.php" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold btn-press transition <?= ($activeNav ?? '') === 'search' ? 'bg-white text-slate-900 shadow-sm border border-slate-200 font-bold' : 'text-slate-600 hover:text-slate-900 hover:bg-white/60' ?>">
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <span>Scrape GMaps</span>
                        </span>
                    </a>
                    <a href="settings.php" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold btn-press transition <?= ($activeNav ?? '') === 'settings' ? 'bg-white text-slate-900 shadow-sm border border-slate-200 font-bold' : 'text-slate-600 hover:text-slate-900 hover:bg-white/60' ?>">
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span>Pengaturan</span>
                        </span>
                    </a>
                </nav>

            </div>
        </div>
    </header>

    <!-- Main Container (Reserved bottom padding for mobile bottom bar) -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 pb-24 sm:pb-8">

    <script>
    // Lightweight Sonner-style Toast Utility adhering to Emil Kowalski's principles
    window.toast = {
        show({ message, type = 'default', duration = 3000 }) {
            const container = document.getElementById('toast-container');
            if (!container) return;

            const toast = document.createElement('div');
            toast.className = 'sonner-toast flex items-center gap-3 px-4 py-3 rounded-2xl border shadow-lg text-xs font-semibold';

            let icon = '';
            let styleClasses = '';

            if (type === 'success') {
                styleClasses = 'bg-white border-emerald-200 text-slate-800 shadow-md';
                icon = '<span class="flex h-5 w-5 rounded-full bg-emerald-100 text-emerald-700 items-center justify-center text-xs font-bold">✓</span>';
            } else if (type === 'error') {
                styleClasses = 'bg-white border-rose-200 text-slate-800 shadow-md';
                icon = '<span class="flex h-5 w-5 rounded-full bg-rose-100 text-rose-700 items-center justify-center text-xs font-bold">✕</span>';
            } else {
                styleClasses = 'bg-white border-slate-200 text-slate-800 shadow-md';
                icon = '<span class="flex h-5 w-5 rounded-full bg-slate-100 text-slate-600 items-center justify-center text-xs font-bold">ℹ</span>';
            }

            toast.className += ' ' + styleClasses;
            toast.innerHTML = `${icon} <div class="flex-1 leading-snug">${message}</div>`;
            container.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('dismissing');
                setTimeout(() => {
                    toast.remove();
                }, 200);
            }, duration);
        },
        success(msg, dur = 3000) { this.show({ message: msg, type: 'success', duration: dur }); },
        error(msg, dur = 3500) { this.show({ message: msg, type: 'error', duration: dur }); },
        info(msg, dur = 3000) { this.show({ message: msg, type: 'default', duration: dur }); }
    };
    </script>
