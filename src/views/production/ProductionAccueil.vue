<script setup>
/**
 * ProductionAccueil.vue — PWA mobile /crufiture/production
 *
 * Liste les lots en_repos et en production.
 * Card par lot : numéro, saveur, statut, progression (si production).
 * Tap en_repos  → /production/lot/:id/demarrer
 * Tap production → /production/lot/:id
 */
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';

const router = useRouter();
const toast  = useToast();

const lots    = ref([]);
const loading = ref(true);

const charger = async () => {
    loading.value = true;
    try {
        const res = await axiosCrufiture.get('/lots/suivi');
        lots.value = res.data.details || [];
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger les lots.', life: 4000 });
    } finally {
        loading.value = false;
    }
};

onMounted(charger);

// Progression évaporation en %
const progression = (lot) => {
    if (!lot.dernier_poids_net || !lot.masse_totale_kg || !lot.evaporation_kg) return null;
    const evaporee = lot.masse_totale_kg - lot.dernier_poids_net;
    const pct = Math.round(evaporee / lot.evaporation_kg * 100);
    return Math.min(100, Math.max(0, pct));
};

const cibleAtteinte = (lot) => {
    if (!lot.dernier_poids_net || !lot.cible_kg) return false;
    return lot.dernier_poids_net <= lot.cible_kg;
};

const allerVersLot = (lot) => {
    if (lot.statut === 'en_repos') {
        router.push('/production/lot/' + lot.id + '/demarrer');
    } else {
        router.push('/production/lot/' + lot.id);
    }
};
</script>

<template>
<div class="pwa-page">

    <!-- Chargement -->
    <div v-if="loading" class="pwa-loading">
        <i class="pi pi-spin pi-spinner" style="font-size:28px;color:#6dbf5a"></i>
    </div>

    <!-- Vide -->
    <div v-else-if="!lots.length" class="pwa-vide">
        <div class="pwa-vide-icone">🌿</div>
        <div class="pwa-vide-titre">Aucun lot en cours</div>
        <div class="pwa-vide-sub">Les lots en repos ou en production apparaîtront ici.</div>
    </div>

    <!-- Liste des lots -->
    <template v-else>
        <div class="pwa-section-label">{{ lots.length }} lot{{ lots.length > 1 ? 's' : '' }} en cours</div>

        <div
            v-for="lot in lots"
            :key="lot.id"
            class="pwa-lot-card"
            :class="'pwa-lot-card--' + lot.statut.replace('é','e')"
            @click="allerVersLot(lot)"
        >
            <!-- En-tête de la card -->
            <div class="pwa-lot-header">
                <div class="pwa-lot-num">{{ lot.numero_lot }}</div>
                <div class="pwa-lot-badge" :class="'badge--' + lot.statut.replace('é','e')">
                    {{ lot.statut === 'en_repos' ? 'En repos' : 'Production' }}
                </div>
            </div>

            <!-- Saveur -->
            <div class="pwa-lot-saveur">{{ lot.saveur_nom }}</div>

            <!-- Lot en repos — invite à démarrer -->
            <div v-if="lot.statut === 'en_repos'" class="pwa-lot-action">
                <i class="pi pi-play-circle"></i>
                Appuyer pour démarrer les pesées
            </div>

            <!-- Lot en production — progression -->
            <template v-else>
                <!-- Dernier relevé -->
                <div v-if="lot.dernier_poids_net" class="pwa-lot-releve">
                    <span class="pwa-lot-releve-val"
                        :class="{ 'pwa-lot-releve-ok': cibleAtteinte(lot) }">
                        {{ lot.dernier_poids_net.toFixed(3) }} kg
                    </span>
                    <span class="pwa-lot-releve-label">
                        {{ cibleAtteinte(lot) ? '✓ Cible atteinte' : 'dernier net' }}
                        <template v-if="lot.dernier_releve_heure">
                            · {{ lot.dernier_releve_heure.slice(0,5) }}
                        </template>
                    </span>
                </div>

                <!-- Barre de progression -->
                <div v-if="progression(lot) !== null" class="pwa-lot-prog-wrap">
                    <div class="pwa-lot-prog-bar">
                        <div
                            class="pwa-lot-prog-fill"
                            :class="{ 'pwa-lot-prog-fill--ok': cibleAtteinte(lot) }"
                            :style="{ width: progression(lot) + '%' }"
                        ></div>
                    </div>
                    <div class="pwa-lot-prog-pct">{{ progression(lot) }}%</div>
                </div>

                <div v-else class="pwa-lot-action">
                    <i class="pi pi-plus-circle"></i>
                    Appuyer pour saisir un relevé
                </div>

                <!-- Cible -->
                <div v-if="lot.cible_kg" class="pwa-lot-cible">
                    Cible : <strong>{{ Number(lot.cible_kg).toFixed(3) }} kg</strong>
                </div>
            </template>

            <!-- Chevron -->
            <div class="pwa-lot-chevron"><i class="pi pi-chevron-right"></i></div>
        </div>
    </template>

    <!-- Bouton rafraîchir -->
    <div class="pwa-refresh">
        <button class="pwa-refresh-btn" @click="charger" :disabled="loading">
            <i class="pi pi-refresh" :class="{ 'pi-spin': loading }"></i>
            Actualiser
        </button>
    </div>

</div>
</template>

<style scoped>
.pwa-page {
    padding: 16px;
    padding-bottom: 32px;
    min-height: 100%;
}

/* Chargement / vide */
.pwa-loading {
    display: flex;
    justify-content: center;
    padding: 60px 0;
}
.pwa-vide {
    text-align: center;
    padding: 60px 20px;
}
.pwa-vide-icone { font-size: 52px; margin-bottom: 12px; }
.pwa-vide-titre { font-size: 18px; font-weight: 700; color: #d4f5c4; margin-bottom: 6px; }
.pwa-vide-sub   { font-size: 14px; color: #6a8a60; }

/* Label section */
.pwa-section-label {
    font-size: 12px;
    font-weight: 600;
    color: #5a7a50;
    text-transform: uppercase;
    letter-spacing: .6px;
    margin-bottom: 12px;
}

/* Cards lots */
.pwa-lot-card {
    background: #1a2e1a;
    border: 1.5px solid #2a3f2a;
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 12px;
    cursor: pointer;
    position: relative;
    transition: background .15s;
    -webkit-tap-highlight-color: transparent;
    user-select: none;
}
.pwa-lot-card:active { background: #213221; }

.pwa-lot-card--en_repos { border-color: #2a4a6a; background: #111e2e; }
.pwa-lot-card--production { border-color: #5a3a0a; background: #1e1a0e; }

.pwa-lot-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
}
.pwa-lot-num {
    font-size: 20px;
    font-weight: 800;
    color: #d4f5c4;
    letter-spacing: .5px;
    font-variant-numeric: tabular-nums;
}
.pwa-lot-badge {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.badge--en_repos   { background: #1a3a5a; color: #7ab8f5; }
.badge--production { background: #3a2a0a; color: #f5b84a; }

.pwa-lot-saveur {
    font-size: 15px;
    color: #9ac880;
    margin-bottom: 10px;
    font-weight: 500;
}

/* Relevé */
.pwa-lot-releve {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin-bottom: 8px;
}
.pwa-lot-releve-val {
    font-size: 24px;
    font-weight: 800;
    color: #f5b84a;
    font-variant-numeric: tabular-nums;
}
.pwa-lot-releve-val.pwa-lot-releve-ok { color: #6dbf5a; }
.pwa-lot-releve-label {
    font-size: 12px;
    color: #6a8a60;
}

/* Barre de progression */
.pwa-lot-prog-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 6px;
}
.pwa-lot-prog-bar {
    flex: 1;
    height: 8px;
    background: #2a3a2a;
    border-radius: 4px;
    overflow: hidden;
}
.pwa-lot-prog-fill {
    height: 100%;
    background: #f5b84a;
    border-radius: 4px;
    transition: width .3s;
}
.pwa-lot-prog-fill--ok { background: #6dbf5a; }
.pwa-lot-prog-pct {
    font-size: 13px;
    font-weight: 700;
    color: #9ac880;
    min-width: 36px;
    text-align: right;
}

.pwa-lot-cible {
    font-size: 12px;
    color: #5a7a50;
    margin-top: 4px;
}

/* Action invite */
.pwa-lot-action {
    font-size: 13px;
    color: #5a7a50;
    display: flex;
    align-items: center;
    gap: 6px;
}
.pwa-lot-action .pi { font-size: 15px; color: #4a9a3a; }

/* Chevron */
.pwa-lot-chevron {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #3a5a3a;
    font-size: 13px;
}

/* Bouton rafraîchir */
.pwa-refresh {
    text-align: center;
    margin-top: 20px;
}
.pwa-refresh-btn {
    background: none;
    border: 1px solid #2a3f2a;
    color: #5a7a50;
    border-radius: 20px;
    padding: 8px 20px;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    -webkit-tap-highlight-color: transparent;
}
.pwa-refresh-btn:disabled { opacity: .5; }
</style>
