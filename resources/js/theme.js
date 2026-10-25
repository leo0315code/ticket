const KEY = 'ticket_dark';

/** 初始化主题：优先读用户偏好，否则跟随系统 */
export function initTheme() {
    const saved = localStorage.getItem(KEY);
    const dark = saved === null
        ? window.matchMedia('(prefers-color-scheme: dark)').matches
        : saved === '1';
    document.documentElement.classList.toggle('dark', dark);
    return dark;
}

export function isDark() {
    return document.documentElement.classList.contains('dark');
}

export function toggleTheme() {
    const dark = !isDark();
    document.documentElement.classList.toggle('dark', dark);
    localStorage.setItem(KEY, dark ? '1' : '0');
    return dark;
}
