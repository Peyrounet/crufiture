<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/authStore';

const authStore = useAuthStore();
const route     = useRoute();
const router    = useRouter();

// Titre dynamique selon la route
const titre = computed(() => {
    if (route.name === 'ProductionAccueil')    return 'Suivi de production';
    if (route.name === 'ProductionDemarrage')  return 'Démarrer le lot';
    if (route.name === 'ProductionPesee')      return 'Relevé de pesée';
    if (route.name === 'ProductionHistorique') return 'Historique';
    if (route.name === 'ProductionStock')      return 'Mise en stock';
    return 'Production';
});

// Afficher le bouton retour sur toutes les routes sauf l'accueil
const showBack = computed(() => route.name !== 'ProductionAccueil');

const retour = () => router.back();
</script>

<template>
    <div class="prod-layout">

        <div class="prod-header">
            <!-- Bouton retour -->
            <button v-if="showBack" class="prod-header-btn" @click="retour" aria-label="Retour">
                <i class="pi pi-arrow-left"></i>
            </button>
            <div v-else class="prod-header-logo">🍇</div>

            <span class="prod-header-titre">{{ titre }}</span>

            <button class="prod-header-btn" @click="authStore.logout()" aria-label="Se déconnecter">
                <i class="pi pi-sign-out"></i>
            </button>
        </div>

        <div class="prod-content">
            <router-view />
        </div>

    </div>
    <Toast />
</template>

<style scoped>
*, *::before, *::after { box-sizing: border-box; }

.prod-layout {
    display: flex;
    flex-direction: column;
    height: 100vh;
    height: 100dvh; /* dynamic viewport height — évite les problèmes avec la barre d'URL mobile */
    background: #0f1b0f;
    overflow: hidden;
}

.prod-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: #0f1b0f;
    border-bottom: 1px solid rgba(255,255,255,.07);
    position: sticky;
    top: 0;
    z-index: 100;
    flex-shrink: 0;
    min-height: 52px;
}

.prod-header-titre {
    font-size: 16px;
    font-weight: 700;
    color: #d4f5c4;
    letter-spacing: .2px;
}

.prod-header-logo {
    font-size: 22px;
    width: 36px;
    text-align: center;
}

.prod-header-btn {
    width: 36px;
    height: 36px;
    border: none;
    background: rgba(255,255,255,.07);
    border-radius: 50%;
    color: #a0c890;
    font-size: 15px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .15s;
    -webkit-tap-highlight-color: transparent;
}

.prod-header-btn:active {
    background: rgba(255,255,255,.15);
}

/* Contenu scrollable — chaque vue gère son propre padding */
.prod-content {
    flex: 1;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}
</style>
