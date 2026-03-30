<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useLayout } from '@/layout/composables/layout';
import { useAuthStore } from '@/stores/authStore';
import { useUserStore } from '@/stores/userStore';
import axios from '@/plugins/axios';

const { onMenuToggle } = useLayout();
const authStore = useAuthStore();
const userStore = useUserStore();

const outsideClickListener = ref(null);
const topbarMenuActive     = ref(false);
const panier               = ref(null);

onMounted(async () => {
    bindOutsideClickListener();
    try {
        const response = await axios.get('/panier');
        if (response.data?.details) {
            panier.value = response.data.details;
        }
    } catch (e) {
        // Fallback silencieux
    }
});

onBeforeUnmount(() => {
    unbindOutsideClickListener();
});

const onTopBarMenuButton = () => { topbarMenuActive.value = !topbarMenuActive.value; };
const onLogoutClick      = async () => { await authStore.logout(); };
const goToFerme          = () => { window.location.href = '/ferme/dashboard'; };

const topbarMenuClasses = computed(() => ({
    'layout-topbar-menu-mobile-active': topbarMenuActive.value,
}));

const bindOutsideClickListener = () => {
    if (!outsideClickListener.value) {
        outsideClickListener.value = (event) => {
            if (isOutsideClicked(event)) {
                topbarMenuActive.value = false;
            }
        };
        document.addEventListener('click', outsideClickListener.value);
    }
};

const unbindOutsideClickListener = () => {
    if (outsideClickListener.value) {
        document.removeEventListener('click', outsideClickListener.value);
        outsideClickListener.value = null;
    }
};

const isOutsideClicked = (event) => {
    if (!topbarMenuActive.value) return;
    const menuEl   = document.querySelector('.layout-topbar-menu');
    const buttonEl = document.querySelector('.layout-topbar-menu-button');
    return !(
        menuEl?.isSameNode(event.target)   || menuEl?.contains(event.target) ||
        buttonEl?.isSameNode(event.target) || buttonEl?.contains(event.target)
    );
};
</script>

<template>
    <div class="layout-topbar">
        <button class="ml-0 mr-4 p-link layout-menu-button layout-topbar-button" @click="onMenuToggle()">
            <i class="pi pi-bars"></i>
        </button>

        <a href="/crufiture/dashboard" class="layout-topbar-logo">
            <span v-if="panier?.name">{{ panier.name }}</span>
            <span v-else>🫙 Crufiture</span>
        </a>

        <button class="p-link layout-topbar-menu-button layout-topbar-button" @click="onTopBarMenuButton()">
            <i class="pi pi-ellipsis-v"></i>
        </button>

        <div class="layout-topbar-menu" :class="topbarMenuClasses">
            <button @click="goToFerme()" class="p-link layout-topbar-button">
                <i class="pi pi-th-large"></i>
                <span>Portail</span>
            </button>
            <button @click="onLogoutClick()" class="p-link layout-topbar-button">
                <i class="pi pi-sign-out"></i>
                <span>Se déconnecter</span>
            </button>
        </div>
    </div>
</template>

<style lang="scss" scoped></style>
