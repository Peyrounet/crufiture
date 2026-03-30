import { defineStore } from 'pinia';

export const useUserStore = defineStore('user', {
    state: () => ({
        user: null,
    }),

    getters: {
        userRoles: (state) => {
            if (!state.user) return [];
            const roles = [];
            if (state.user.is_admin)     roles.push('admin');
            if (state.user.is_organizer) roles.push('organizer');
            if (state.user.is_producer)  roles.push('producer');
            return roles;
        },
        isAdmin:     (state) => !!state.user?.is_admin,
        isOrganizer: (state) => !!state.user?.is_organizer,
        isProducer:  (state) => !!state.user?.is_producer,
    },

    actions: {
        setUser(user) {
            this.user = user;
        },
        clearUser() {
            this.user = null;
        },
    },
});
