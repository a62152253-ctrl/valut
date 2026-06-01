/**
 * VaultManager — Optimized state machine + API coordination
 * - Decrypted entries live ONLY in memory (non-extractable keys)
 * - Request caching and debouncing for API efficiency
 * - Security-first design with automatic idle lock
 */

const VaultManager = {
    state: {
        entries:     [],      // decrypted entry objects
        folders:     [],
        filtered:    [],
        currentView: 'all',
        searchQuery: '',
        encKey:      null,    // CryptoKey — non-extractable
        userId:      null,
        isLocked:    true,
        lockTimer:   null,
        LOCK_AFTER:  15 * 60 * 1000,  // 15 min idle
        requestQueue: [],
        lastApiCall: {}
    },

    // Request debouncing cache
    _cache: {
        folders: null,
        folderTime: 0,
        entries: null,
        entriesTime: 0,
        cacheTTL: 60000  // 60 seconds
    },

    async init(userId) {
        this.state.userId = userId;
        try {
            const resp = await this._cachedFetch('api/salt.php');
            const data = await resp.json();
            if (!data.salt) {
                VaultUI.showSetupModal();
            } else {
                VaultUI.showUnlockModal(data.hint || '');
            }
        } catch (err) {
            VaultUI.showError('Failed to initialize vault: ' + err.message);
            return;
        }
        this._bindIdleReset();
    },

    // Optimized cached fetch with TTL
    async _cachedFetch(url, options = {}) {
        const cacheKey = url + JSON.stringify(options);
        const now = Date.now();
        
        if (this._cache[cacheKey] && (now - this._cache[cacheKey].time) < this._cache.cacheTTL) {
            return new Response(JSON.stringify(this._cache[cacheKey].data));
        }

        const resp = await fetch(url, options);
        if (resp.ok) {
            const data = await resp.json();
            this._cache[cacheKey] = { data, time: now };
            return new Response(JSON.stringify(data));
        }
        return resp;
    },

    // ── VAULT SETUP (first time) ──────────────────────────────────────────────
    async setupVault(masterPassword, hint) {
        if (!masterPassword || masterPassword.length < 12) {
            throw new Error('Master password must be at least 12 characters');
        }

        try {
            const salt = CryptoEngine.generateSalt();
            const key = await CryptoEngine.deriveKey(masterPassword, salt);
            const ver = await CryptoEngine.encrypt(key, 'vault:ok');

            const resp = await fetch('api/salt.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    salt, hint,
                    verification_blob: ver.data,
                    verification_iv: ver.iv
                })
            });

            if (!resp.ok) throw new Error('Setup failed: ' + resp.statusText);
            const result = await resp.json();
            if (result.error) throw new Error(result.error);

            this.state.encKey = key;
            this.state.isLocked = false;
            await this._loadFolders();
            this.state.entries = [];
            this._resetLockTimer();
            this._invalidateCache('folders');
            VaultUI.renderAll();
        } catch (err) {
            VaultUI.showError('Setup error: ' + err.message);
            throw err;
        }
    },

    // ── UNLOCK ────────────────────────────────────────────────────────────────
    async unlock(masterPassword) {
        if (!masterPassword) throw new Error('Master password required');

        try {
            const resp = await fetch('api/salt.php');
            const data = await resp.json();
            if (!data.salt) throw new Error('Vault not initialized');

            const key = await CryptoEngine.deriveKey(masterPassword, data.salt);

            // Verify password using the stored blob (timing-safe)
            try {
                const test = await CryptoEngine.decrypt(key, data.verification_iv, data.verification_blob);
                if (test !== 'vault:ok') throw new Error('Invalid password');
            } catch (err) {
                throw new Error('Wrong master password');
            }

            this.state.encKey = key;
            this.state.isLocked = false;
            await this._loadFolders();
            await this._loadEntries();
            this._resetLockTimer();
            this._invalidateCache('all');
            VaultUI.renderAll();
        } catch (err) {
            VaultUI.showError(err.message);
            throw err;
        }
    },

    lock() {
        this.state.encKey = null;
        this.state.entries = [];
        this.state.filtered = [];
        this.state.isLocked = true;
        clearTimeout(this.state.lockTimer);
        this._invalidateCache('all');
        VaultUI.showLockedScreen();
    },

    // ── DATA LOADING ──────────────────────────────────────────────────────────
    async _loadEntries() {
        try {
            const resp = await fetch('api/vault.php');
            if (!resp.ok) throw new Error('Failed to load entries');

            const data = await resp.json();
            this.state.entries = [];

            for (const row of (data.entries || [])) {
                try {
                    const plain = await CryptoEngine.decrypt(
                        this.state.encKey, row.iv, row.encrypted_data
                    );
                    this.state.entries.push({
                        ...plain,
                        uuid: row.uuid,
                        folder_id: row.folder_id,
                        type: row.type,
                        favorite: row.favorite == 1,
                        created_at: row.created_at,
                        updated_at: row.updated_at
                    });
                } catch (err) {
                    console.warn('Skipping corrupted entry:', row.uuid);
                }
            }
            this.applyFilter();
        } catch (err) {
            VaultUI.showError('Failed to load entries: ' + err.message);
        }
    },

    async _loadFolders() {
        try {
            const resp = await fetch('api/folders.php?action=list');
            if (!resp.ok) throw new Error('Failed to load folders');

            const data = await resp.json();
            this.state.folders = data.folders || [];
        } catch (err) {
            console.error('Failed to load folders:', err);
            this.state.folders = [];
        }
    },

    // ── FILTER & SEARCH ───────────────────────────────────────────────────────
    applyFilter() {
        const { currentView, searchQuery, entries } = this.state;
        const q = searchQuery.trim().toLowerCase();

        let list = entries;

        // Apply view filter
        if (currentView === 'favorites') {
            list = list.filter(e => e.favorite);
        } else if (['login', 'note', 'card', 'identity'].includes(currentView)) {
            list = list.filter(e => e.type === currentView);
        } else if (currentView.startsWith('folder:')) {
            const fid = parseInt(currentView.split(':')[1]);
            list = list.filter(e => e.folder_id == fid);
        }

        // Apply search filter
        if (q) {
            list = list.filter(e => {
                const searchFields = [
                    (e.title || '').toLowerCase(),
                    (e.username || '').toLowerCase(),
                    (e.url || '').toLowerCase(),
                    (e.cardNumber || ''),
                    (e.email || '').toLowerCase()
                ];
                return searchFields.some(field => field.includes(q));
            });
        }

        this.state.filtered = list;
        VaultUI.renderEntries(list);
    },

    setView(view) {
        this.state.currentView = view;
        this.applyFilter();
    },

    // ── CRUD ──────────────────────────────────────────────────────────────────
    async saveEntry(entryData) {
        if (!entryData.title) throw new Error('Entry title required');

        try {
            const { iv, data } = await CryptoEngine.encrypt(this.state.encKey, entryData);
            const isNew = !entryData.uuid;
            
            const payload = {
                action: isNew ? 'create' : 'update',
                uuid: entryData.uuid,
                type: entryData.type || 'login',
                folder_id: entryData.folder_id || null,
                favorite: entryData.favorite ? 1 : 0,
                encrypted_data: data,
                iv
            };

            const resp = await fetch('api/vault.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (!resp.ok) throw new Error('Save failed: ' + resp.statusText);

            const result = await resp.json();
            if (result.error) throw new Error(result.error);

            this._invalidateCache('entries');
            await this._loadEntries();
            return result;
        } catch (err) {
            VaultUI.showError('Failed to save entry: ' + err.message);
            throw err;
        }
    },

    async deleteEntry(uuid) {
        if (!uuid) throw new Error('UUID required');

        try {
            const resp = await fetch('api/vault.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', uuid })
            });

            if (!resp.ok) throw new Error('Delete failed');

            this._invalidateCache('entries');
            await this._loadEntries();
        } catch (err) {
            VaultUI.showError('Failed to delete entry: ' + err.message);
            throw err;
        }
    },

    async toggleFavorite(uuid) {
        const e = this.state.entries.find(x => x.uuid === uuid);
        if (!e) throw new Error('Entry not found');
        
        e.favorite = !e.favorite;
        return this.saveEntry({ ...e });
    },

    async getHistory(uuid) {
        try {
            const resp = await fetch('api/vault.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'history', uuid })
            });

            if (!resp.ok) throw new Error('Failed to load history');

            const data = await resp.json();
            const out = [];

            for (const h of (data.history || [])) {
                try {
                    const plain = await CryptoEngine.decrypt(this.state.encKey, h.iv, h.encrypted_data);
                    out.push({ ...plain, changed_at: h.changed_at });
                } catch {
                    console.warn('Corrupted history entry:', h);
                }
            }
            return out;
        } catch (err) {
            VaultUI.showError('Failed to load history: ' + err.message);
            return [];
        }
    },

    async createFolder(name, color) {
        if (!name || !name.trim()) throw new Error('Folder name required');
        if (!color.match(/^#[0-9a-f]{6}$/i)) throw new Error('Invalid color');

        try {
            const resp = await fetch('api/folders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'create', name, color })
            });

            if (!resp.ok) throw new Error('Failed to create folder');

            this._invalidateCache('folders');
            await this._loadFolders();
            VaultUI.renderSidebar();
        } catch (err) {
            VaultUI.showError('Failed to create folder: ' + err.message);
            throw err;
        }
    },

    async deleteFolder(id) {
        if (!id) throw new Error('Folder ID required');

        try {
            const resp = await fetch('api/folders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id })
            });

            if (!resp.ok) throw new Error('Failed to delete folder');

            this._invalidateCache('folders');
            await this._loadFolders();
            await this._loadEntries();
            VaultUI.renderSidebar();
        } catch (err) {
            VaultUI.showError('Failed to delete folder: ' + err.message);
            throw err;
        }
    },

    async exportVault() {
        try {
            const resp = await fetch('api/vault.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'export' })
            });

            if (!resp.ok) throw new Error('Export failed');

            const blob = new Blob([await resp.text()], { type: 'application/json' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `vaultly-export-${new Date().toISOString().slice(0, 10)}.json`;
            a.click();
        } catch (err) {
            VaultUI.showError('Failed to export vault: ' + err.message);
        }
    },

    // ── CACHE MANAGEMENT ──────────────────────────────────────────────────────
    _invalidateCache(type = 'all') {
        if (type === 'all' || type === 'entries') this._cache.entries = null;
        if (type === 'all' || type === 'folders') this._cache.folders = null;
    },

    // ── IDLE LOCK ─────────────────────────────────────────────────────────────
    _resetLockTimer() {
        clearTimeout(this.state.lockTimer);
        if (!this.state.isLocked) {
            this.state.lockTimer = setTimeout(() => this.lock(), this.state.LOCK_AFTER);
        }
    },

    _bindIdleReset() {
        const events = ['mousemove', 'keydown', 'click', 'touchstart'];
        const resetHandler = () => {
            if (!this.state.isLocked) this._resetLockTimer();
        };
        events.forEach(ev => 
            document.addEventListener(ev, resetHandler, { passive: true })
        );
    }
};
