import {t as translate} from './i18n.js';

const root = document.documentElement;
const storageKey = 'sticker-fight:theme';
const validTheme = value => value === 'light' || value === 'dark';

function cookieTheme() {
    try {
        return document.cookie.split(';').map(part => part.trim()).find(part => part.startsWith('sf_theme='))?.slice('sf_theme='.length);
    } catch {
        return null;
    }
}

function applyTheme(theme, persist = false) {
    const light = theme === 'light';
    root.dataset.theme = light ? 'light' : 'dark';
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', light ? '#FFFFFF' : '#1C1B20');
    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
        button.setAttribute('aria-pressed', String(light));
        button.title = translate(light ? 'التحويل إلى النمط الليلي' : 'التحويل إلى النمط النهاري');
    });
    if (persist) {
        try { localStorage.setItem(storageKey, root.dataset.theme); } catch {}
        try { document.cookie = `sf_theme=${root.dataset.theme}; Path=/; Max-Age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`; } catch {}
    }
}

let saved = null;
try { saved = localStorage.getItem(storageKey); } catch {}
const cookie = cookieTheme();
applyTheme(validTheme(cookie) ? cookie : validTheme(saved) ? saved : root.dataset.theme);
document.querySelectorAll('[data-theme-toggle]').forEach(button => {
    button.addEventListener('click', () => applyTheme(root.dataset.theme === 'light' ? 'dark' : 'light', true));
});
window.addEventListener('storage', event => {
    if (event.key === storageKey && validTheme(event.newValue)) applyTheme(event.newValue);
});
