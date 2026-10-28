import { createRouter, createWebHistory } from 'vue-router';
import { clearErrors } from './notify';
import { ADMIN_BASE, adminPath, homePath, isAdminRoute, ticketPath } from './paths';
import { useAuthStore } from './stores/auth';
import { meta } from './meta';

export { ticketPath };

const routes = [
    { path: '/login', component: () => import('./views/LoginView.vue'), meta: { guest: true, client: true, title: '登录' } },
    { path: adminPath('login'), name: 'admin-login', component: () => import('./views/AdminLoginView.vue'), meta: { guest: true, adminGate: true, title: '后台登录' } },

    // 管理后台（管理员/客服）——路径前缀由 .env ADMIN_PATH 配置，避免使用易猜的 admin
    {
        path: ADMIN_BASE,
        component: () => import('./components/AppLayout.vue'),
        children: [
            { path: 'tickets', component: () => import('./views/TicketListView.vue'), meta: { title: '工单列表' } },
            { path: 'tickets/new', component: () => import('./views/TicketCreateView.vue'), meta: { title: '新建工单' } },
            { path: 'tickets/:id', component: () => import('./views/TicketDetailView.vue'), meta: { title: '工单详情' } },
            { path: 'dashboard', component: () => import('./views/DashboardView.vue'), meta: { permission: 'stats.view', title: '统计看板' } },
            { path: 'categories', component: () => import('./views/CategoriesView.vue'), meta: { permission: 'category.manage', title: '分类与规则' } },
            { path: 'users', component: () => import('./views/UsersView.vue'), meta: { permission: 'user.manage', title: '用户管理' } },
            { path: 'roles', component: () => import('./views/RolesView.vue'), meta: { permission: 'role.manage', title: '角色权限' } },
            { path: 'faqs', component: () => import('./views/FaqView.vue'), meta: { title: '常见问题' } },
            { path: 'logs', component: () => import('./views/LogsView.vue'), meta: { permission: 'log.view', title: '操作审计' } },
            { path: 'settings', component: () => import('./views/SettingsView.vue'), meta: { permission: 'settings.manage', title: '系统设置' } },
            { path: '', redirect: 'tickets' },
        ],
    },

    // 移动客户端（普通用户；staff 也可访问）
    {
        path: '/m',
        component: () => import('./components/MLayout.vue'),
        children: [
            { path: 'tickets', component: () => import('./views/client/ClientTickets.vue'), meta: { title: '我的工单' } },
            { path: 'new', component: () => import('./views/TicketCreateView.vue'), meta: { title: '提交工单' } },
            { path: 'tickets/:id', component: () => import('./views/TicketDetailView.vue'), meta: { title: '工单详情' } },
            { path: 'faqs', component: () => import('./views/FaqView.vue'), meta: { title: '常见问题' } },
            { path: 'profile', component: () => import('./views/client/MProfile.vue'), meta: { title: '我的' } },
            { path: '', redirect: 'tickets' },
        ],
    },

    // 公开页面（无需登录，也不因已登录而跳走）
    { path: '/wechat/callback', component: () => import('./views/WeChatCallbackView.vue'), meta: { public: true } },
    { path: '/help', component: () => import('./views/HelpCenterView.vue'), meta: { public: true, title: '帮助中心' } },
    { path: '/forgot-password', component: () => import('./views/ForgotPasswordView.vue'), meta: { public: true, title: '找回密码' } },
    { path: '/reset-password', component: () => import('./views/ResetPasswordView.vue'), meta: { public: true, title: '重置密码' } },

    // 根路径按角色分流；旧链接兼容（/admin 不再指向后台，统一送去登录页——不暴露后台存在）
    { path: '/', redirect: () => homePath() },
    { path: '/tickets/:id(\\d+)', redirect: (to) => ticketPath(to.params.id) },
    { path: '/:pathMatch(.*)*', redirect: '/login' },
];

const router = createRouter({ history: createWebHistory(), routes });

router.beforeEach((to) => {
    const auth = useAuthStore();
    // 未登录：后台去后台登录页，其余去客户端登录页
    if (!to.meta.guest && !to.meta.public && !auth.isLoggedIn) {
        const loginPath = isAdminRoute(to.path) ? adminPath('login') : '/login';
        // 带上原目标，登录后回跳（邮件深链、分享链接都能落到具体工单）
        return { path: loginPath, query: to.fullPath === '/' ? {} : { redirect: to.fullPath } };
    }
    if (to.meta.staff && !auth.isStaff) return '/m/tickets';
    if (to.meta.admin && !auth.isAdmin) return '/m/tickets';
    if (to.meta.permission && !auth.can(to.meta.permission)) return adminPath('tickets');
    // 普通用户不能进管理后台
    if (auth.isLoggedIn && auth.user?.role === 'user' && isAdminRoute(to.path) && !to.meta.guest) {
        return '/m/tickets';
    }
    // 已登录访问登录页：各回各家
    if (to.meta.guest && auth.isLoggedIn) {
        if (to.query.redirect) return to.query.redirect;
        if (to.meta.adminGate) return auth.isStaff ? adminPath('tickets') : '/m/tickets';
        return auth.user?.role === 'user' ? '/m/tickets' : adminPath('tickets');
    }
    return true;
});

// 页面切换后，上一个页面残留的常驻错误提示不应跟着走
router.afterEach((to) => {
    clearErrors();
    const site = meta().site_name || '工单支持中心';
    document.title = to.meta.title ? `${to.meta.title} · ${site}` : site;
});

export default router;
