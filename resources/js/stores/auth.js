import { defineStore } from 'pinia';
import http from '../api';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem('ticket_token') || '',
        user: JSON.parse(localStorage.getItem('ticket_user') || 'null'),
    }),
    getters: {
        isLoggedIn: (s) => !!s.token,
        isStaff: (s) => s.user && ['agent', 'admin'].includes(s.user.role),
        isAdmin: (s) => s.user?.role === 'admin',
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
        async register(name, username, password, email = null) {
            const { data } = await http.post('/register', { name, username, password, email: email || null });
            this.save(data.token, data.user);
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
