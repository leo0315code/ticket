import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth';

const routes = [
    { path: '/login', component: () => import('./views/LoginView.vue'), meta: { guest: true } },
    {
        // 桌面后台：管理员/客服
        path: '/',
        component: () => import('./components/AppLayout.vue'),
        children: [
            { path: '', component: () => import('./views/TicketListView.vue') },
            { path: 'tickets/new', component: () => import('./views/TicketCreateView.vue') },
            { path: 'tickets/:id', component: () => import('./views/TicketDetailView.vue') },
            { path: 'dashboard', component: () => import('./views/DashboardView.vue'), meta: { staff: true } },
            { path: 'categories', component: () => import('./views/CategoriesView.vue'), meta: { admin: true } },
            { path: 'users', component: () => import('./views/UsersView.vue'), meta: { admin: true } },
            { path: 'faqs', component: () => import('./views/FaqView.vue') },
        ],
    },
    {
        // 移动客户端：普通用户（staff 也可访问）
        path: '/m',
        component: () => import('./components/MLayout.vue'),
        children: [
            { path: 'tickets', component: () => import('./views/client/ClientTickets.vue') },
            { path: 'new', component: () => import('./views/TicketCreateView.vue') },
            { path: 'tickets/:id', component: () => import('./views/TicketDetailView.vue') },
            { path: 'faqs', component: () => import('./views/FaqView.vue') },
            { path: 'profile', component: () => import('./views/client/MProfile.vue') },
            { path: '', redirect: 'tickets' },
        ],
    },
];

const router = createRouter({ history: createWebHistory(), routes });

router.beforeEach((to) => {
    const auth = useAuthStore();
    if (!to.meta.guest && !auth.isLoggedIn) return '/login';
    if (to.meta.staff && !auth.isStaff) return '/m/tickets';
    if (to.meta.admin && !auth.isAdmin) return '/m/tickets';
    // 普通用户只能进客户端路由（后台桌面端留给 staff）
    if (auth.isLoggedIn && auth.user?.role === 'user' && !to.path.startsWith('/m') && !to.meta.guest) {
        return '/m/tickets';
    }
    if (to.path === '/login' && auth.isLoggedIn) {
        return auth.user?.role === 'user' ? '/m/tickets' : '/';
    }
    return true;
});

export default router;
