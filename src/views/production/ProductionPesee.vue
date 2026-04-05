<script setup>
/**
 * ProductionPesee.vue — PWA mobile /crufiture/production/lot/:id
 *
 * Page principale de suivi d'un lot en production.
 * - Formulaire de saisie en haut (action principale)
 * - Résultat après validation (poids net, reste, cible)
 * - Dernier relevé + lien historique
 * - Abandon via menu caché (4 étapes)
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';

const route  = useRoute();
const router = useRouter();
const toast  = useToast();

const lotId   = computed(() => Number(route.params.id));
const lot     = ref(null);
const loading = ref(true);
const saving  = ref(false);

// ── Formulaire relevé ─────────────────────────────────────────
const form = reactive({
    heure:          new Date().toTimeString().slice(0, 5),
    poids_brut:     '',
    temperature:    '',
    humidite:       '',
    vent_kmh:       '',
    ensoleillement: null,
    remarque:       '',
});

// Dernier résultat affiché après validation
const resultat = ref(null);

const ensoleillementOptions = [
    { label: '☁️',  value: 0, title: 'Couvert'    },
    { label: '🌥',  value: 1, title: 'Voilé'      },
    { label: '⛅',  value: 2, title: 'Mi-ombre'   },
    { label: '☀️',  value: 3, title: 'Ensoleillé' },
];

// Poids net = brut - tare lot
const poidsNet = computed(() => {
    const b = parseFloat(form.poids_brut);
    const t = (lot.value?.tare_kg) ? parseFloat(lot.value.tare_kg) : 0;
    if (!b || b <= 0) return null;
    return Math.round((b - t) * 1000) / 1000;
});

const peutEnregistrer = computed(() =>
    poidsNet.value !== null && poidsNet.value > 0 && !saving.value
);

// Progression depuis le dernier relevé connu
const dernierReleve = computed(() => {
    if (!lot.value?.releves?.length) return null;
    return lot.value.releves[lot.value.releves.length - 1];
});

const progression = computed(() => {
    if (!dernierReleve.value || !lot.value?.masse_totale_kg || !lot.value?.evaporation_kg) return null;
    const evaporee = lot.value.masse_totale_kg - dernierReleve.value.poids_brut_kg;
    const pct = Math.round(evaporee / lot.value.evaporation_kg * 100);
    return Math.min(100, Math.max(0, pct));
});

// ── Abandon — 4 étapes ────────────────────────────────────────
const abandonEtape      = ref(0); // 0=caché 1=avertissement 2=note 3=confirmation numéro
const abandonNote       = ref('');
const abandonNumeroSaisi= ref('');
const abandonSaving     = ref(false);

const ouvrirAbandon = () => { abandonEtape.value = 1; };
const fermerAbandon = () => {
    abandonEtape.value       = 0;
    abandonNote.value        = '';
    abandonNumeroSaisi.value = '';
};
const abandonEtape2 = () => {
    if (!abandonNote.value.trim()) {
        toast.add({ severity: 'warn', summary: 'Note requise', detail: 'Expliquez la raison de l\'abandon.', life: 3000 });
        return;
    }
    abandonEtape.value = 3;
};
const confirmerAbandon = async () => {
    if (abandonNumeroSaisi.value !== lot.value?.numero_lot) {
        toast.add({ severity: 'error', summary: 'Numéro incorrect', detail: 'Tapez exactement le numéro du lot.', life: 3000 });
        return;
    }
    abandonSaving.value = true;
    try {
        const res = await axiosCrufiture.put('/lots/' + lotId.value + '/abandonner', {
            note: abandonNote.value,
        });
        if (res.data.status === 'success') {
            toast.add({ severity: 'info', summary: 'Lot abandonné', detail: 'Le lot a été abandonné.', life: 3000 });
            router.replace('/production');
        } else {
            toast.add({ severity: 'error', summary: 'Erreur', detail: res.data.message, life: 4000 });
        }
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Une erreur est survenue.', life: 4000 });
    } finally {
        abandonSaving.value = false;
    }
};

// ── Chargement ────────────────────────────────────────────────
const charger = async () => {
    loading.value = true;
    try {
        const res = await axiosCrufiture.get('/lots/' + lotId.value);
        if (res.data.status !== 'success') {
            router.push('/production');
            return;
        }
        lot.value = res.data.details;
        if (lot.value.statut === 'en_repos') {
            router.replace('/production/lot/' + lotId.value + '/demarrer');
            return;
        }
        if (lot.value.statut !== 'production') {
            router.replace('/production');
            return;
        }
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger le lot.', life: 4000 });
        router.push('/production');
    } finally {
        loading.value = false;
    }
};

onMounted(charger);

// ── Enregistrer un relevé ─────────────────────────────────────
const enregistrer = async () => {
    if (!peutEnregistrer.value) return;
    saving.value = true;
    resultat.value = null;
    try {
        const heureApi = form.heure.length === 5 ? form.heure + ':00' : form.heure;
        const res = await axiosCrufiture.post('/lots/' + lotId.value + '/releves', {
            heure:          heureApi,
            poids_brut_kg:  poidsNet.value,
            temperature:    form.temperature    !== '' ? parseFloat(form.temperature)    : null,
            humidite:       form.humidite        !== '' ? parseFloat(form.humidite)        : null,
            vent_kmh:       form.vent_kmh        !== '' ? parseFloat(form.vent_kmh)        : null,
            ensoleillement: form.ensoleillement  !== null ? form.ensoleillement             : null,
            remarque:       form.remarque        || null,
        });

        if (res.data.status !== 'success') {
            toast.add({ severity: 'error', summary: 'Erreur', detail: res.data.message, life: 4000 });
            return;
        }

        resultat.value = res.data.details;

        // Réinitialiser le formulaire (sauf heure et météo — souvent identiques)
        form.poids_brut = '';
        form.heure      = new Date().toTimeString().slice(0, 5);

        // Recharger pour mettre à jour le dernier relevé
        await charger();

    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible d\'enregistrer le relevé.', life: 4000 });
    } finally {
        saving.value = false;
    }
};
</script>

<template>
<div class="pwa-page">

    <!-- Chargement -->
    <div v-if="loading" class="pwa-loading">
        <i class="pi pi-spin pi-spinner" style="font-size:28px;color:#6dbf5a"></i>
    </div>

    <template v-else-if="lot">

        <!-- En-tête lot -->
        <div class="pes-lot-header">
            <div class="pes-lot-left">
                <div class="pes-lot-num">{{ lot.numero_lot }}</div>
                <div class="pes-lot-saveur">{{ lot.saveur_nom }}</div>
            </div>
            <div class="pes-lot-right">
                <div class="pes-cible-label">Cible</div>
                <div class="pes-cible-val">{{ lot.cible_kg ? Number(lot.cible_kg).toFixed(3) : '—' }} kg</div>
            </div>
        </div>

        <!-- Progression (si relevés existants) -->
        <div v-if="progression !== null" class="pes-prog-bloc">
            <div class="pes-prog-header">
                <span>Évaporation</span>
                <span>{{ progression }}%</span>
            </div>
            <div class="pes-prog-bar">
                <div
                    class="pes-prog-fill"
                    :class="{ 'pes-prog-fill--ok': dernierReleve?.reste_evap_kg <= 0 }"
                    :style="{ width: progression + '%' }"
                ></div>
            </div>
            <div class="pes-prog-sub" v-if="dernierReleve">
                Dernier : <strong>{{ dernierReleve.poids_brut_kg.toFixed(3) }} kg net</strong>
                · {{ dernierReleve.heure.slice(0,5) }}
                <template v-if="dernierReleve.reste_evap_kg <= 0">
                    <span class="pes-cible-ok">✓ Cible atteinte</span>
                </template>
                <template v-else>
                    · reste {{ dernierReleve.reste_evap_kg.toFixed(3) }} kg
                </template>
            </div>
        </div>

        <!-- ── Formulaire relevé (action principale) ──────────── -->
        <div class="pes-bloc">
            <div class="pes-bloc-titre">Nouveau relevé</div>

            <div class="pes-field">
                <label>Heure</label>
                <input type="time" v-model="form.heure" class="pes-input" />
            </div>

            <div class="pes-field">
                <label>Poids brut plateau <span class="pes-unit">kg</span></label>
                <div class="pes-input-suffix-wrap">
                    <input
                        type="number"
                        v-model="form.poids_brut"
                        class="pes-input pes-input-num pes-input-big"
                        placeholder="0.000"
                        step="0.001"
                        min="0"
                        inputmode="decimal"
                        autofocus
                    />
                    <span class="pes-input-suffix">kg</span>
                </div>
                <div class="pes-tare-info" v-if="lot.tare_kg">
                    Tare : {{ Number(lot.tare_kg).toFixed(3) }} kg (déduite automatiquement)
                </div>
            </div>

            <!-- Météo — ligne compacte -->
            <div class="pes-meteo-row">
                <!-- Ensoleillement -->
                <div class="pes-ensol-group">
                    <button
                        v-for="opt in ensoleillementOptions"
                        :key="opt.value"
                        class="pes-ensol-btn"
                        :class="{ 'pes-ensol-btn--active': form.ensoleillement === opt.value }"
                        :title="opt.title"
                        type="button"
                        @click="form.ensoleillement = form.ensoleillement === opt.value ? null : opt.value"
                    >{{ opt.label }}</button>
                </div>

                <input
                    type="number"
                    v-model="form.temperature"
                    class="pes-input pes-input-num pes-input-meteo"
                    placeholder="°C"
                    step="0.1"
                    inputmode="decimal"
                />
                <input
                    type="number"
                    v-model="form.humidite"
                    class="pes-input pes-input-num pes-input-meteo"
                    placeholder="%HR"
                    step="1" min="0" max="100"
                    inputmode="decimal"
                />
                <input
                    type="number"
                    v-model="form.vent_kmh"
                    class="pes-input pes-input-num pes-input-meteo"
                    placeholder="km/h"
                    step="1" min="0"
                    inputmode="decimal"
                />
            </div>

            <!-- Bouton enregistrer -->
            <button
                class="pes-btn-enregistrer"
                :class="{ 'pes-btn--disabled': !peutEnregistrer }"
                :disabled="!peutEnregistrer"
                @click="enregistrer"
            >
                <i v-if="saving" class="pi pi-spin pi-spinner"></i>
                <i v-else class="pi pi-check"></i>
                {{ saving ? 'Enregistrement…' : 'Enregistrer le relevé' }}
            </button>
        </div>

        <!-- ── Résultat du dernier enregistrement ─────────────── -->
        <div v-if="resultat" class="pes-resultat" :class="{ 'pes-resultat--ok': resultat.cible_atteinte }">
            <div class="pes-resultat-row">
                <span class="pes-resultat-label">Poids net</span>
                <span class="pes-resultat-val">{{ resultat.poids_net.toFixed(3) }} kg</span>
            </div>
            <div class="pes-resultat-row">
                <span class="pes-resultat-label">Reste à évaporer</span>
                <span class="pes-resultat-val" :class="{ 'pes-val-ok': resultat.cible_atteinte }">
                    {{ resultat.reste_evap_kg.toFixed(3) }} kg
                </span>
            </div>
            <div v-if="resultat.cible_atteinte" class="pes-resultat-cible">
                ✓ Poids cible atteint — vous pouvez mettre en stock
            </div>
        </div>

        <!-- ── Bouton mise en stock (si cible atteinte) ───────── -->
        <button
            v-if="dernierReleve && dernierReleve.reste_evap_kg <= 0"
            class="pes-btn-stock"
            @click="router.push('/production/lot/' + lotId + '/stocker')"
        >
            <i class="pi pi-inbox"></i>
            Mettre en stock
        </button>

        <!-- ── Historique ─────────────────────────────────────── -->
        <div class="pes-historique-link" v-if="lot.releves?.length">
            <button class="pes-link-btn" @click="router.push('/production/lot/' + lotId + '/historique')">
                <i class="pi pi-list"></i>
                Voir les {{ lot.releves.length }} relevés
            </button>
        </div>

        <!-- ── Menu abandon (caché) ───────────────────────────── -->
        <div class="pes-abandon-zone">
            <button class="pes-abandon-trigger" @click="ouvrirAbandon">
                ··· Abandonner ce lot
            </button>
        </div>

        <!-- ── Dialog abandon — étape 1 : avertissement ──────── -->
        <div v-if="abandonEtape >= 1" class="pes-overlay" @click.self="fermerAbandon">
            <div class="pes-dialog">

                <!-- Étape 1 : avertissement -->
                <template v-if="abandonEtape === 1">
                    <div class="pes-dialog-titre danger">⚠️ Abandonner le lot ?</div>
                    <p class="pes-dialog-texte">
                        Vous êtes sur le point d'abandonner le lot <strong>{{ lot.numero_lot }}</strong>.
                        Cette action est <strong>irréversible</strong>.
                        Toutes les données sont conservées pour la traçabilité.
                    </p>
                    <div class="pes-dialog-actions">
                        <button class="pes-dialog-btn pes-dialog-btn--cancel" @click="fermerAbandon">Annuler</button>
                        <button class="pes-dialog-btn pes-dialog-btn--danger" @click="abandonEtape = 2">Continuer</button>
                    </div>
                </template>

                <!-- Étape 2 : note obligatoire -->
                <template v-else-if="abandonEtape === 2">
                    <div class="pes-dialog-titre danger">Raison de l'abandon</div>
                    <p class="pes-dialog-texte">Expliquez pourquoi ce lot est abandonné.</p>
                    <textarea
                        v-model="abandonNote"
                        class="pes-dialog-textarea"
                        placeholder="Ex : Contamination, erreur de formule, panne…"
                        rows="4"
                        autofocus
                    ></textarea>
                    <div class="pes-dialog-actions">
                        <button class="pes-dialog-btn pes-dialog-btn--cancel" @click="fermerAbandon">Annuler</button>
                        <button class="pes-dialog-btn pes-dialog-btn--danger" @click="abandonEtape2">Suivant</button>
                    </div>
                </template>

                <!-- Étape 3 : confirmation par saisie du numéro de lot -->
                <template v-else-if="abandonEtape === 3">
                    <div class="pes-dialog-titre danger">Confirmation finale</div>
                    <p class="pes-dialog-texte">
                        Pour confirmer, tapez le numéro du lot :
                        <strong class="pes-dialog-numero">{{ lot.numero_lot }}</strong>
                    </p>
                    <input
                        type="text"
                        v-model="abandonNumeroSaisi"
                        class="pes-dialog-input"
                        placeholder="Numéro du lot"
                        inputmode="numeric"
                        autocomplete="off"
                    />
                    <div class="pes-dialog-actions">
                        <button class="pes-dialog-btn pes-dialog-btn--cancel" @click="fermerAbandon">Annuler</button>
                        <button
                            class="pes-dialog-btn pes-dialog-btn--danger"
                            :disabled="abandonNumeroSaisi !== lot.numero_lot || abandonSaving"
                            @click="confirmerAbandon"
                        >
                            <i v-if="abandonSaving" class="pi pi-spin pi-spinner"></i>
                            Abandonner définitivement
                        </button>
                    </div>
                </template>

            </div>
        </div>

    </template>
</div>
</template>

<style scoped>
.pwa-page { padding: 16px; padding-bottom: 40px; }
.pwa-loading { display: flex; justify-content: center; padding: 60px 0; }

/* En-tête */
.pes-lot-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    background: #1a2e1a;
    border: 1px solid #2a3f2a;
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 12px;
}
.pes-lot-num    { font-size: 22px; font-weight: 800; color: #d4f5c4; }
.pes-lot-saveur { font-size: 14px; color: #9ac880; margin-top: 2px; }
.pes-lot-right  { text-align: right; }
.pes-cible-label{ font-size: 11px; color: #5a7a50; text-transform: uppercase; letter-spacing: .4px; }
.pes-cible-val  { font-size: 20px; font-weight: 800; color: #f5b84a; font-variant-numeric: tabular-nums; }

/* Progression */
.pes-prog-bloc {
    background: #141f14;
    border: 1px solid #1e2e1e;
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 12px;
}
.pes-prog-header {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    font-weight: 600;
    color: #6a8a60;
    margin-bottom: 6px;
}
.pes-prog-bar {
    height: 8px;
    background: #2a3a2a;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 6px;
}
.pes-prog-fill {
    height: 100%;
    background: #f5b84a;
    border-radius: 4px;
    transition: width .3s;
}
.pes-prog-fill--ok { background: #6dbf5a; }
.pes-prog-sub {
    font-size: 12px;
    color: #5a7a50;
}
.pes-cible-ok { color: #6dbf5a; font-weight: 700; margin-left: 4px; }

/* Blocs */
.pes-bloc {
    background: #141f14;
    border: 1px solid #1e2e1e;
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 12px;
}
.pes-bloc-titre {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: #5a7a50;
    margin-bottom: 12px;
}

/* Champs */
.pes-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
.pes-field:last-child { margin-bottom: 0; }
.pes-field label { font-size: 13px; font-weight: 600; color: #7aaa60; }
.pes-unit { font-weight: 400; color: #4a6a40; font-size: 11px; }

.pes-input {
    background: #0d1a0d;
    border: 1.5px solid #2a3f2a;
    border-radius: 10px;
    color: #d4f5c4;
    font-size: 16px;
    padding: 12px 14px;
    width: 100%;
    outline: none;
    -webkit-appearance: none;
    box-sizing: border-box;
}
.pes-input:focus { border-color: #4a9a3a; }
.pes-input::placeholder { color: #2a4a2a; }
.pes-input-num { font-variant-numeric: tabular-nums; }
.pes-input-big { font-size: 22px; font-weight: 700; padding: 14px; }

.pes-input-suffix-wrap { position: relative; display: flex; align-items: center; }
.pes-input-suffix-wrap .pes-input { padding-right: 42px; }
.pes-input-suffix { position: absolute; right: 14px; font-size: 13px; color: #4a6a40; pointer-events: none; }

.pes-tare-info { font-size: 11px; color: #3a5a3a; margin-top: 2px; }

/* Météo compact */
.pes-meteo-row {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.pes-ensol-group {
    display: flex;
    gap: 4px;
}
.pes-ensol-btn {
    width: 36px;
    height: 36px;
    background: #0d1a0d;
    border: 1.5px solid #2a3f2a;
    border-radius: 8px;
    font-size: 16px;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    display: flex;
    align-items: center;
    justify-content: center;
}
.pes-ensol-btn--active { border-color: #4a9a3a; background: #1a3f1a; }
.pes-input-meteo {
    width: 72px;
    padding: 8px 10px;
    font-size: 14px;
    text-align: center;
}

/* Bouton enregistrer */
.pes-btn-enregistrer {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 16px;
    background: #2a6a1a;
    border: none;
    border-radius: 12px;
    color: #d4f5c4;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    transition: background .15s;
}
.pes-btn-enregistrer:active { background: #3a8a2a; }
.pes-btn--disabled { background: #1a2e1a; color: #3a5a3a; cursor: not-allowed; }

/* Résultat */
.pes-resultat {
    background: #141f14;
    border: 1px solid #2a3f2a;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 10px;
}
.pes-resultat--ok { border-color: #3a7a2a; background: #0f2a0f; }
.pes-resultat-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}
.pes-resultat-row:last-child { margin-bottom: 0; }
.pes-resultat-label { font-size: 13px; color: #5a7a50; }
.pes-resultat-val   { font-size: 18px; font-weight: 800; color: #d4f5c4; font-variant-numeric: tabular-nums; }
.pes-val-ok         { color: #6dbf5a; }
.pes-resultat-cible { font-size: 13px; color: #6dbf5a; font-weight: 600; margin-top: 8px; text-align: center; }

/* Bouton stock */
.pes-btn-stock {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 16px;
    background: #1a4a6a;
    border: none;
    border-radius: 12px;
    color: #7ab8f5;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    margin-bottom: 10px;
    -webkit-tap-highlight-color: transparent;
}
.pes-btn-stock:active { background: #2a5a8a; }

/* Historique */
.pes-historique-link { text-align: center; margin: 6px 0 16px; }
.pes-link-btn {
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

/* Zone abandon */
.pes-abandon-zone { text-align: center; margin-top: 20px; }
.pes-abandon-trigger {
    background: none;
    border: none;
    color: #3a3a3a;
    font-size: 12px;
    cursor: pointer;
    padding: 8px;
    -webkit-tap-highlight-color: transparent;
}

/* Dialog abandon */
.pes-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.75);
    z-index: 500;
    display: flex;
    align-items: flex-end;
    padding: 0;
}
.pes-dialog {
    background: #1a1a1a;
    border-radius: 20px 20px 0 0;
    padding: 24px 20px 40px;
    width: 100%;
    max-height: 85vh;
    overflow-y: auto;
}
.pes-dialog-titre {
    font-size: 18px;
    font-weight: 800;
    color: #d4f5c4;
    margin-bottom: 12px;
}
.pes-dialog-titre.danger { color: #f56a4a; }
.pes-dialog-texte {
    font-size: 14px;
    color: #8a8a8a;
    line-height: 1.6;
    margin-bottom: 16px;
}
.pes-dialog-texte strong { color: #d4d4d4; }
.pes-dialog-numero {
    display: block;
    font-size: 24px;
    color: #f56a4a;
    text-align: center;
    margin: 8px 0;
    letter-spacing: 2px;
}
.pes-dialog-textarea, .pes-dialog-input {
    width: 100%;
    background: #0d0d0d;
    border: 1.5px solid #3a3a3a;
    border-radius: 10px;
    color: #d4d4d4;
    font-size: 16px;
    padding: 12px;
    box-sizing: border-box;
    outline: none;
    resize: none;
    margin-bottom: 16px;
}
.pes-dialog-textarea:focus, .pes-dialog-input:focus { border-color: #f56a4a; }
.pes-dialog-actions {
    display: flex;
    gap: 10px;
}
.pes-dialog-btn {
    flex: 1;
    padding: 14px;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}
.pes-dialog-btn--cancel { background: #2a2a2a; color: #8a8a8a; }
.pes-dialog-btn--danger { background: #6a1a0a; color: #f5a090; }
.pes-dialog-btn--danger:disabled { background: #2a1a1a; color: #4a3a3a; cursor: not-allowed; }
</style>
