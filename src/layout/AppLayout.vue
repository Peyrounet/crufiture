<script setup>
import { computed, watch, ref, onMounted } from 'vue';
import { usePrimeVue } from 'primevue/config';
import AppTopbar from './AppTopbar.vue';
import AppSidebar from './AppSidebar.vue';
import { useLayout } from '@/layout/composables/layout';
import { useUserStore } from '@/stores/userStore';
import axios from '@/plugins/axios';

const userStore = useUserStore();
const user      = computed(() => userStore.user);

const $primevue = usePrimeVue();
const { layoutConfig, layoutState, isSidebarActive } = useLayout();

const outsideClickListener = ref(null);

// Au montage — charger le thème réel depuis monpanier/api/panier
// et l'appliquer si différent du thème par défaut
onMounted(async () => {
    try {
        const response = await axios.get('/panier');
        const theme = response.data?.details?.theme;
        if (theme && theme !== layoutConfig.theme.value) {
            $primevue.changeTheme(layoutConfig.theme.value, theme, 'theme-css', () => {
                layoutConfig.theme.value = theme;
            });
        }
    } catch (e) {
        // Fallback silencieux — aura-light-amber reste actif
    }
});

watch(isSidebarActive, (newVal) => {
    if (newVal) bindOutsideClickListener();
    else unbindOutsideClickListener();
});

const containerClass = computed(() => ({
    'layout-theme-light':     layoutConfig.darkTheme.value === 'light',
    'layout-theme-dark':      layoutConfig.darkTheme.value === 'dark',
    'layout-overlay':         layoutConfig.menuMode.value === 'overlay',
    'layout-static':          layoutConfig.menuMode.value === 'static',
    'layout-static-inactive': layoutState.staticMenuDesktopInactive.value && layoutConfig.menuMode.value === 'static',
    'layout-overlay-active':  layoutState.overlayMenuActive.value,
    'layout-mobile-active':   layoutState.staticMenuMobileActive.value,
    'p-ripple-disabled':      layoutConfig.ripple.value === false,
}));

const bindOutsideClickListener = () => {
    if (!outsideClickListener.value) {
        outsideClickListener.value = (event) => {
            if (isOutsideClicked(event)) {
                layoutState.overlayMenuActive.value      = false;
                layoutState.staticMenuMobileActive.value = false;
                layoutState.menuHoverActive.value        = false;
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
    const sidebarEl = document.querySelector('.layout-sidebar');
    const topbarEl  = document.querySelector('.layout-menu-button');
    return !(
        sidebarEl?.isSameNode(event.target) || sidebarEl?.contains(event.target) ||
        topbarEl?.isSameNode(event.target)  || topbarEl?.contains(event.target)
    );
};
</script>

<template>
    <div class="layout-wrapper" :class="containerClass">
        <app-topbar />
        <div class="layout-sidebar">
            <app-sidebar />
        </div>
        <div class="layout-main-container">
            <div class="layout-main">
                <div class="grid">
                    <div class="col-12">
                        Bonjour, {{ user?.firstname }} {{ user?.lastname }}
                    </div>
                    <router-view />
                </div>
            </div>
        </div>
        <div class="layout-mask"></div>
    </div>
    <Toast />
</template>

<style lang="scss" scoped></style>
