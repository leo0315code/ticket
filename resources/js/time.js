/**
 * 人性化时间：刚刚 / n 分钟前 / n 小时前 / 昨天 HH:mm / M月d日 / yyyy-MM-dd
 * 入参支持 'yyyy-MM-dd HH:mm:ss' 或 ISO。
 */
export function timeAgo(input) {
    if (! input) return '';
    const t = new Date(String(input).replace(' ', 'T'));
    if (Number.isNaN(t.getTime())) return String(input);

    const diff = Date.now() - t.getTime();
    const minute = 60_000;
    const hour = 3_600_000;

    if (diff < minute) return '刚刚';
    if (diff < hour) return `${Math.floor(diff / minute)} 分钟前`;
    if (diff < 24 * hour) return `${Math.floor(diff / hour)} 小时前`;

    const pad = (n) => String(n).padStart(2, '0');
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    if (t.toDateString() === yesterday.toDateString()) return `昨天 ${pad(t.getHours())}:${pad(t.getMinutes())}`;
    if (t.getFullYear() === new Date().getFullYear()) return `${t.getMonth() + 1}月${t.getDate()}日`;

    return `${t.getFullYear()}-${pad(t.getMonth() + 1)}-${pad(t.getDate())}`;
}
