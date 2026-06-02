/**
 * VaultManager — state machine + API coordination.
 * Encapsulated in module scope to prevent console access to private state.
 * The encKey (CryptoKey) is non-extractable and never serialised.
 */
const VaultManager = (() => {
    // PRIVATE state — not accessible from console
    const privateState = {
        encKey:      null,  // CryptoKey — non-extractable, PRIVATE
        lockTimer:   null,
        LOCK_AFTER:  15 * 60 * 1000,  // 15 min idle
    };

    // PUBLIC state — read-only references only
    const publicState = {
        entries:     [],   // decrypted entry objects
        folders:     [],
        filtered:    [],
        currentView: 'all',
        searchQuery: '',
        userId:      null,
        isLocked:    true,
    };

    return {
        // Expose only publicState for inspection
        state: publicState,

        // All methods reference privateState.encKey (never exposed)
        async init(userId) {
            publicState.userId = userId;
            const resp = await fetch('api/salt.php');
            const data = await resp.json();
            if (!data.salt) {
                VaultUI.showSetupModal();
            } else {
                VaultUI.showUnlockModal(data.hint || '');
            }
            this._bindIdleReset();
        },

        // ── VAULT SETUP (first time) ──────────────────────────────────────────────
        async setupVault(masterPassword, hint) {
            const salt   = CryptoEngine.generateSalt();
            const key    = await CryptoEngine.deriveKey(masterPassword, salt);

            // Store a verification blob so we can detect wrong master password later
            const ver    = await CryptoEngine.encrypt(key, 'vault:ok');

            const resp   = await fetch('api/salt.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    salt,
                    hint,
                    verification_blob: ver.data,
                    verification_iv:   ver.iv
                })
            });
            const result = await resp.json();
            if (result.error) throw new Error(result.error);

            privateState.encKey   = key;
            publicState.isLocked = false;
            await this._loadFolders();
            publicState.entries  = [];
            this._resetLockTimer();
            VaultUI.renderAll();
        },

        // ── UNLOCK ────────────────────────────────────────────────────────────────
        async unlock(masterPassword) {
            const resp = await fetch('api/salt.php');
            const data = await resp.json();
            if (!data.salt) throw new Error('Vault not initialised');

            const key = await CryptoEngine.deriveKey(masterPassword, data.salt);

            // Verify password using the stored blob
            try {
                const test = await CryptoEngine.decrypt(key, data.verification_iv, data.verification_blob);
                if (test !== 'vault:ok') throw new Error('bad');
            } catch {
                throw new Error('Wrong master password');
            }

            privateState.encKey   = key;
            publicState.isLocked = false;
            await this._loadFolders();
            await this._loadEntries();
            this._resetLockTimer();
            VaultUI.renderAll();
        },

        lock() {
            privateState.encKey   = null;
            publicState.entries  = [];
            publicState.filtered = [];
            publicState.isLocked = true;
            clearTimeout(privateState.lockTimer);
            VaultUI.showLockedScreen();
        },

        // ── DATA LOADING ──────────────────────────────────────────────────────────
        async _loadEntries() {
            const resp = await fetch('api/vault.php');
            const data = await resp.json();
            publicState.entries = [];

            for (const row of (data.entries || [])) {
                try {
                    const plain = await CryptoEngine.decrypt(
                        privateState.encKey, row.iv, row.encrypted_data
                    );
                    publicState.entries.push({
                        ...plain,
                        uuid:       row.uuid,
                        folder_id:  row.folder_id,
                        type:       row.type,
                        favorite:   row.favorite == 1,
                        created_at: row.created_at,
                        updated_at: row.updated_at
                    });
                } catch { /* corrupted entry — skip */ }
            }
            this.applyFilter();
        },

        async _loadFolders() {
            const resp = await fetch('api/folders.php?action=list');
            const data = await resp.json();
            publicState.folders = data.folders || [];
        },

        // ── FILTER & SEARCH ───────────────────────────────────────────────────────
        applyFilter() {
            const { currentView, searchQuery, entries } = publicState;
            const q = searchQuery.trim().toLowerCase();

            let list = entries;
            if (currentView === 'favorites') list = list.filter(e => e.favorite);
            else if (['login','note','card','identity'].includes(currentView)) {
                list = list.filter(e => e.type === currentView);
            } else if (currentView.startsWith('folder:')) {
                const fid = parseInt(currentView.split(':')[1]);
                list = list.filter(e => e.folder_id == fid);
            }

            if (q) {
                list = list.filter(e =>
                    (e.title      || '').toLowerCase().includes(q) ||
                    (e.username   || '').toLowerCase().includes(q) ||
                    (e.url        || '').toLowerCase().includes(q) ||
                    (e.cardNumber || '').includes(q) ||
                    (e.email      || '').toLowerCase().includes(q)
                );
            }

            publicState.filtered = list;
            VaultUI.renderEntries(list);
        },

        setView(view) {
            publicState.currentView = view;
            this.applyFilter();
        },

        // ── CRUD ──────────────────────────────────────────────────────────────────
        async saveEntry(entryData) {
            if (!privateState.encKey) throw new Error('Vault is locked');
            if (!entryData) throw new Error('Entry data is required');
            
            const { iv, data } = await CryptoEngine.encrypt(privateState.encKey, entryData);
            const isNew = !entryData.uuid;
            const payload = {
                action:         isNew ? 'create' : 'update',
                uuid:           entryData.uuid || undefined,
                type:           entryData.type || 'login',
                folder_id:      entryData.folder_id || null,
                favorite:       entryData.favorite ? 1 : 0,
                encrypted_data: data,
                iv
            };
            
            const resp   = await fetch('api/vault.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            
            if (!resp.ok) {
                const error = await resp.json();
                throw new Error(error.error || `HTTP ${resp.status}`);
            }
            
            const result = await resp.json();
            if (result.error) throw new Error(result.error);
            
            // Update local state with returned UUID if new entry
            if (isNew && result.uuid) {
                entryData.uuid = result.uuid;
            }
            
            await this._loadEntries();
            return result;
        },

        async deleteEntry(uuid) {
            if (!uuid) throw new Error('UUID required for deletion');
            
            const resp = await fetch('api/vault.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', uuid })
            });
            
            if (!resp.ok) {
                const error = await resp.json();
                throw new Error(error.error || `HTTP ${resp.status}`);
            }
            
            await this._loadEntries();
        },

        async toggleFavorite(uuid) {
            const e = publicState.entries.find(x => x.uuid === uuid);
            if (!e) return;
            e.favorite = !e.favorite;
            await this.saveEntry({ ...e });
        },

        async getHistory(uuid) {
            const resp = await fetch('api/vault.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'history', uuid })
            });
            const data = await resp.json();
            const out  = [];
            for (const h of (data.history || [])) {
                try {
                    const plain = await CryptoEngine.decrypt(privateState.encKey, h.iv, h.encrypted_data);
                    out.push({ ...plain, changed_at: h.changed_at });
                } catch {}
            }
            return out;
        },

        async createFolder(name, color) {
            if (!name || name.trim().length === 0) throw new Error('Folder name is required');
            
            const resp = await fetch('api/folders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'create', name: name.trim(), color })
            });
            
            if (!resp.ok) {
                const error = await resp.json();
                throw new Error(error.error || `HTTP ${resp.status}`);
            }
            
            const result = await resp.json();
            if (result.error) throw new Error(result.error);
            
            await this._loadFolders();
            if (VaultUI && VaultUI.renderSidebar) VaultUI.renderSidebar();
            return result;
        },

        async deleteFolder(id) {
            if (!id) throw new Error('Folder ID is required for deletion');
            
            const resp = await fetch('api/folders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id })
            });
            
            if (!resp.ok) {
                const error = await resp.json();
                throw new Error(error.error || `HTTP ${resp.status}`);
            }
            
            const result = await resp.json();
            if (result.error) throw new Error(result.error);
            
            await this._loadFolders();
            await this._loadEntries();
            if (VaultUI && VaultUI.renderSidebar) VaultUI.renderSidebar();
        }

        async exportVault() {
            const resp = await fetch('api/vault.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'export' })
            });
            const blob = new Blob([await resp.text()], { type: 'application/json' });
            const a    = document.createElement('a');
            a.href     = URL.createObjectURL(blob);
            a.download = `vaultauth-export-${new Date().toISOString().slice(0,10)}.json`;
            a.click();
        },

        // ── IDLE LOCK ─────────────────────────────────────────────────────────────
        _resetLockTimer() {
            clearTimeout(privateState.lockTimer);
            privateState.lockTimer = setTimeout(() => this.lock(), privateState.LOCK_AFTER);
        },

        _bindIdleReset() {
            ['mousemove','keydown','click','touchstart'].forEach(ev =>
                document.addEventListener(ev, () => {
                    if (!publicState.isLocked) this._resetLockTimer();
                }, { passive: true })
            );
        }
    };
})();
