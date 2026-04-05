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

    // ── Dashboard bureau — AppLayout ───────────────────────────
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
            {
                path: 'recettes',
                name: 'Recettes',
                component: () => import('@/views/admin/GestionRecettes.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer'], title: 'Recettes' },
            },
            {
                path: 'recettes/:id',
                name: 'EditionRecette',
                component: () => import('@/views/admin/EditionRecette.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer'], title: 'Édition recette' },
            },
            {
                path: 'lots',
                name: 'Lots',
                component: () => import('@/views/admin/GestionLots.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer'], title: 'Lots de production' },
            },
            {
                path: 'lots/nouveau',
                name: 'CreationLot',
                component: () => import('@/views/admin/CreationLot.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer'], title: 'Nouveau lot' },
            },
            {
                path: 'lots/:id',
                name: 'FicheLot',
                component: () => import('@/views/admin/FicheLot.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer'], title: 'Fiche lot' },
            },
        ],
    },

    // ── PWA Production mobile — ProductionLayout ───────────────
    // Accessible aux admin, organizer et producer
    {
        path: '/production',
        component: () => import('@/layout/ProductionLayout.vue'),
        meta: { requiresAuth: true },
        children: [
            {
                path: '',
                name: 'ProductionAccueil',
                component: () => import('@/views/production/ProductionAccueil.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer', 'producer'], title: 'Suivi de production' },
            },
            {
                path: 'lot/:id/demarrer',
                name: 'ProductionDemarrage',
                component: () => import('@/views/production/ProductionDemarrage.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer', 'producer'], title: 'Démarrer le lot' },
            },
            {
                path: 'lot/:id',
                name: 'ProductionPesee',
                component: () => import('@/views/production/ProductionPesee.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer', 'producer'], title: 'Relevé de pesée' },
            },
            {
                path: 'lot/:id/historique',
                name: 'ProductionHistorique',
                component: () => import('@/views/production/ProductionHistorique.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer', 'producer'], title: 'Historique des pesées' },
            },
            {
                path: 'lot/:id/stocker',
                name: 'ProductionStock',
                component: () => import('@/views/production/ProductionStock.vue'),
                meta: { requiresAuth: true, roles: ['admin', 'organizer', 'producer'], title: 'Mise en stock' },
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