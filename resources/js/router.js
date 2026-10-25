import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth';

const routes = [
    { path: '/login', component: () => import('./views/LoginView.vue'), meta: { guest: true } },
    {
        path: '/',
        component: () => import('./components/AppLayout.vue'),
        children: [
            { path: '', component: () => import('./views/TicketListView.vue') },
            { path: 'tickets/new', component: () => import('./views/TicketCreateView.vue') },
            { path: 'faqs', component: () => import('./views/FaqView.vue') },
            { path: 'tickets/:id', component: () => import('./views/TicketDetailView.vue') },
            { path: 'dashboard', component: () => import('./views/DashboardView.vue'), meta: { staff: true } },
            { path: 'categories', component: () => import('./views/CategoriesView.vue'), meta: { admin: true } },
            { path: 'users', component: () => import('./views/UsersView.vue'), meta: { admin: true } },
        ],
    },
];

const router = createRouter({ history: createWebHistory(), routes });

router.beforeEach((to) => {
    const auth = useAuthStore();
    if (!to.meta.guest && !auth.isLoggedIn) return '/login';
    if (to.meta.staff && !auth.isStaff) return '/';
    if (to.meta.admin && !auth.isAdmin) return '/';
    if (to.path === '/login' && auth.isLoggedIn) return '/';
    return true;
});

export default router;
