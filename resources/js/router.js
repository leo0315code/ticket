import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth';

/** 按角色返回工单详情路径（铃铛/跳转共用） */
export function ticketPath(id) {
    const auth = useAuthStore();
    return auth.user?.role === 'user' ? `/m/tickets/${id}` : `/admin/tickets/${id}`;
}

const routes = [
    { path: '/login', component: () => import('./views/LoginView.vue'), meta: { guest: true } },

    // 管理后台（管理员/客服）
    {
        path: '/admin',
        component: () => import('./components/AppLayout.vue'),
        children: [
            { path: 'tickets', component: () => import('./views/TicketListView.vue') },
            { path: 'tickets/new', component: () => import('./views/TicketCreateView.vue') },
            { path: 'tickets/:id', component: () => import('./views/TicketDetailView.vue') },
            { path: 'dashboard', component: () => import('./views/DashboardView.vue'), meta: { staff: true } },
            { path: 'categories', component: () => import('./views/CategoriesView.vue'), meta: { admin: true } },
            { path: 'users', component: () => import('./views/UsersView.vue'), meta: { admin: true } },
            { path: 'faqs', component: () => import('./views/FaqView.vue') },
            { path: '', redirect: 'tickets' },
        ],
    },

    // 移动客户端（普通用户；staff 也可访问）
    {
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

    // 根路径与旧链接兼容
    { path: '/', redirect: () => homePath() },
    { path: '/tickets/:id(\\d+)', redirect: (to) => ticketPath(to.params.id) },
    { path: '/tickets/new', redirect: () => `${homePath()}/tickets/new` },
    { path: '/dashboard', redirect: '/admin/dashboard' },
    { path: '/categories', redirect: '/admin/categories' },
    { path: '/users', redirect: '/admin/users' },
    { path: '/faqs', redirect: () => `${homePath() === '/admin' ? '/admin' : '/m'}/faqs` },
];

function homePath() {
    const auth = useAuthStore();
    return auth.user?.role === 'user' ? '/m/tickets' : '/admin/tickets';
}

const router = createRouter({ history: createWebHistory(), routes });

router.beforeEach((to) => {
    const auth = useAuthStore();
    if (!to.meta.guest && !auth.isLoggedIn) {
        return to.path.startsWith('/admin') ? '/login' : '/login';
    }
    if (to.meta.staff && !auth.isStaff) return '/m/tickets';
    if (to.meta.admin && !auth.isAdmin) return '/m/tickets';
    // 普通用户不能进管理后台
    if (auth.isLoggedIn && auth.user?.role === 'user' && to.path.startsWith('/admin')) {
        return '/m/tickets';
    }
    if (to.path === '/login' && auth.isLoggedIn) {
        return homePath();
    }
    return true;
});

export default router;
