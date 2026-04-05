<script setup>
/**
 * ProductionHistorique.vue — PWA mobile /crufiture/production/lot/:id/historique
 *
 * Affiche la liste complète des relevés d'un lot.
 * Lecture seule — bouton retour vers la pesée.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';

const route  = useRoute();
const router = useRouter();
const toast  = useToast();

const lotId   = computed(() => Number(route.params.id));
const lot     = ref(null);
const loading = ref(true);

const ensoleillementLabel = (val) => {
    if (val === null || val === undefined) return null;
    return ['☁️', '🌥', '⛅', '☀️'][val] ?? null;
};

const charger = async () => {
    loading.value = true;
    try {
        const res = await axiosCrufiture.get('/lots/' + lotId.value);
        if (res.data.status !== 'success') {
            router.push('/production');
            return;
        }
        lot.value = res.data.details;
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger le lot.', life: 4000 });
        router.push('/production');
    } finally {
        loading.value = false;
    }
};

onMounted(charger);
</script>

<template>
<div class="pwa-page">

    <div v-if="loading" class="pwa-loading">
        <i class="pi pi-spin pi-spinner" style="font-size:28px;color:#6dbf5a"></i>
    </div>

    <template v-else-if="lot">

        <!-- En-tête -->
        <div class="hist-header">
            <span class="hist-lot-num">{{ lot.numero_lot }}</span>
            <span class="hist-lot-saveur">{{ lot.saveur_nom }}</span>
            <span class="hist-cible">Cible : {{ lot.cible_kg ? Number(lot.cible_kg).toFixed(3) + ' kg' : '—' }}</span>
        </div>

        <!-- Vide -->
        <div v-if="!lot.releves?.length" class="hist-vide">
            Aucun relevé enregistré pour ce lot.
        </div>

        <!-- Liste des relevés — du plus récent au plus ancien -->
        <div
            v-for="(releve, idx) in [...(lot.releves || [])].reverse()"
            :key="releve.id"
            class="hist-releve-card"
            :class="{
                'hist-releve-card--ok':     releve.reste_evap_kg <= 0,
                'hist-releve-card--recent': idx === 0,
            }"
        >
            <div class="hist-releve-top">
                <span class="hist-heure">{{ releve.heure.slice(0,5) }}</span>
                <span
                    class="hist-poids"
                    :class="{ 'hist-poids--ok': releve.reste_evap_kg <= 0 }"
                >
                    {{ releve.poids_brut_kg.toFixed(3) }} kg
                </span>
            </div>

            <div class="hist-releve-mid">
                <span class="hist-reste" :class="{ 'hist-reste--ok': releve.reste_evap_kg <= 0 }">
                    <template v-if="releve.reste_evap_kg <= 0">✓ Cible atteinte</template>
                    <template v-else>Reste {{ releve.reste_evap_kg.toFixed(3) }} kg</template>
                </span>
                <span v-if="idx === 0" class="hist-badge-recent">Dernier</span>
            </div>

            <!-- Météo si disponible -->
            <div v-if="releve.temperature || releve.humidite || releve.vent_kmh || releve.ensoleillement !== null" class="hist-meteo">
                <span v-if="releve.ensoleillement !== null">{{ ensoleillementLabel(releve.ensoleillement) }}</span>
                <span v-if="releve.temperature">{{ releve.temperature }}°C</span>
                <span v-if="releve.humidite">{{ releve.humidite }}%HR</span>
                <span v-if="releve.vent_kmh">{{ releve.vent_kmh }} km/h</span>
            </div>

            <div v-if="releve.remarque" class="hist-remarque">{{ releve.remarque }}</div>
        </div>

        <!-- Bouton retour pesée -->
        <button class="hist-btn-retour" @click="router.push('/production/lot/' + lotId)">
            <i class="pi pi-arrow-left"></i>
            Retour aux pesées
        </button>

    </template>
</div>
</template>

<style scoped>
.pwa-page { padding: 16px; padding-bottom: 40px; }
.pwa-loading { display: flex; justify-content: center; padding: 60px 0; }

.hist-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
}
.hist-lot-num    { font-size: 20px; font-weight: 800; color: #d4f5c4; }
.hist-lot-saveur { font-size: 14px; color: #9ac880; flex: 1; }
.hist-cible      { font-size: 12px; color: #5a7a50; width: 100%; }

.hist-vide { text-align: center; color: #4a6a40; font-size: 14px; padding: 40px 0; }

.hist-releve-card {
    background: #141f14;
    border: 1px solid #1e2e1e;
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 8px;
}
.hist-releve-card--ok     { border-color: #2a5a2a; background: #0f2a0f; }
.hist-releve-card--recent { border-color: #3a4a3a; }

.hist-releve-top {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 4px;
}
.hist-heure { font-size: 14px; color: #5a7a50; font-variant-numeric: tabular-nums; }
.hist-poids { font-size: 20px; font-weight: 800; color: #f5b84a; font-variant-numeric: tabular-nums; }
.hist-poids--ok { color: #6dbf5a; }

.hist-releve-mid {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.hist-reste     { font-size: 13px; color: #6a8a60; }
.hist-reste--ok { color: #6dbf5a; font-weight: 600; }
.hist-badge-recent {
    font-size: 10px;
    font-weight: 700;
    background: #2a4a2a;
    color: #6dbf5a;
    padding: 2px 8px;
    border-radius: 10px;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.hist-meteo {
    display: flex;
    gap: 10px;
    margin-top: 6px;
    font-size: 12px;
    color: #4a6a40;
    flex-wrap: wrap;
}
.hist-remarque {
    font-size: 12px;
    color: #4a6a40;
    margin-top: 4px;
    font-style: italic;
}

.hist-btn-retour {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 14px;
    background: #1a2e1a;
    border: 1px solid #2a3f2a;
    border-radius: 12px;
    color: #6a8a60;
    font-size: 15px;
    cursor: pointer;
    margin-top: 16px;
    -webkit-tap-highlight-color: transparent;
}
.hist-btn-retour:active { background: #213221; }
</style>
