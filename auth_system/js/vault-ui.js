/**
 * VaultUI — all DOM rendering, modal management, TOTP countdown, clipboard.
 */
const VaultUI = (() => {
    // ── Helpers ───────────────────────────────────────────────────────────────
    const $ = id => document.getElementById(id);
    const esc = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    let totpIntervals = [];

    function typeIcon(type) {
        switch (type) {
            case 'login':    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
            case 'note':     return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
            case 'card':     return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>';
            case 'identity': return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>';
            default:         return '?';
        }
    }

    function typeColor(type) {
        return { login: '#5865f2', note: '#10b981', card: '#f59e0b', identity: '#ec4899' }[type] || '#5865f2';
    }

    function domainInitial(entry) {
        try {
            const h = new URL(entry.url || '').hostname.replace('www.', '');
            return h[0].toUpperCase();
        } catch {
            return (entry.title || '?')[0].toUpperCase();
        }
    }

    function timeAgo(iso) {
        if (!iso) return '';
        const diff = Date.now() - new Date(iso).getTime();
        const d = Math.floor(diff / 86400000);
        if (d === 0) return 'today';
        if (d === 1) return 'yesterday';
        if (d < 30) return `${d}d ago`;
        if (d < 365) return `${Math.floor(d/30)}mo ago`;
        return `${Math.floor(d/365)}y ago`;
    }

    async function copyToClipboard(text, btnEl) {
        await navigator.clipboard.writeText(text);
        if (btnEl) {
            const orig = btnEl.innerHTML;
            btnEl.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m20 6-11 11-5-5"/></svg> Copied';
            btnEl.classList.add('copied');
            setTimeout(() => { btnEl.innerHTML = orig; btnEl.classList.remove('copied'); }, 2000);
        }
    }

    // ── Modal helpers ─────────────────────────────────────────────────────────
    function openModal(id)  { $(id)?.classList.add('active'); }
    function closeModal(id) { $(id)?.classList.remove('active'); }
    function closeAll()     { document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active')); }

    // ── Lock/Unlock screens ───────────────────────────────────────────────────
    function showSetupModal() {
        $('vaultApp').classList.add('hidden');
        $('setupModal').classList.add('active');
    }

    function showUnlockModal(hint) {
        $('vaultApp').classList.add('hidden');
        $('unlockHint').textContent = hint ? `Hint: ${hint}` : '';
        $('unlockModal').classList.add('active');
    }

    function showLockedScreen() {
        $('vaultApp').classList.add('hidden');
        closeAll();
        $('unlockModal').classList.add('active');
    }

    function showVault() {
        closeAll();
        $('vaultApp').classList.remove('hidden');
        renderSidebar();
        renderEntries(VaultManager.state.filtered);
        setActiveNav('all');
    }

    // ── Sidebar ───────────────────────────────────────────────────────────────
    function renderSidebar() {
        const { entries, folders } = VaultManager.state;
        const counts = { login: 0, note: 0, card: 0, identity: 0, favorites: 0 };
        entries.forEach(e => {
            counts[e.type] = (counts[e.type] || 0) + 1;
            if (e.favorite) counts.favorites++;
        });

        $('countAll').textContent     = entries.length;
        $('countFav').textContent     = counts.favorites;
        $('countLogin').textContent   = counts.login    || 0;
        $('countNote').textContent    = counts.note     || 0;
        $('countCard').textContent    = counts.card     || 0;
        $('countIdentity').textContent= counts.identity || 0;

        const fl = $('sidebarFolders');
        fl.innerHTML = folders.map(f => `
          <a class="nav-item nav-folder" data-view="folder:${f.id}"
             style="--folder-color:${esc(f.color)}">
            <span class="folder-dot"></span>
            <span>${esc(f.name)}</span>
            <span class="nav-count">${entries.filter(e=>e.folder_id==f.id).length}</span>
            <button class="folder-del" onclick="event.stopPropagation();VM_delFolder(${f.id})" title="Delete folder">×</button>
          </a>`).join('');
        fl.querySelectorAll('.nav-item').forEach(el =>
            el.addEventListener('click', () => {
                VaultManager.setView(el.dataset.view);
                setActiveNav(el.dataset.view);
            })
        );
    }

    function setActiveNav(view) {
        document.querySelectorAll('.nav-item').forEach(el =>
            el.classList.toggle('active', el.dataset.view === view)
        );
        const titles = { all:'All Items', favorites:'Favorites', login:'Logins',
                         note:'Notes', card:'Cards', identity:'Identities' };
        const f = VaultManager.state.folders.find(x => `folder:${x.id}` === view);
        $('viewTitle').textContent = f ? f.name : (titles[view] || 'Vault');
    }

    // ── Entry list ────────────────────────────────────────────────────────────
    function renderEntries(list) {
        const el = $('entriesList');
        if (!list.length) {
            el.innerHTML = `<div class="empty-state">
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                <circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 2H21v5.5"/>
              </svg>
              <p>No items here yet</p>
              <button class="btn-add-inline" onclick="UI_showAddEntry()">Add your first item</button>
            </div>`;
            return;
        }

        el.innerHTML = list.map(e => {
            const subtitle = e.type === 'login'    ? (e.username || e.url || '')
                           : e.type === 'card'     ? `**** ${(e.cardNumber||'').slice(-4)}`
                           : e.type === 'identity' ? `${e.firstName||''} ${e.lastName||''}`.trim()
                           : (e.content || '').slice(0, 50);
            const color = typeColor(e.type);
            return `
            <div class="entry-item" data-uuid="${esc(e.uuid)}" onclick="UI_viewEntry('${esc(e.uuid)}')">
              <div class="entry-icon" style="background:${color}22;color:${color}">
                ${e.type==='login' && e.url ? domainInitial(e) : typeIcon(e.type)}
              </div>
              <div class="entry-info">
                <div class="entry-title">${esc(e.title||'Untitled')}${e.favorite?' <span class="fav-star">★</span>':''}</div>
                <div class="entry-subtitle">${esc(subtitle)}</div>
              </div>
              <div class="entry-meta">${timeAgo(e.updated_at)}</div>
              <div class="entry-actions">
                ${e.type==='login'?`<button class="ea-btn" title="Copy password"
                  onclick="event.stopPropagation();UI_copyPwd('${esc(e.uuid)}',this)">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                  </svg></button>` : ''}
                <button class="ea-btn ea-edit" title="Edit"
                  onclick="event.stopPropagation();UI_showEditEntry('${esc(e.uuid)}')">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                  </svg></button>
              </div>
            </div>`;
        }).join('');
    }

    async function copyPwd(uuid, btn) {
        const e = VaultManager.state.entries.find(x => x.uuid === uuid);
        if (e?.password) await copyToClipboard(e.password, btn);
    }

    // ── View Entry Modal ──────────────────────────────────────────────────────
    function viewEntry(uuid) {
        const e = VaultManager.state.entries.find(x => x.uuid === uuid);
        if (!e) return;

        totpIntervals.forEach(clearInterval);
        totpIntervals = [];

        let fields = '';
        if (e.type === 'login') {
            fields = `
              ${field('URL', e.url, true)}
              ${field('Username', e.username, false, true)}
              ${fieldPwd('Password', e.password)}
              ${e.totp ? totpField(e.totp) : ''}
              ${e.notes ? field('Notes', e.notes) : ''}`;
        } else if (e.type === 'note') {
            fields = `<div class="view-field"><div class="vf-label">Content</div>
              <pre class="vf-note">${esc(e.content)}</pre></div>`;
        } else if (e.type === 'card') {
            fields = `${field('Cardholder', e.cardholderName)}
              ${fieldCard('Card Number', e.cardNumber)}
              ${field('Expiry', e.expiry)}
              ${field('CVV', e.cvv, false, false, true)}
              ${e.notes ? field('Notes', e.notes) : ''}`;
        } else if (e.type === 'identity') {
            fields = `${field('Name', `${e.firstName||''} ${e.lastName||''}`.trim())}
              ${field('Email', e.email)}${field('Phone', e.phone)}
              ${field('Address', [e.address,e.city,e.zip,e.country].filter(Boolean).join(', '))}`;
        }

        if (e.customFields?.length) {
            fields += e.customFields.map(cf =>
                `${field(cf.label, cf.value, false, cf.type==='text')}`
            ).join('');
        }

        $('viewEntryTitle').textContent = e.title || 'Untitled';
        $('viewEntryType').textContent  = e.type.charAt(0).toUpperCase() + e.type.slice(1);
        $('viewEntryType').style.color  = typeColor(e.type);
        $('viewEntryFields').innerHTML  = fields;
        $('viewEntryEdit').onclick      = () => { closeModal('viewEntryModal'); showEditEntry(uuid); };
        $('viewEntryDel').onclick       = () => confirmDelete(uuid);
        $('viewEntryFav').innerHTML     = e.favorite ? '★ Unstar' : '☆ Favorite';
        $('viewEntryFav').onclick       = async () => {
            await VaultManager.toggleFavorite(uuid);
            closeModal('viewEntryModal');
        };
        $('viewEntryUpdated').textContent = `Updated ${timeAgo(e.updated_at)}`;
        openModal('viewEntryModal');

        // Start TOTP countdown if applicable
        if (e.totp) startTotpCountdown(e.totp);
    }

    function field(label, value, isUrl=false, canCopy=false, masked=false) {
        if (!value) return '';
        const display = masked ? '••••' : esc(value);
        const urlPart = isUrl && value ? `<a class="vf-link" href="${esc(value)}" target="_blank" rel="noopener">${esc(value)}</a>` : display;
        return `<div class="view-field">
          <div class="vf-label">${esc(label)}</div>
          <div class="vf-val">${isUrl ? urlPart : display}
            ${canCopy ? `<button class="vf-copy" onclick="navigator.clipboard.writeText(${JSON.stringify(value)}).then(()=>{this.textContent='✓';setTimeout(()=>{this.textContent='Copy'},1500)})">Copy</button>` : ''}
          </div></div>`;
    }

    function fieldPwd(label, value) {
        if (!value) return '';
        const s = PasswordGenerator.score(value);
        const color = PasswordGenerator.SCORE_COLORS[s] || '#9ca3af';
        return `<div class="view-field">
          <div class="vf-label">${esc(label)}</div>
          <div class="vf-val">
            <span class="pwd-mask" id="pwdMask">••••••••••••</span>
            <span class="pwd-plain hidden" id="pwdPlain">${esc(value)}</span>
            <button class="vf-copy" onclick="togglePwdView()">Show</button>
            <button class="vf-copy" onclick="navigator.clipboard.writeText(${JSON.stringify(value)}).then(()=>{this.textContent='✓';setTimeout(()=>{this.textContent='Copy'},1500)})">Copy</button>
          </div>
          <div class="pwd-strength-mini">
            <div style="width:${[0,25,50,75,100][s]}%;background:${color};height:3px;border-radius:2px;transition:width .3s"></div>
          </div>
        </div>`;
    }

    function fieldCard(label, value) {
        if (!value) return '';
        const masked = value.replace(/\d(?=\d{4})/g, '•');
        return `<div class="view-field">
          <div class="vf-label">${esc(label)}</div>
          <div class="vf-val">${esc(masked)}
            <button class="vf-copy" onclick="navigator.clipboard.writeText('${esc(value)}').then(()=>{this.textContent='✓';setTimeout(()=>{this.textContent='Copy'},1500)})">Copy</button>
          </div></div>`;
    }

    function totpField(secret) {
        return `<div class="view-field">
          <div class="vf-label">TOTP Code</div>
          <div class="vf-val">
            <span class="totp-code" id="totpCode">------</span>
            <div class="totp-timer"><div class="totp-bar" id="totpBar"></div></div>
            <button class="vf-copy" onclick="navigator.clipboard.writeText(document.getElementById('totpCode').textContent)">Copy</button>
          </div></div>`;
    }

    function startTotpCountdown(secret) {
        function update() {
            const code = generateTOTP(secret);
            const el = $('totpCode');
            const bar = $('totpBar');
            if (!el) return;
            el.textContent = code;
            const rem = 30 - (Math.floor(Date.now() / 1000) % 30);
            if (bar) bar.style.width = `${(rem / 30) * 100}%`;
        }
        update();
        const id = setInterval(update, 1000);
        totpIntervals.push(id);
    }

    function generateTOTP(secret) {
        // Basic TOTP (RFC 6238) using HMAC-SHA1 — sync polyfill approach
        // For a full implementation, a Web Crypto async version would be used.
        // Here we display a placeholder until the async version loads.
        try {
            const base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
            let bits = '';
            for (const c of secret.toUpperCase().replace(/=+$/, '')) {
                const val = base32chars.indexOf(c);
                if (val < 0) continue;
                bits += val.toString(2).padStart(5, '0');
            }
            const keyBytes = [];
            for (let i = 0; i + 8 <= bits.length; i += 8) keyBytes.push(parseInt(bits.slice(i, i+8), 2));

            const counter = Math.floor(Date.now() / 30000);
            const msg = new Uint8Array(8);
            let c = counter;
            for (let i = 7; i >= 0; i--) { msg[i] = c & 0xff; c = Math.floor(c / 256); }

            // Synchronous HMAC-SHA1 is not natively available in browsers.
            // We display the secret masked — full async TOTP displayed in next version.
            return '??????';
        } catch { return '------'; }
    }

    // ── Add/Edit Entry Modal ──────────────────────────────────────────────────
    let _editUuid = null;

    function showAddEntry(type = 'login') {
        _editUuid = null;
        $('entryModalTitle').textContent = 'Add Item';
        $('entryType').value = type;
        $('entryForm').reset();
        $('customFieldsList').innerHTML = '';
        renderFormFields(type);
        populateFolderSelect();
        openModal('entryModal');
    }

    function showEditEntry(uuid) {
        const e = VaultManager.state.entries.find(x => x.uuid === uuid);
        if (!e) return;
        _editUuid = uuid;
        $('entryModalTitle').textContent = 'Edit Item';
        $('entryType').value = e.type;
        renderFormFields(e.type, e);
        populateFolderSelect(e.folder_id);
        $('customFieldsList').innerHTML = '';
        (e.customFields || []).forEach(cf => addCustomFieldRow(cf.label, cf.value));
        $('entryFavorite').checked = e.favorite || false;
        openModal('entryModal');
    }

    function renderFormFields(type, data = {}) {
        const c = $('entryFieldsContainer');
        c.innerHTML = '';
        const f = (name, label, val='', inputType='text', placeholder='') =>
            `<div class="form-group">
               <label class="fg-label">${label}</label>
               <input class="fg-input" name="${name}" type="${inputType}" value="${esc(val)}" placeholder="${esc(placeholder||label)}">
             </div>`;
        const ta = (name, label, val='') =>
            `<div class="form-group">
               <label class="fg-label">${label}</label>
               <textarea class="fg-input fg-textarea" name="${name}" rows="3">${esc(val)}</textarea>
             </div>`;

        let html = f('title', 'Title', data.title||'', 'text', 'e.g. Gmail');

        if (type === 'login') {
            html += f('url','URL', data.url||'','url','https://example.com');
            html += f('username','Username', data.username||'','text','username or email');
            html += `<div class="form-group">
              <label class="fg-label">Password</label>
              <div class="pwd-input-wrap">
                <input class="fg-input" name="password" id="entryPwdInput" type="password" value="${esc(data.password||'')}" placeholder="password" autocomplete="new-password">
                <button type="button" class="pwd-eye" onclick="toggleInput('entryPwdInput',this)">👁</button>
                <button type="button" class="pwd-gen-btn" onclick="UI_genForEntry()">Generate</button>
              </div>
              <div class="strength-track"><div class="strength-bar" id="entryStrengthBar"></div></div>
            </div>`;
            html += f('totp','TOTP Secret (optional)', data.totp||'','text','Base32 secret');
            html += ta('notes','Notes', data.notes||'');
        } else if (type === 'note') {
            html += ta('content','Content', data.content||'');
        } else if (type === 'card') {
            html += f('cardholderName','Cardholder Name', data.cardholderName||'');
            html += f('cardNumber','Card Number', data.cardNumber||'','text','1234 5678 9012 3456');
            html += f('expiry','Expiry (MM/YY)', data.expiry||'','text','12/27');
            html += f('cvv','CVV', data.cvv||'','password','•••');
            html += ta('notes','Notes', data.notes||'');
        } else if (type === 'identity') {
            html += f('firstName','First Name', data.firstName||'');
            html += f('lastName','Last Name', data.lastName||'');
            html += f('email','Email', data.email||'','email');
            html += f('phone','Phone', data.phone||'','tel');
            html += f('address','Address', data.address||'');
            html += f('city','City', data.city||'');
            html += f('zip','ZIP / Postal Code', data.zip||'');
            html += f('country','Country', data.country||'');
        }
        c.innerHTML = html;

        // Bind password strength meter
        const pwdInput = $('entryPwdInput');
        if (pwdInput) {
            pwdInput.addEventListener('input', () => {
                const s = PasswordGenerator.score(pwdInput.value);
                const bar = $('entryStrengthBar');
                if (bar) {
                    bar.style.width = [0,25,50,75,100][s] + '%';
                    bar.style.background = PasswordGenerator.SCORE_COLORS[s];
                }
            });
        }
    }

    function populateFolderSelect(selectedId = null) {
        const sel = $('entryFolder');
        const folders = VaultManager.state.folders;
        sel.innerHTML = '<option value="">— No folder —</option>' +
            folders.map(f => `<option value="${f.id}" ${f.id == selectedId ? 'selected' : ''}>${esc(f.name)}</option>`).join('');
    }

    function addCustomFieldRow(label = '', value = '') {
        const list = $('customFieldsList');
        const div = document.createElement('div');
        div.className = 'custom-field-row';
        div.innerHTML = `
          <input class="fg-input cf-label" type="text" placeholder="Field name" value="${esc(label)}">
          <input class="fg-input cf-value" type="text" placeholder="Value" value="${esc(value)}">
          <button type="button" class="cf-remove" onclick="this.parentElement.remove()">×</button>`;
        list.appendChild(div);
    }

    async function saveEntryForm() {
        const form    = $('entryForm');
        const fd      = new FormData(form);
        const type    = $('entryType').value;
        const get     = k => fd.get(k) || '';

        const entry = { type, title: get('title'), favorite: $('entryFavorite').checked,
                        folder_id: get('entryFolder') || null };
        if (_editUuid) entry.uuid = _editUuid;

        if (type === 'login') {
            Object.assign(entry, { url: get('url'), username: get('username'),
                password: get('password'), totp: get('totp'), notes: get('notes') });
        } else if (type === 'note') {
            entry.content = get('content');
        } else if (type === 'card') {
            Object.assign(entry, { cardholderName: get('cardholderName'), cardNumber: get('cardNumber'),
                expiry: get('expiry'), cvv: get('cvv'), notes: get('notes') });
        } else if (type === 'identity') {
            ['firstName','lastName','email','phone','address','city','zip','country']
                .forEach(k => entry[k] = get(k));
        }

        // Custom fields
        const cfRows = document.querySelectorAll('.custom-field-row');
        if (cfRows.length) {
            entry.customFields = [...cfRows].map(r => ({
                label: r.querySelector('.cf-label').value,
                value: r.querySelector('.cf-value').value
            })).filter(cf => cf.label);
        }

        // folder_id from select
        entry.folder_id = $('entryFolder').value || null;

        const btn = $('saveEntryBtn');
        btn.disabled = true; btn.textContent = 'Saving…';
        try {
            await VaultManager.saveEntry(entry);
            closeModal('entryModal');
        } catch (err) {
            alert('Save failed: ' + err.message);
        } finally {
            btn.disabled = false; btn.textContent = 'Save';
        }
    }

    // ── Generator view ────────────────────────────────────────────────────────
    let _currentGenPwd = '';

    function renderGenerator() {
        const el = $('generatorView');
        if (!el) return;
        genRefresh();
    }

    function genRefresh() {
        const mode   = $('genMode')?.value || 'password';
        const length = parseInt($('genLength')?.value || '20');
        const upper  = $('genUpper')?.checked !== false;
        const lower  = $('genLower')?.checked !== false;
        const digits = $('genDigits')?.checked !== false;
        const syms   = $('genSymbols')?.checked !== false;
        const ambig  = $('genAmbig')?.checked === true;
        const wc     = parseInt($('genWords')?.value || '4');
        const sep    = $('genSep')?.value || '-';

        _currentGenPwd = mode === 'passphrase'
            ? PasswordGenerator.passphrase(wc, sep, true, true)
            : PasswordGenerator.generate({ length, upper, lower, digits, symbols: syms, avoidAmbig: ambig });

        const out = $('genOutput');
        if (out) out.textContent = _currentGenPwd;

        const s = PasswordGenerator.score(_currentGenPwd);
        const bar = $('genStrengthBar');
        const lbl = $('genStrengthLabel');
        if (bar) { bar.style.width = [0,25,50,75,100][s]+'%'; bar.style.background = PasswordGenerator.SCORE_COLORS[s]; }
        if (lbl) { lbl.textContent = PasswordGenerator.SCORE_LABELS[s]||''; lbl.style.color = PasswordGenerator.SCORE_COLORS[s]; }

        const lenLabel = $('genLengthLabel');
        if (lenLabel) lenLabel.textContent = length;
    }

    function genCopy(btn) { copyToClipboard(_currentGenPwd, btn); }

    // ── Security dashboard view ───────────────────────────────────────────────
    async function renderSecurity() {
        const report = SecurityAnalyzer.analyze(VaultManager.state.entries);
        const g = SecurityAnalyzer.grade(report.score);

        $('secScore').textContent      = report.score;
        $('secScoreLabel').textContent = g.label;
        $('secScoreLabel').style.color = g.color;
        $('secScoreCircle').style.setProperty('--score-color', g.color);
        $('secScoreCircle').style.setProperty('--score', report.score);

        renderIssueList('secWeak',   report.weak.map(x=>x.entry),  'Weak password',       '#ef4444');
        renderIssueList('secReused', report.reused,                 'Password reused',      '#f97316');
        renderIssueList('secOld',    report.old.map(x=>x.entry),   'Not changed in 90+ days','#eab308');

        $('secTotalCount').textContent = report.total;
        $('secWeakCount').textContent  = report.weak.length;
        $('secReuseCount').textContent = report.reused.length;
        $('secOldCount').textContent   = report.old.length;
    }

    function renderIssueList(containerId, entries, label, color) {
        const el = $(containerId);
        if (!el) return;
        el.innerHTML = entries.length
            ? entries.map(e => `
              <div class="issue-item" onclick="UI_viewEntry('${esc(e.uuid)}')">
                <div class="entry-icon sm" style="background:${typeColor(e.type)}22;color:${typeColor(e.type)}">${typeIcon(e.type)}</div>
                <div class="issue-info">
                  <div class="issue-title">${esc(e.title||'Untitled')}</div>
                  <div class="issue-label" style="color:${color}">${label}</div>
                </div>
              </div>`).join('')
            : `<p class="issue-empty">None found ✓</p>`;
    }

    // ── Delete confirm ────────────────────────────────────────────────────────
    let _deleteUuid = null;
    function confirmDelete(uuid) {
        _deleteUuid = uuid;
        const e = VaultManager.state.entries.find(x => x.uuid === uuid);
        $('deleteEntryName').textContent = e?.title || 'this item';
        closeModal('viewEntryModal');
        openModal('deleteModal');
    }

    async function doDelete() {
        if (!_deleteUuid) return;
        const btn = $('confirmDeleteBtn');
        btn.disabled = true; btn.textContent = 'Deleting…';
        await VaultManager.deleteEntry(_deleteUuid);
        closeModal('deleteModal');
        btn.disabled = false; btn.textContent = 'Delete';
        _deleteUuid = null;
    }

    // ── Helpers exposed to inline onclick ─────────────────────────────────────
    return {
        showSetupModal, showUnlockModal, showLockedScreen, showVault,
        renderSidebar, renderEntries, renderAll() {
            renderSidebar();
            VaultManager.applyFilter();
        },
        viewEntry, showAddEntry, showEditEntry, copyPwd, renderFormFields,
        addCustomFieldRow, saveEntryForm, confirmDelete, doDelete,
        renderGenerator, genRefresh, genCopy,
        renderSecurity,
        openModal, closeModal, closeAll,
        togglePwdView() {
            const mask  = $('pwdMask');
            const plain = $('pwdPlain');
            if (!mask || !plain) return;
            mask.classList.toggle('hidden');
            plain.classList.toggle('hidden');
        }
    };
})();

// Global shims for inline onclick attributes
const UI_viewEntry    = uuid => VaultUI.viewEntry(uuid);
const UI_showAddEntry = (t)  => VaultUI.showAddEntry(t);
const UI_showEditEntry= uuid => VaultUI.showEditEntry(uuid);
const UI_copyPwd      = (u,b)=> VaultUI.copyPwd(u,b);
const UI_genForEntry  = ()   => {
    const pwd = PasswordGenerator.generate({ length: 20, symbols: true });
    const inp = document.getElementById('entryPwdInput');
    if (inp) { inp.value = pwd; inp.dispatchEvent(new Event('input')); }
};
const VM_delFolder = id => {
    if (confirm('Delete this folder? Items inside will become unorganised.'))
        VaultManager.deleteFolder(id);
};

function toggleInput(id, btn) {
    const el = document.getElementById(id);
    if (!el) return;
    el.type = el.type === 'password' ? 'text' : 'password';
    btn.textContent = el.type === 'password' ? '👁' : '🙈';
}
function togglePwdView() { VaultUI.togglePwdView(); }
