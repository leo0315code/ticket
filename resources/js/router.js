import { createRouter, createWebHistory } from 'vue-router';
import { ADMIN_BASE, adminPath, homePath, isAdminRoute, ticketPath } from './paths';
import { useAuthStore } from './stores/auth';

export { ticketPath };

const routes = [
    { path: '/login', component: () => import('./views/LoginView.vue'), meta: { guest: true, client: true } },
    { path: adminPath('login'), name: 'admin-login', component: () => import('./views/AdminLoginView.vue'), meta: { guest: true, adminGate: true } },

    // 管理后台（管理员/客服）——路径前缀由 .env ADMIN_PATH 配置，避免使用易猜的 admin
    {
        path: ADMIN_BASE,
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

    // 根路径按角色分流；旧链接兼容（/admin 不再指向后台，统一送去登录页——不暴露后台存在）
    { path: '/', redirect: () => homePath() },
    { path: '/tickets/:id(\\d+)', redirect: (to) => ticketPath(to.params.id) },
    { path: '/:pathMatch(.*)*', redirect: '/login' },
];

const router = createRouter({ history: createWebHistory(), routes });

router.beforeEach((to) => {
    const auth = useAuthStore();
    // 未登录：后台去后台登录页，其余去客户端登录页
    if (!to.meta.guest && !auth.isLoggedIn) {
        return isAdminRoute(to.path) ? adminPath('login') : '/login';
    }
    if (to.meta.staff && !auth.isStaff) return '/m/tickets';
    if (to.meta.admin && !auth.isAdmin) return '/m/tickets';
    // 普通用户不能进管理后台
    if (auth.isLoggedIn && auth.user?.role === 'user' && isAdminRoute(to.path) && !to.meta.guest) {
        return '/m/tickets';
    }
    // 已登录访问登录页：各回各家
    if (to.meta.guest && auth.isLoggedIn) {
        if (to.meta.adminGate) return auth.isStaff ? adminPath('tickets') : '/m/tickets';
        return auth.user?.role === 'user' ? '/m/tickets' : adminPath('tickets');
    }
    return true;
});

export default router;
