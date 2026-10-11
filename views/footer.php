    </main>

    <!-- Footer with Safe Area Support -->
    <footer class="bg-white border-t border-slate-200/80 py-6 mb-16 sm:mb-0 mt-auto" style="padding-bottom: calc(1.5rem + env(safe-area-inset-bottom, 0px));">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between text-xs text-slate-500 gap-3">
            <div class="flex items-center gap-2">
                <span class="font-bold text-slate-800">MapLeads Pro</span>
                <span class="text-slate-300">•</span>
                <span>B2B Web Agency Lead Engine &amp; WhatsApp Automation</span>
            </div>
            <div class="flex items-center space-x-3">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-emerald-50 border border-emerald-200 text-emerald-700">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    MySQL Active &bull; OneSender Ready
                </span>
            </div>
        </div>
    </footer>

    <!-- Mobile Native Bottom Navigation Bar (R-03 / antislop-layoutmobile) -->
    <nav class="sm:hidden fixed bottom-0 left-0 right-0 z-50 bg-white/95 backdrop-blur-xl border-t border-slate-200/80 shadow-lg" style="padding-bottom: env(safe-area-inset-bottom, 0px);" aria-label="Navigasi Utama Ponsel">
        <div class="grid grid-cols-3 h-16 items-center px-2">
            <a href="index.php" class="btn-press flex flex-col items-center justify-center py-1 rounded-xl transition <?= ($activeNav ?? '') === 'dashboard' ? 'text-emerald-700 font-bold' : 'text-slate-500 hover:text-slate-900' ?>" style="min-height: 48px;">
                <div class="p-1 rounded-lg <?= ($activeNav ?? '') === 'dashboard' ? 'bg-emerald-50' : '' ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                </div>
                <span class="text-[10px] mt-0.5 tracking-tight">Prospek</span>
            </a>
            <a href="search.php" class="btn-press flex flex-col items-center justify-center py-1 rounded-xl transition <?= ($activeNav ?? '') === 'search' ? 'text-emerald-700 font-bold' : 'text-slate-500 hover:text-slate-900' ?>" style="min-height: 48px;">
                <div class="p-1 rounded-lg <?= ($activeNav ?? '') === 'search' ? 'bg-emerald-50' : '' ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
                <span class="text-[10px] mt-0.5 tracking-tight">Scraper</span>
            </a>
            <a href="settings.php" class="btn-press flex flex-col items-center justify-center py-1 rounded-xl transition <?= ($activeNav ?? '') === 'settings' ? 'text-emerald-700 font-bold' : 'text-slate-500 hover:text-slate-900' ?>" style="min-height: 48px;">
                <div class="p-1 rounded-lg <?= ($activeNav ?? '') === 'settings' ? 'bg-emerald-50' : '' ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </div>
                <span class="text-[10px] mt-0.5 tracking-tight">Pengaturan</span>
            </a>
        </div>
    </nav>

</body>
</html>
