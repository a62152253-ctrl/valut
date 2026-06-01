const SecurityAnalyzer = (() => {

    /** Analyse a list of decrypted entries and return a health report. */
    function analyze(entries) {
        const logins = entries.filter(e => e.type === 'login' && e.password);

        const weak       = [];
        const reused     = [];
        const old        = [];
        const noPassword = [];

        const pwdMap = {};   // password → [entries]
        const now = Date.now();
        const NINETY_DAYS = 90 * 24 * 60 * 60 * 1000;

        for (const e of logins) {
            const pwd = e.password || '';

            // Weak check
            const s = PasswordGenerator.score(pwd);
            if (s <= 2) weak.push({ entry: e, score: s });

            // Reuse tracking
            if (pwd) {
                pwdMap[pwd] = pwdMap[pwd] || [];
                pwdMap[pwd].push(e);
            }

            // Old password (based on updated_at)
            if (e.updated_at) {
                const age = now - new Date(e.updated_at).getTime();
                if (age > NINETY_DAYS) old.push({ entry: e, days: Math.floor(age / 86400000) });
            }

            // No password
            if (!pwd) noPassword.push(e);
        }

        for (const [pwd, group] of Object.entries(pwdMap)) {
            if (group.length > 1) group.forEach(e => reused.push(e));
        }

        // Deduplicate reused
        const reusedUniq = [...new Map(reused.map(e => [e.uuid, e])).values()];

        // Overall score: 100 minus penalties
        const total = logins.length || 1;
        let score = 100;
        score -= Math.min(40, Math.round((weak.length / total) * 40));
        score -= Math.min(30, Math.round((reusedUniq.length / total) * 30));
        score -= Math.min(20, Math.round((old.length / total) * 20));
        score = Math.max(0, score);

        return { score, weak, reused: reusedUniq, old, noPassword, total: logins.length };
    }

    /** Check a single password against HIBP via local proxy. Returns breach count. */
    async function checkHIBP(password) {
        const hash   = await CryptoEngine.sha1Hex(password);
        const prefix = hash.slice(0, 5);
        const suffix = hash.slice(5);

        try {
            const resp = await fetch(`api/hibp.php?prefix=${prefix}`);
            if (!resp.ok) return -1;
            const text = await resp.text();
            for (const line of text.split('\n')) {
                const [s, count] = line.trim().split(':');
                if (s.toUpperCase() === suffix) return parseInt(count, 10);
            }
            return 0;
        } catch {
            return -1;
        }
    }

    /** Grade label for a score 0–100. */
    function grade(score) {
        if (score >= 90) return { label: 'Excellent', color: '#22c55e' };
        if (score >= 70) return { label: 'Good',      color: '#84cc16' };
        if (score >= 50) return { label: 'Fair',      color: '#eab308' };
        if (score >= 30) return { label: 'Poor',      color: '#f97316' };
        return { label: 'Critical', color: '#ef4444' };
    }

    return { analyze, checkHIBP, grade };
})();
