    <!-- Leads Table Section Partial -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-surface overflow-hidden">
        <?php if (empty($leads)): ?>
            <div class="px-4 py-16 text-center text-slate-500">
                <div class="max-w-xs mx-auto space-y-2">
                    <div class="text-3xl opacity-80">📭</div>
                    <div class="font-bold text-slate-800 text-sm">Tidak ada prospek ditemukan</div>
                    <div class="text-xs text-slate-500">Coba ubah kata kunci pencarian atau ganti filter status / grup.</div>
                    <?php if (!empty($searchQuery) || !empty($statusFilter) || $groupFilter !== ''): ?>
                        <button type="button" onclick="resetAllFilters()" class="btn-press inline-block mt-3 px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold border border-slate-200">
                            Reset Filter
                        </button>
                    <?php else: ?>
                        <a href="search.php" class="btn-press inline-block mt-3 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm">
                            + Mulai Cari di Google Maps
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Desktop & Tablet Table View (Hidden on mobile < 640px) -->
            <div class="hidden sm:block overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-[11px] uppercase tracking-wider font-semibold text-slate-500 border-b border-slate-200">
                        <tr>
                            <th class="px-3.5 py-3.5 w-10 text-center">
                                <input 
                                    type="checkbox" 
                                    id="selectAllCheckbox" 
                                    onchange="toggleSelectAll(this)"
                                    class="w-4 h-4 rounded bg-white text-emerald-600 focus:ring-0 border-slate-300 cursor-pointer" 
                                    title="Pilih Semua"
                                >
                            </th>
                            <th class="px-3.5 py-3.5">Nama Usaha &amp; Kategori</th>
                            <th class="px-3.5 py-3.5 hidden md:table-cell">Grup Prospek</th>
                            <th class="px-3.5 py-3.5 hidden lg:table-cell">Kota &amp; Alamat</th>
                            <th class="px-3.5 py-3.5">WhatsApp</th>
                            <th class="px-3.5 py-3.5 hidden xl:table-cell">Rating</th>
                            <th class="px-3.5 py-3.5">Status Prospek</th>
                            <th class="px-3.5 py-3.5 hidden lg:table-cell">Catatan</th>
                            <th class="px-3.5 py-3.5 text-right min-w-[170px]">WhatsApp Outreach</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($leads as $lead): ?>
                            <?php
                                $lastStep = (int)$lead['last_step_sent'];
                                $leadStatus = $lead['status'];
                                $directWaUrl = "https://api.whatsapp.com/send?phone=" . $lead['phone_wa'];
                            ?>
                            <tr class="interactive-row transition-colors" id="lead-row-<?= $lead['id'] ?>">
                                <!-- Checkbox Row -->
                                <td class="px-3.5 py-3.5 text-center">
                                    <input 
                                        type="checkbox" 
                                        class="lead-checkbox w-4 h-4 rounded bg-white text-emerald-600 focus:ring-0 border-slate-300 cursor-pointer" 
                                        value="<?= $lead['id'] ?>"
                                        onchange="handleLeadCheckboxChange(this)"
                                    >
                                </td>

                                <!-- Nama & Kategori -->
                                <td class="px-3.5 py-3.5">
                                    <div class="font-bold text-slate-900 text-xs leading-tight">
                                        <?= htmlspecialchars($lead['name']) ?>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                        <span class="inline-block px-2 py-0.5 rounded-md text-[10px] bg-slate-100 border border-slate-200 text-slate-600 font-medium">
                                            <?= htmlspecialchars($lead['category'] ?: 'Bisnis Lokal') ?>
                                        </span>
                                        <?php if (!empty($lead['city'])): ?>
                                            <span class="inline-block lg:hidden text-[10px] text-slate-500 font-medium">
                                                📍 <?= htmlspecialchars($lead['city']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($lead['last_incoming_message'])): ?>
                                        <div class="mt-2 p-2 rounded-xl bg-amber-50/90 border border-amber-200/90 text-slate-800 text-[11px] shadow-2xs max-w-sm">
                                            <div class="flex items-center justify-between gap-1 text-[10px] text-amber-800 font-semibold mb-0.5">
                                                <span class="flex items-center gap-1">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                                    <span>Balasan Klien</span>
                                                </span>
                                                <?php if (!empty($lead['last_incoming_at'])): ?>
                                                    <span class="text-[9px] text-slate-400 font-mono"><?= date('H:i', strtotime($lead['last_incoming_at'])) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="font-medium text-slate-900 italic line-clamp-2" title="<?= htmlspecialchars($lead['last_incoming_message']) ?>">
                                                "<?= htmlspecialchars($lead['last_incoming_message']) ?>"
                                            </div>
                                            <?php if ($lead['status'] === 'dihubungi'): ?>
                                                <div class="flex items-center gap-1.5 mt-1.5 pt-1.5 border-t border-amber-200/70">
                                                    <?php if ((int)$lead['last_step_sent'] === 1): ?>
                                                        <button 
                                                            type="button" 
                                                            onclick="openAddTriggerModal(<?= $lead['id'] ?>, <?= htmlspecialchars(json_encode($lead['last_incoming_message']), ENT_QUOTES, 'UTF-8') ?>)"
                                                            class="btn-press px-2.5 py-1 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-semibold text-[11px] inline-flex items-center gap-1 transition shadow-2xs"
                                                            title="Jadikan kata kunci trigger dan otomatis kirim Pesan 2"
                                                        >
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                                            <span>+ Jadikan Trigger</span>
                                                        </button>
                                                        <button 
                                                            type="button" 
                                                            onclick="sendStepMessage(<?= $lead['id'] ?>, 2)"
                                                            class="btn-press px-2.5 py-1 rounded-lg bg-white hover:bg-amber-50 border border-amber-300 text-amber-900 font-semibold text-[11px] inline-flex items-center gap-1 transition shadow-2xs"
                                                            title="Kirim Pesan 2 langsung manual"
                                                        >
                                                            <svg class="w-3 h-3 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                                            <span>Kirim Pesan 2</span>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-[10px] font-semibold text-emerald-700 bg-emerald-100/80 px-2 py-0.5 rounded-md inline-flex items-center gap-1">
                                                            <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                            <span>Pesan 2 Terkirim</span>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Grup Prospek Dropdown (hidden on small tablet) -->
                                <td class="px-3.5 py-3.5 hidden md:table-cell">
                                    <select 
                                        class="px-2.5 py-1 text-[11px] font-medium rounded-lg border border-slate-200 bg-slate-50 text-slate-700 focus:bg-white focus:border-emerald-600 max-w-[150px] truncate outline-none"
                                        data-id="<?= $lead['id'] ?>"
                                        onchange="updateLeadGroup(<?= $lead['id'] ?>, this.value)"
                                    >
                                        <option value="">-- Tanpa Grup --</option>
                                        <?php foreach ($availableGroups as $grp): ?>
                                            <option value="<?= $grp['id'] ?>" <?= (int)$lead['group_id'] === (int)$grp['id'] ? 'selected' : '' ?>>
                                                📁 <?= htmlspecialchars($grp['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <!-- Kota & Alamat (hidden on tablet) -->
                                <td class="px-3.5 py-3.5 hidden lg:table-cell">
                                    <div class="font-semibold text-slate-800 text-[11px]"><?= htmlspecialchars($lead['city'] ?: 'Kota -') ?></div>
                                    <div class="text-[11px] text-slate-500 truncate max-w-[160px]" title="<?= htmlspecialchars($lead['address']) ?>">
                                        <?= htmlspecialchars($lead['address']) ?>
                                    </div>
                                </td>

                                <!-- WhatsApp -->
                                <td class="px-3.5 py-3.5 whitespace-nowrap">
                                    <a href="<?= $directWaUrl ?>" target="_blank" class="font-mono text-xs font-semibold text-emerald-600 hover:text-emerald-700 hover:underline inline-flex items-center gap-1">
                                        <span>+<?= htmlspecialchars($lead['phone_wa']) ?></span>
                                    </a>
                                </td>

                                <!-- Rating (hidden below xl) -->
                                <td class="px-3.5 py-3.5 whitespace-nowrap hidden xl:table-cell">
                                    <span class="inline-flex items-center gap-1 font-bold text-amber-500 text-xs">
                                        ★ <?= number_format($lead['rating'], 1) ?>
                                        <span class="text-[10px] text-slate-400 font-normal font-mono">(<?= $lead['user_ratings_total'] ?>)</span>
                                    </span>
                                </td>

                                <!-- Status Dropdown -->
                                <td class="px-3.5 py-3.5 whitespace-nowrap">
                                    <select 
                                        class="px-2.5 py-1 text-[11px] font-semibold rounded-lg border border-slate-200 bg-slate-50 text-slate-800 focus:bg-white focus:border-emerald-600 status-selector outline-none status-select-<?= $lead['id'] ?>"
                                        data-id="<?= $lead['id'] ?>"
                                        id="status-select-<?= $lead['id'] ?>"
                                        onchange="updateLeadStatus(<?= $lead['id'] ?>, this.value)"
                                    >
                                        <option value="belum_dihubungi" <?= $leadStatus === 'belum_dihubungi' ? 'selected' : '' ?>>🟡 Belum Kontak</option>
                                        <option value="dihubungi" <?= $leadStatus === 'dihubungi' ? 'selected' : '' ?>>🔵 Dihubungi</option>
                                        <option value="deal" <?= $leadStatus === 'deal' ? 'selected' : '' ?>>🏆 Deal</option>
                                        <option value="batal" <?= $leadStatus === 'batal' ? 'selected' : '' ?>>❌ Batal</option>
                                    </select>
                                </td>

                                <!-- Catatan (hidden below lg) -->
                                <td class="px-3.5 py-3.5 hidden lg:table-cell">
                                    <div class="flex items-center gap-1.5">
                                        <span id="note-display-<?= $lead['id'] ?>" class="note-display-<?= $lead['id'] ?> text-slate-700 max-w-[130px] truncate block text-[11px]" title="<?= htmlspecialchars($lead['notes'] ?? '') ?>">
                                            <?= !empty($lead['notes']) ? htmlspecialchars($lead['notes']) : '<span class="text-slate-400 italic">Kosong</span>' ?>
                                        </span>
                                        <button 
                                            type="button" 
                                            onclick="openEditNoteModal(<?= $lead['id'] ?>, '<?= htmlspecialchars(addslashes($lead['name'])) ?>', '<?= htmlspecialchars(addslashes($lead['notes'] ?? '')) ?>', this)" 
                                            class="btn-press min-w-[32px] min-h-[32px] inline-flex items-center justify-center text-slate-400 hover:text-slate-800 p-1 rounded-md"
                                            title="Edit Catatan"
                                            aria-label="Edit Catatan"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                        </button>
                                    </div>
                                </td>

                                <!-- Kolom WhatsApp Outreach (Multi-Step Drip) -->
                                <td class="px-3.5 py-3.5 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5" id="outreach-actions-<?= $lead['id'] ?>">

                                        <?php if ($lastStep === 0): ?>
                                            <button 
                                                type="button" 
                                                onclick="sendStepMessage(<?= $lead['id'] ?>, 1)" 
                                                id="btn-step-<?= $lead['id'] ?>"
                                                class="btn-step-<?= $lead['id'] ?> btn-press inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold shadow-xs"
                                                title="Kirim Pesan 1 sekarang & otomatis ubah status ke Dihubungi"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                                <span>Pesan 1</span>
                                            </button>

                                        <?php elseif ($lastStep === 1): ?>
                                            <button 
                                                type="button" 
                                                onclick="sendStepMessage(<?= $lead['id'] ?>, 2)" 
                                                id="btn-step-<?= $lead['id'] ?>"
                                                class="btn-step-<?= $lead['id'] ?> btn-press inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold shadow-xs"
                                                title="Kirim Pesan 2 (Penawaran Solusi)"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                                <span>Pesan 2</span>
                                            </button>

                                        <?php elseif ($lastStep === 2): ?>
                                            <button 
                                                type="button" 
                                                onclick="sendStepMessage(<?= $lead['id'] ?>, 3)" 
                                                id="btn-step-<?= $lead['id'] ?>"
                                                class="btn-step-<?= $lead['id'] ?> btn-press inline-flex items-center gap-1.5 px-3 py-1 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-xs font-semibold shadow-xs"
                                                title="Kirim Pesan 3 (Portofolio & CTA)"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                                <span>Pesan 3</span>
                                            </button>

                                        <?php elseif ($lastStep === 3): ?>
                                            <?php if ($leadStatus === 'dihubungi' && !empty($lead['next_followup_at'])): ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-purple-50 border border-purple-200 text-purple-700 rounded-lg text-[11px] font-semibold" title="Jadwal otomatis Follow-up 1">
                                                    <svg class="w-3 h-3 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    <span>FU 1:</span> <span class="font-mono"><?= date('d M H:i', strtotime($lead['next_followup_at'])) ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-[11px] font-medium text-slate-500">Pesan 3 Selesai</span>
                                            <?php endif; ?>

                                        <?php elseif ($lastStep === 4): ?>
                                            <?php if ($leadStatus === 'dihubungi' && !empty($lead['next_followup_at'])): ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-lg text-[11px] font-semibold" title="Jadwal otomatis Follow-up 2">
                                                    <svg class="w-3 h-3 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    <span>FU 2:</span> <span class="font-mono"><?= date('d M H:i', strtotime($lead['next_followup_at'])) ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-[11px] font-medium text-slate-500">FU 1 Selesai</span>
                                            <?php endif; ?>

                                        <?php elseif ($lastStep >= 5): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg text-[11px] font-semibold">
                                                <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                <span>Drip Selesai</span>
                                            </span>
                                        <?php endif; ?>

                                        <!-- Direct WA Icon Button -->
                                        <a 
                                            href="<?= $directWaUrl ?>" 
                                            target="_blank" 
                                            class="btn-press w-7 h-7 inline-flex items-center justify-center text-slate-400 hover:text-emerald-600 rounded-lg hover:bg-emerald-50 transition"
                                            title="Buka Chat WhatsApp Web"
                                            aria-label="Buka Chat WhatsApp Web"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/></svg>
                                        </a>

                                        <!-- Delete Button -->
                                        <button 
                                            type="button" 
                                            onclick="deleteLead(<?= $lead['id'] ?>)" 
                                            class="btn-press w-7 h-7 inline-flex items-center justify-center text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition"
                                            title="Hapus Prospek"
                                            aria-label="Hapus Prospek"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>

                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View: Fully Fluid & Tailored for Phones (No Horizontal Scroll) -->
            <div class="sm:hidden divide-y divide-slate-100">
                <?php foreach ($leads as $lead): ?>
                    <?php
                        $lastStep = (int)$lead['last_step_sent'];
                        $leadStatus = $lead['status'];
                        $directWaUrl = "https://api.whatsapp.com/send?phone=" . $lead['phone_wa'];
                    ?>
                    <div class="p-3.5 space-y-3 bg-white" id="lead-card-<?= $lead['id'] ?>">
                        <!-- Card Header: Checkbox + Nama + Rating -->
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-start gap-2.5 min-w-0">
                                <input 
                                    type="checkbox" 
                                    class="lead-checkbox w-4 h-4 mt-0.5 rounded bg-white text-emerald-600 focus:ring-0 border-slate-300 cursor-pointer flex-shrink-0" 
                                    value="<?= $lead['id'] ?>"
                                    onchange="handleLeadCheckboxChange(this)"
                                >
                                <div class="min-w-0">
                                    <h3 class="font-bold text-slate-900 text-sm leading-snug break-words">
                                        <?= htmlspecialchars($lead['name']) ?>
                                    </h3>
                                    <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                        <span class="inline-block px-2 py-0.5 rounded-md text-[10px] bg-slate-100 border border-slate-200 text-slate-600 font-medium">
                                            <?= htmlspecialchars($lead['category'] ?: 'Bisnis Lokal') ?>
                                        </span>
                                        <?php if (!empty($lead['group_name'])): ?>
                                            <span class="inline-block px-2 py-0.5 rounded-md text-[10px] bg-purple-50 border border-purple-200 text-purple-700 font-medium truncate max-w-[140px]">
                                                📁 <?= htmlspecialchars($lead['group_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Rating Badge -->
                            <span class="inline-flex items-center gap-1 font-bold text-amber-600 text-xs flex-shrink-0 bg-amber-50 px-2 py-1 rounded-lg border border-amber-200">
                                ★ <?= number_format($lead['rating'], 1) ?>
                                <span class="text-[10px] text-slate-500 font-normal font-mono">(<?= $lead['user_ratings_total'] ?>)</span>
                            </span>
                        </div>

                        <!-- Meta Info: Alamat & WhatsApp (Clean Stacked) -->
                        <div class="bg-slate-50 rounded-xl p-2.5 text-xs space-y-1.5 border border-slate-200/60">
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] text-slate-500 font-medium">WhatsApp:</span>
                                <a href="<?= $directWaUrl ?>" target="_blank" class="font-mono font-semibold text-emerald-600 hover:text-emerald-700 inline-flex items-center gap-1">
                                    <span>+<?= htmlspecialchars($lead['phone_wa']) ?></span>
                                    <svg class="w-3.5 h-3.5 text-emerald-600" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/></svg>
                                </a>
                            </div>
                            <div class="flex items-start justify-between text-[11px] pt-1 border-t border-slate-200/60">
                                <span class="text-slate-500 flex-shrink-0 mr-2 font-medium">Alamat:</span>
                                <span class="text-slate-700 truncate text-right flex-1" title="<?= htmlspecialchars($lead['address']) ?>">
                                    <?= htmlspecialchars($lead['city'] ? $lead['city'] . ' - ' : '') ?><?= htmlspecialchars($lead['address']) ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($lead['last_incoming_message'])): ?>
                            <div class="p-2.5 rounded-xl bg-amber-50/90 border border-amber-200/90 text-slate-800 text-xs shadow-2xs">
                                <div class="flex items-center justify-between gap-1 text-[11px] text-amber-800 font-semibold mb-1">
                                    <span class="flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                        <span>Balasan Klien</span>
                                    </span>
                                    <?php if (!empty($lead['last_incoming_at'])): ?>
                                        <span class="text-[10px] text-slate-400 font-mono"><?= date('H:i', strtotime($lead['last_incoming_at'])) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="font-medium text-slate-900 italic text-[11px]" title="<?= htmlspecialchars($lead['last_incoming_message']) ?>">
                                    "<?= htmlspecialchars($lead['last_incoming_message']) ?>"
                                </div>
                                <?php if ($lead['status'] === 'dihubungi'): ?>
                                    <div class="flex items-center gap-2 mt-2 pt-2 border-t border-amber-200/70">
                                        <?php if ((int)$lead['last_step_sent'] === 1): ?>
                                            <button 
                                                type="button" 
                                                onclick="openAddTriggerModal(<?= $lead['id'] ?>, <?= htmlspecialchars(json_encode($lead['last_incoming_message']), ENT_QUOTES, 'UTF-8') ?>)"
                                                class="btn-press flex-1 py-1.5 px-2.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-semibold text-xs inline-flex items-center justify-center gap-1 transition shadow-2xs"
                                            >
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                                <span>+ Jadikan Trigger</span>
                                            </button>
                                            <button 
                                                type="button" 
                                                onclick="sendStepMessage(<?= $lead['id'] ?>, 2)"
                                                class="btn-press py-1.5 px-3 rounded-lg bg-white hover:bg-amber-50 border border-amber-300 text-amber-900 font-semibold text-xs inline-flex items-center justify-center gap-1 transition shadow-2xs"
                                            >
                                                <svg class="w-3 h-3 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                                <span>Kirim Pesan 2</span>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-[10px] font-semibold text-emerald-700 bg-emerald-100/80 px-2.5 py-1 rounded-md inline-flex items-center gap-1">
                                                <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                <span>Pesan 2 Terkirim</span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Status & Group Controls: Side by side on mobile -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] uppercase font-bold text-slate-500 mb-1">Status</label>
                                <select 
                                    class="status-select-<?= $lead['id'] ?> w-full px-2 py-1.5 text-xs font-semibold rounded-xl border border-slate-200 bg-slate-50 text-slate-800 focus:bg-white focus:border-emerald-600 outline-none"
                                    onchange="updateLeadStatus(<?= $lead['id'] ?>, this.value)"
                                >
                                    <option value="belum_dihubungi" <?= $leadStatus === 'belum_dihubungi' ? 'selected' : '' ?>>Belum Kontak</option>
                                    <option value="dihubungi" <?= $leadStatus === 'dihubungi' ? 'selected' : '' ?>>Dihubungi</option>
                                    <option value="deal" <?= $leadStatus === 'deal' ? 'selected' : '' ?>>Deal</option>
                                    <option value="batal" <?= $leadStatus === 'batal' ? 'selected' : '' ?>>Batal</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase font-bold text-slate-500 mb-1">Grup</label>
                                <select 
                                    class="w-full px-2 py-1.5 text-xs font-medium rounded-xl border border-slate-200 bg-slate-50 text-slate-700 focus:bg-white focus:border-emerald-600 truncate outline-none"
                                    onchange="updateLeadGroup(<?= $lead['id'] ?>, this.value)"
                                >
                                    <option value="">-- Tanpa Grup --</option>
                                    <?php foreach ($availableGroups as $grp): ?>
                                        <option value="<?= $grp['id'] ?>" <?= (int)$lead['group_id'] === (int)$grp['id'] ? 'selected' : '' ?>>
                                            📁 <?= htmlspecialchars($grp['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Card Action Buttons -->
                        <div class="flex items-center justify-between gap-2 pt-2 border-t border-slate-100">
                            <button 
                                type="button" 
                                onclick="openEditNoteModal(<?= $lead['id'] ?>, '<?= htmlspecialchars(addslashes($lead['name'])) ?>', '<?= htmlspecialchars(addslashes($lead['notes'] ?? '')) ?>', this)" 
                                class="btn-press inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-slate-50 border border-slate-200 text-slate-700 hover:text-slate-900 text-xs font-medium flex-1 max-w-[130px]"
                                title="Catatan"
                            >
                                <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                <span class="note-display-<?= $lead['id'] ?> truncate text-[11px]"><?= !empty($lead['notes']) ? htmlspecialchars($lead['notes']) : 'Catatan' ?></span>
                            </button>

                            <div class="flex items-center gap-1.5">
                                <?php if ($lastStep === 0): ?>
                                    <button 
                                        type="button" 
                                        onclick="sendStepMessage(<?= $lead['id'] ?>, 1)" 
                                        class="btn-step-<?= $lead['id'] ?> btn-press inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-semibold shadow-xs"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                        <span>Pesan 1</span>
                                    </button>
                                <?php elseif ($lastStep === 1): ?>
                                    <button 
                                        type="button" 
                                        onclick="sendStepMessage(<?= $lead['id'] ?>, 2)" 
                                        class="btn-step-<?= $lead['id'] ?> btn-press inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-semibold shadow-xs"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                        <span>Pesan 2</span>
                                    </button>
                                <?php elseif ($lastStep === 2): ?>
                                    <button 
                                        type="button" 
                                        onclick="sendStepMessage(<?= $lead['id'] ?>, 3)" 
                                        class="btn-step-<?= $lead['id'] ?> btn-press inline-flex items-center gap-1 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white rounded-xl text-xs font-semibold shadow-xs"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                        <span>Pesan 3</span>
                                    </button>
                                <?php elseif ($lastStep === 3): ?>
                                    <?php if ($leadStatus === 'dihubungi' && !empty($lead['next_followup_at'])): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-purple-50 border border-purple-200 text-purple-700 rounded-xl text-[11px] font-semibold">
                                            <svg class="w-3 h-3 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <span>FU 1:</span> <span class="font-mono"><?= date('d M H:i', strtotime($lead['next_followup_at'])) ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[11px] font-medium text-slate-500 px-2">Pesan 3 OK</span>
                                    <?php endif; ?>
                                <?php elseif ($lastStep === 4): ?>
                                    <?php if ($leadStatus === 'dihubungi' && !empty($lead['next_followup_at'])): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-xl text-[11px] font-semibold">
                                            <svg class="w-3 h-3 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <span>FU 2:</span> <span class="font-mono"><?= date('d M H:i', strtotime($lead['next_followup_at'])) ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[11px] font-medium text-slate-500 px-2">FU 1 OK</span>
                                    <?php endif; ?>
                                <?php elseif ($lastStep >= 5): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl text-[11px] font-semibold">
                                        <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        <span>Drip Selesai</span>
                                    </span>
                                <?php endif; ?>

                                <!-- Direct WA -->
                                <a 
                                    href="<?= $directWaUrl ?>" 
                                    target="_blank" 
                                    class="btn-press w-9 h-9 inline-flex items-center justify-center text-slate-400 hover:text-emerald-600 rounded-xl bg-slate-50 border border-slate-200 hover:bg-emerald-50 transition"
                                    title="Buka Chat WhatsApp"
                                    aria-label="Buka Chat WhatsApp"
                                >
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/></svg>
                                </a>

                                <!-- Delete -->
                                <button 
                                    type="button" 
                                    onclick="deleteLead(<?= $lead['id'] ?>)" 
                                    class="btn-press w-9 h-9 inline-flex items-center justify-center text-slate-400 hover:text-rose-600 rounded-xl bg-slate-50 border border-slate-200 hover:bg-rose-50 transition"
                                    title="Hapus Prospek"
                                    aria-label="Hapus Prospek"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="p-3.5 sm:p-4 border-t border-slate-200 bg-slate-50/60 flex flex-col sm:flex-row items-center justify-between text-xs text-slate-500 gap-3">
                <span>Halaman <strong class="text-slate-800"><?= $page ?></strong> dari <strong class="text-slate-800"><?= $totalPages ?></strong> (Total <?= $totalFiltered ?> data)</span>
                <div class="flex flex-wrap gap-1.5 justify-center">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a 
                            href="index.php?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>&group_id=<?= urlencode($groupFilter) ?>&q=<?= urlencode($searchQuery) ?>" 
                            data-page="<?= $i ?>"
                            class="pagination-link btn-press px-2.5 py-1 rounded-lg border text-xs font-mono <?= $i === $page ? 'bg-emerald-600 text-white border-emerald-600 font-bold shadow-sm' : 'border-slate-200 text-slate-700 bg-white hover:bg-slate-100' ?>"
                        >
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
