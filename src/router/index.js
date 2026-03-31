import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/authStore';
import { useUserStore } from '@/stores/userStore';

const routes = [
    {
        path: '/',
        beforeEnter: (to, from, next) => {
            const authStore = useAuthStore();
            next(authStore.token ? '/dashboard' : '/login');
        }
    },
    {
        path: '/login',
        name: 'Login',
        component: () => import('@/views/LoginView.vue'),
        meta: { requiresGuest: true },
    },
    {
        path: '/dashboard',
        component: () => import('@/layout/AppLayout.vue'),
        meta: { requiresAuth: true },
        children: [
            {
                path: '',
                name: 'Dashboard',
                component: () => import('@/views/admin/DashboardCrufiture.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer'], title: 'Tableau de bord' },
            },
            {
                path: 'simulateur',
                name: 'Simulateur',
                component: () => import('@/views/admin/SimulateurFormulation.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer'], title: 'Simulateur de formulation' },
            },
            {
                path: 'saveurs',
                name: 'Saveurs',
                component: () => import('@/views/admin/GestionSaveurs.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer'], title: 'Saveurs' },
            },
        ],
    },
    { path: '/unauthorized', name: 'Unauthorized', component: () => import('@/views/common/Error.vue') },
    { path: '/:pathMatch(.*)*',  name: 'NotFound',    component: () => import('@/views/common/Error.vue') },
];

const router = createRouter({
    history: createWebHistory('/crufiture/'),
    routes,
});

router.beforeEach(async (to, from, next) => {
    const authStore = useAuthStore();
    const userStore = useUserStore();

    if (to.meta.requiresAuth && !userStore.user) {
        await authStore.authenticate();
    }

    if (to.meta.requiresAuth && !authStore.isAuthenticated) {
        return next({ name: 'Login', query: { redirect: to.fullPath } });
    }

    if (to.meta.roles && userStore.user) {
        const hasRole = to.meta.roles.some(r => userStore.userRoles.includes(r));
        if (!hasRole) return next({ name: 'Unauthorized' });
    }

    if (to.meta.requiresGuest && authStore.isAuthenticated) {
        return next({ name: 'Dashboard' });
    }

    if (to.meta.title) document.title = to.meta.title + ' — Crufiture';

    next();
});

export default router;