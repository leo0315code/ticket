import { defineStore } from 'pinia';
import { disconnectRealtime } from '../realtime';
import http from '../api';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem('ticket_token') || '',
        user: JSON.parse(localStorage.getItem('ticket_user') || 'null'),
    }),
    getters: {
        isLoggedIn: (s) => !!s.token,
        isStaff: (s) => !!(s.user && (s.user.permissions?.includes('panel.access') || ['agent', 'admin'].includes(s.user.role))),
        isAdmin: (s) => !!(s.user && (s.user.permissions?.includes('*') || s.user.role === 'admin')),
        // 细粒度权限：后端未下发 permissions 时（旧缓存）按内置角色兜底
        can: (s) => (permission) => {
            const user = s.user;
            if (!user) return false;
            if (Array.isArray(user.permissions)) {
                return user.permissions.includes('*') || user.permissions.includes(permission);
            }
            if (user.role === 'admin') return true;
            return ['panel.access', 'ticket.view_all', 'ticket.assign', 'ticket.close', 'ticket.export',
                'ticket.internal_note', 'canned.manage', 'stats.view', 'log.view'].includes(permission);
        },
    },
    actions: {
        save(token, user) {
            this.token = token;
            this.user = user;
            localStorage.setItem('ticket_token', token);
            localStorage.setItem('ticket_user', JSON.stringify(user));
        },
        applySession(token, user) {
            this.save(token, user);
        },
        async login(account, password) {
            const { data } = await http.post('/login', { account, password });
            this.save(data.token, data.user);
        },
        async loginWithPhone(phone, code) {
            const { data } = await http.post('/auth/phone/login', { phone, code });
            this.save(data.token, data.user);
        },
        async loginWithWechat(code, state) {
            const { data } = await http.post('/auth/wechat/login', { code, state });
            this.save(data.token, data.user);
        },
        async sendBindPhoneCode(phone) {
            const { data } = await http.post('/profile/phone/code', { phone });
            return data;
        },
        async bindPhone(phone, code) {
            const { data } = await http.put('/profile/phone', { phone, code });
            this.user = data.user;
            localStorage.setItem('ticket_user', JSON.stringify(data.user));
            return data.user;
        },
        async bindWechat(code, state) {
            const { data } = await http.post('/profile/wechat/bind', { code, state });
            this.user = data.user;
            localStorage.setItem('ticket_user', JSON.stringify(data.user));
            return data.user;
        },
        async unbindWechat() {
            await http.delete('/profile/wechat');
            this.user = { ...this.user, has_wechat: false, wechat_nickname: null };
            localStorage.setItem('ticket_user', JSON.stringify(this.user));
        },
        async updateProfile(payload) {
            const { data } = await http.put('/profile', payload);
            this.user = data.user;
            localStorage.setItem('ticket_user', JSON.stringify(data.user));
            return data.user;
        },
        async logout() {
            // 先断开 WS，网关 onClose 立即把该用户标记离线
            disconnectRealtime();
            // 本地状态一定清干净；服务端注销失败（token 过期等）不阻塞退出
            try {
                await http.post('/logout');
            } catch {
                // 忽略：本地仍然退出
            }
            this.token = '';
            this.user = null;
            localStorage.removeItem('ticket_token');
            localStorage.removeItem('ticket_user');
        },
        async refreshMe() {
            const { data } = await http.get('/me');
            this.user = data.user;
            localStorage.setItem('ticket_user', JSON.stringify(data.user));
        },
    },
});
