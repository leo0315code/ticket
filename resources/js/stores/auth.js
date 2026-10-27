import { defineStore } from 'pinia';
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
        async login(account, password) {
            const { data } = await http.post('/login', { account, password });
            this.save(data.token, data.user);
        },
        async register(name, username, password) {
            const { data } = await http.post('/register', { name, username, password });
            this.save(data.token, data.user);
        },
        async updateProfile(payload) {
            const { data } = await http.put('/profile', payload);
            this.user = data.user;
            localStorage.setItem('ticket_user', JSON.stringify(data.user));
            return data.user;
        },
        async logout() {
            try {
                await http.post('/logout');
            } finally {
                this.token = '';
                this.user = null;
                localStorage.removeItem('ticket_token');
                localStorage.removeItem('ticket_user');
            }
        },
        async refreshMe() {
            const { data } = await http.get('/me');
            this.user = data.user;
            localStorage.setItem('ticket_user', JSON.stringify(data.user));
        },
    },
});
