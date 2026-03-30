/**
 * authStore.js
 *
 * Appelle les routes d'auth de monpanier (/monpanier/api/login, /authenticate...).
 * Le cookie JWT est émis par monpanier sur le domaine peyrounet.com —
 * il est donc automatiquement partagé avec /foretfeerique/.
 */
import { defineStore } from 'pinia';
import axios from '@/plugins/axios';
import router from '@/router';
import { useUserStore } from '@/stores/userStore';
import { setCookie, getCookie, deleteCookie } from '@/utils/cookies';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: getCookie('token') || null,
    }),

    getters: {
        isAuthenticated: (state) => {
            const userStore = useUserStore();
            return !!state.token && !!userStore.user;
        },
    },

    actions: {
        // Connexion via monpanier/api/login
        async login(credentials) {
            const userStore = useUserStore();
            try {
                const response = await axios.post('/login', credentials);
                if (response.data.status === 'success') {
                    userStore.setUser(response.data.user);
                    this.token = response.data.token;
                    setCookie('token', this.token, response.data.tokenvalidity);
                    router.push('/dashboard');
                } else {
                    return response.data.message;
                }
            } catch (error) {
                return 'Erreur lors de la connexion. Veuillez réessayer.';
            }
        },

        // Déconnexion
        async logout() {
            const userStore = useUserStore();
            userStore.clearUser();
            this.token = null;
            deleteCookie('token');
            router.push('/login');
        },

        // Vérification du token JWT existant — appelé par le router guard
        // ⚠️ Ne redirige JAMAIS — c'est le router guard qui décide
        async authenticate() {
            const userStore = useUserStore();
            try {
                const response = await axios.post('/authenticate');
                if (response.data.status === 'success') {
                    userStore.setUser(response.data.user);
                    this.token = response.data.token;
                    setCookie('token', this.token, response.data.tokenvalidity);
                } else {
                    // Nettoyer sans rediriger — le guard s'en charge
                    userStore.clearUser();
                    this.token = null;
                    deleteCookie('token');
                }
            } catch (error) {
                // Idem — pas de redirection ici
                userStore.clearUser();
                this.token = null;
            }
        },
    },
});
