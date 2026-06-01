const PasswordGenerator = (() => {
    const UPPER   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';  // no I, O
    const LOWER   = 'abcdefghjkmnpqrstuvwxyz';   // no i, l, o
    const DIGITS  = '23456789';                   // no 0, 1
    const SYMBOLS = '!@#$%^&*-_=+?';
    const FULL_UPPER   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const FULL_LOWER   = 'abcdefghijklmnopqrstuvwxyz';
    const FULL_DIGITS  = '0123456789';
    const FULL_SYMBOLS = '!@#$%^&*()_+-=[]{}|;:,.<>?';

    const WORDS = [
        'apple','brave','cloud','delta','eagle','flame','grace','horse','ivory','jade',
        'kneel','lemon','magic','noble','ocean','piano','quest','river','stone','tiger',
        'ultra','vapor','witch','xenon','yacht','zebra','amber','blaze','crisp','drift',
        'ember','frost','glide','haste','input','jewel','karma','lunar','mirage','nexus',
        'orbit','prism','quartz','ridge','spark','titan','umbra','vault','waltz','pixel',
        'azure','brush','crane','depth','evoke','flora','ghost','haven','ingot','joust',
        'knack','lance','maple','north','oasis','plume','query','realm','solar','trove',
        'unify','visor','wrath','xylem','yearn','zonal','aloft','bison','cedar','dunes',
        'epoch','forge','grail','humid','irony','joker','knife','lotus','marsh','nerve',
        'onyx','pearl','quota','raven','scout','torch','urban','viola','wedge','xeric'
    ];

    function randomInt(max) {
        const arr = new Uint32Array(1);
        crypto.getRandomValues(arr);
        return arr[0] % max;
    }

    function randomChar(alphabet) {
        return alphabet[randomInt(alphabet.length)];
    }

    function shuffle(arr) {
        for (let i = arr.length - 1; i > 0; i--) {
            const j = randomInt(i + 1);
            [arr[i], arr[j]] = [arr[j], arr[i]];
        }
        return arr;
    }

    /**
     * Generate a random password.
     * @param {Object} opts
     * @param {number} opts.length      - 8–128 (default 20)
     * @param {boolean} opts.upper      - include uppercase (default true)
     * @param {boolean} opts.lower      - include lowercase (default true)
     * @param {boolean} opts.digits     - include digits (default true)
     * @param {boolean} opts.symbols    - include symbols (default true)
     * @param {boolean} opts.avoidAmbig - avoid ambiguous chars (default false)
     */
    function generate(opts = {}) {
        const len       = Math.min(128, Math.max(8, opts.length ?? 20));
        const useUpper  = opts.upper  !== false;
        const useLower  = opts.lower  !== false;
        const useDigits = opts.digits !== false;
        const useSym    = opts.symbols !== false;
        const noAmbig   = opts.avoidAmbig === true;

        const upper   = noAmbig ? UPPER   : FULL_UPPER;
        const lower   = noAmbig ? LOWER   : FULL_LOWER;
        const digits  = noAmbig ? DIGITS  : FULL_DIGITS;
        const symbols = noAmbig ? SYMBOLS : FULL_SYMBOLS;

        let pool = '';
        const required = [];
        if (useUpper)  { pool += upper;   required.push(randomChar(upper));   }
        if (useLower)  { pool += lower;   required.push(randomChar(lower));   }
        if (useDigits) { pool += digits;  required.push(randomChar(digits));  }
        if (useSym)    { pool += symbols; required.push(randomChar(symbols)); }
        if (!pool)     { pool = FULL_LOWER; required.push(randomChar(FULL_LOWER)); }

        const rest = Array.from({ length: len - required.length }, () => randomChar(pool));
        return shuffle([...required, ...rest]).join('');
    }

    /**
     * Generate a passphrase.
     * @param {number} wordCount  - 3–8 (default 4)
     * @param {string} separator  - separator char (default '-')
     * @param {boolean} capitalize
     * @param {boolean} addNumber - append a 2-digit number at end
     */
    function passphrase(wordCount = 4, separator = '-', capitalize = true, addNumber = true) {
        const count = Math.min(8, Math.max(3, wordCount));
        const chosen = Array.from({ length: count }, () => {
            const w = WORDS[randomInt(WORDS.length)];
            return capitalize ? w[0].toUpperCase() + w.slice(1) : w;
        });
        const suffix = addNumber ? separator + String(randomInt(90) + 10) : '';
        return chosen.join(separator) + suffix;
    }

    /** Score a password strength 0–4. */
    function score(pwd) {
        if (!pwd || pwd.length < 4) return 0;
        let s = 0;
        if (pwd.length >= 8)  s++;
        if (pwd.length >= 14) s++;
        if (/[A-Z]/.test(pwd) && /[a-z]/.test(pwd)) s++;
        if (/[0-9]/.test(pwd)) s++;
        if (/[^A-Za-z0-9]/.test(pwd)) s++;
        return Math.min(4, s);
    }

    const SCORE_LABELS = ['', 'Weak', 'Fair', 'Good', 'Strong'];
    const SCORE_COLORS = ['transparent', '#ef4444', '#f97316', '#eab308', '#22c55e'];

    return { generate, passphrase, score, SCORE_LABELS, SCORE_COLORS };
})();
