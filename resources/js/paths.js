import { useAuthStore } from './stores/auth';

/** 管理后台路径前缀（blade 注入，.env ADMIN_PATH 可配） */
export const ADMIN_BASE = `/${(window.ADMIN_PATH || 'panel').replace(/^\/+|\/+$/g, '')}`;

/** 后台子路径：adminPath('tickets') → /panel/tickets */
export function adminPath(segment = '') {
    return segment ? `${ADMIN_BASE}/${segment.replace(/^\/+/, '')}` : ADMIN_BASE;
}

export function isAdminRoute(path) {
    return path === ADMIN_BASE || path.startsWith(`${ADMIN_BASE}/`);
}

/** 按角色返回工单详情路径 */
export function ticketPath(id) {
    const auth = useAuthStore();
    return auth.user?.role === 'user' ? `/m/tickets/${id}` : `${ADMIN_BASE}/tickets/${id}`;
}

/** 登录后的首页 */
export function homePath() {
    const auth = useAuthStore();
    // 游客先看帮助中心：既是对外门面，也能自助解决一部分问题
    if (! auth.isLoggedIn) return '/help';
    return auth.user?.role === 'user' ? '/m/tickets' : `${ADMIN_BASE}/tickets`;
}
