import axios from 'axios';

const http = axios.create({ baseURL: '/api' });

http.interceptors.request.use((config) => {
    const token = localStorage.getItem('ticket_token');
    if (token) config.headers.Authorization = `Bearer ${token}`;
    return config;
});

http.interceptors.response.use(
    (res) => res,
    (err) => {
        if (err.response?.status === 401) {
            localStorage.removeItem('ticket_token');
            localStorage.removeItem('ticket_user');
            if (!location.pathname.startsWith('/login')) location.href = '/login';
        }
        return Promise.reject(err);
    },
);

export default http;
