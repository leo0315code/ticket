import http from './api';

// 公开的站点元信息（站点名称、注册开关、附件上限）：未登录也要能拿到
const FALLBACK = { site_name: '工单支持中心', allow_register: true, attach_max_mb: 10, attach_max_count: 10, phone_login_enabled: false, wechat_login_enabled: false, wechat_mode: 'open', support_phone: '', work_hours_enabled: false, work_days: '1,2,3,4,5', work_hours_start: '09:00', work_hours_end: '18:00', agents_online: false, require_online_agent: false, maintenance_mode: false, maintenance_message: '' };

let cache = null;
let pending = null;

export async function loadMeta() {
    if (cache) return cache;
    if (!pending) {
        pending = http
            .get('/meta')
            .then(({ data }) => {
                cache = { ...FALLBACK, ...data };
                if (cache.site_name) document.title = cache.site_name;
                return cache;
            })
            .catch(() => ({ ...FALLBACK }))
            .finally(() => {
                pending = null;
            });
    }
    return pending;
}

/** 同步读取：调用方在 onMounted 里 await loadMeta() 后再用 */
export function meta() {
    return cache || { ...FALLBACK };
}
