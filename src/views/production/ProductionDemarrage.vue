<script setup>
/**
 * ProductionDemarrage.vue — PWA mobile /crufiture/production/lot/:id/demarrer
 *
 * Formulaire de démarrage d'un lot en_repos.
 * Saisit : heure_debut, installation, tare_kg, poids brut initial + météo.
 * Actions :
 *   1. PUT /lots/:id/demarrer  (heure_debut, installation, tare_kg)
 *   2. POST /lots/:id/releves  (premier relevé = poids brut - tare)
 * Si annulé sans valider → le lot reste en_repos (aucun effet).
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

// ── Formulaire ────────────────────────────────────────────────
const form = reactive({
    heure_debut:  new Date().toTimeString().slice(0, 5), // HH:MM
    installation: '',
    tare_kg:      '',   // poids plaque à vide
    poids_brut:   '',   // poids brut initial (plateau + mélange)
    // Météo
    temperature:    '',
    humidite:       '',
    vent_kmh:       '',
    ensoleillement: null, // 0=couvert 1=voilé 2=mi-ombre 3=ensoleillé
    remarque:       '',
});

// Poids net = brut - tare
const poidsNet = computed(() => {
    const b = parseFloat(form.poids_brut);
    const t = parseFloat(form.tare_kg) || 0;
    if (!b || b <= 0) return null;
    return Math.round((b - t) * 1000) / 1000;
});

const ensoleillementOptions = [
    { label: '☁️ Couvert',   value: 0 },
    { label: '🌥 Voilé',     value: 1 },
    { label: '⛅ Mi-ombre',  value: 2 },
    { label: '☀️ Ensoleillé',value: 3 },
];

// Validation — tare et poids brut obligatoires
const peutDemarrer = computed(() =>
    parseFloat(form.tare_kg) >= 0 &&
    form.tare_kg !== '' &&
    parseFloat(form.poids_brut) > 0 &&
    poidsNet.value !== null &&
    poidsNet.value > 0
);

// ── Chargement du lot ─────────────────────────────────────────
const charger = async () => {
    loading.value = true;
    try {
        const res = await axiosCrufiture.get('/lots/' + lotId.value);
        if (res.data.status !== 'success') {
            toast.add({ severity: 'error', summary: 'Erreur', detail: 'Lot introuvable.', life: 4000 });
            router.push('/production');
            return;
        }
        lot.value = res.data.details;
        if (lot.value.statut !== 'en_repos') {
            // Déjà démarré — aller directement à la pesée
            router.replace('/production/lot/' + lotId.value);
            return;
        }
        // Pré-remplir installation si déjà renseignée
        if (lot.value.installation) form.installation = lot.value.installation;
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger le lot.', life: 4000 });
        router.push('/production');
    } finally {
        loading.value = false;
    }
};

onMounted(charger);

// ── Démarrage ─────────────────────────────────────────────────
const demarrer = async () => {
    if (!peutDemarrer.value) return;
    saving.value = true;
    try {
        // 1. Passer en production
        const heureApi = form.heure_debut.length === 5 ? form.heure_debut + ':00' : form.heure_debut;
        const resDem = await axiosCrufiture.put('/lots/' + lotId.value + '/demarrer', {
            heure_debut:  heureApi,
            installation: form.installation || null,
            tare_kg:      parseFloat(form.tare_kg),
        });

        if (resDem.data.status !== 'success') {
            toast.add({ severity: 'error', summary: 'Erreur', detail: resDem.data.message, life: 4000 });
            return;
        }

        // 2. Enregistrer le premier relevé (poids net = brut - tare)
        const resRel = await axiosCrufiture.post('/lots/' + lotId.value + '/releves', {
            heure:         heureApi,
            poids_brut_kg: poidsNet.value,
            temperature:   form.temperature   !== '' ? parseFloat(form.temperature)   : null,
            humidite:      form.humidite       !== '' ? parseFloat(form.humidite)       : null,
            vent_kmh:      form.vent_kmh       !== '' ? parseFloat(form.vent_kmh)       : null,
            ensoleillement:form.ensoleillement !== null ? form.ensoleillement            : null,
            remarque:      form.remarque       || null,
        });

        if (resRel.data.status !== 'success') {
            // Le démarrage a réussi, mais le premier relevé a échoué
            toast.add({ severity: 'warn', summary: 'Démarré', detail: 'Production démarrée — le premier relevé n\'a pas pu être enregistré.', life: 5000 });
        } else {
            toast.add({ severity: 'success', summary: 'Production démarrée', detail: 'Premier relevé enregistré.', life: 3000 });
        }

        router.replace('/production/lot/' + lotId.value);

    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Une erreur est survenue.', life: 4000 });
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
        <div class="dem-lot-header">
            <div class="dem-lot-num">{{ lot.numero_lot }}</div>
            <div class="dem-lot-saveur">{{ lot.saveur_nom }}</div>
            <div class="dem-lot-info">
                Cible : <strong>{{ lot.cible_kg ? Number(lot.cible_kg).toFixed(3) + ' kg' : '—' }}</strong>
                · À évaporer : <strong>{{ lot.evaporation_kg ? Number(lot.evaporation_kg).toFixed(3) + ' kg' : '—' }}</strong>
            </div>
        </div>

        <!-- ── Bloc 1 : Contexte de démarrage ─────────────────── -->
        <div class="dem-bloc">
            <div class="dem-bloc-titre">Démarrage</div>

            <div class="dem-field">
                <label>Heure de pose <span class="dem-req">*</span></label>
                <input type="time" v-model="form.heure_debut" class="dem-input" />
            </div>

            <div class="dem-field">
                <label>Installation</label>
                <input
                    type="text"
                    v-model="form.installation"
                    class="dem-input"
                    placeholder="ex: Inox, Plastique…"
                    autocomplete="off"
                />
            </div>
        </div>

        <!-- ── Bloc 2 : Tare + pesée initiale ────────────────── -->
        <div class="dem-bloc">
            <div class="dem-bloc-titre">Pesée initiale</div>
            <p class="dem-bloc-hint">Pesez d'abord la plaque à vide, puis posez le mélange et pesez à nouveau.</p>

            <div class="dem-field">
                <label>Tare plaque (à vide) <span class="dem-req">*</span></label>
                <div class="dem-input-suffix-wrap">
                    <input
                        type="number"
                        v-model="form.tare_kg"
                        class="dem-input dem-input-num"
                        placeholder="0.000"
                        step="0.001"
                        min="0"
                        inputmode="decimal"
                    />
                    <span class="dem-input-suffix">kg</span>
                </div>
            </div>

            <div class="dem-field">
                <label>Poids brut (plateau + mélange) <span class="dem-req">*</span></label>
                <div class="dem-input-suffix-wrap">
                    <input
                        type="number"
                        v-model="form.poids_brut"
                        class="dem-input dem-input-num"
                        placeholder="0.000"
                        step="0.001"
                        min="0"
                        inputmode="decimal"
                    />
                    <span class="dem-input-suffix">kg</span>
                </div>
            </div>

            <!-- Résultat net -->
            <div v-if="poidsNet !== null" class="dem-net">
                <span class="dem-net-label">Poids net</span>
                <span class="dem-net-val">{{ poidsNet.toFixed(3) }} kg</span>
            </div>
        </div>

        <!-- ── Bloc 3 : Météo ─────────────────────────────────── -->
        <div class="dem-bloc">
            <div class="dem-bloc-titre">Météo</div>

            <!-- Ensoleillement — boutons -->
            <div class="dem-field">
                <label>Ensoleillement</label>
                <div class="dem-ensol-grid">
                    <button
                        v-for="opt in ensoleillementOptions"
                        :key="opt.value"
                        class="dem-ensol-btn"
                        :class="{ 'dem-ensol-btn--active': form.ensoleillement === opt.value }"
                        type="button"
                        @click="form.ensoleillement = form.ensoleillement === opt.value ? null : opt.value"
                    >
                        {{ opt.label }}
                    </button>
                </div>
            </div>

            <div class="dem-grid-3">
                <div class="dem-field">
                    <label>Temp. <span class="dem-unit">°C</span></label>
                    <input type="number" v-model="form.temperature" class="dem-input dem-input-num" placeholder="—" step="0.1" inputmode="decimal" />
                </div>
                <div class="dem-field">
                    <label>Humidité <span class="dem-unit">%</span></label>
                    <input type="number" v-model="form.humidite" class="dem-input dem-input-num" placeholder="—" step="1" min="0" max="100" inputmode="decimal" />
                </div>
                <div class="dem-field">
                    <label>Vent <span class="dem-unit">km/h</span></label>
                    <input type="number" v-model="form.vent_kmh" class="dem-input dem-input-num" placeholder="—" step="1" min="0" inputmode="decimal" />
                </div>
            </div>

            <div class="dem-field">
                <label>Remarque</label>
                <textarea v-model="form.remarque" class="dem-input dem-textarea" placeholder="Optionnel…" rows="2"></textarea>
            </div>
        </div>

        <!-- ── Bouton démarrer ────────────────────────────────── -->
        <button
            class="dem-btn-demarrer"
            :class="{ 'dem-btn-demarrer--disabled': !peutDemarrer || saving }"
            :disabled="!peutDemarrer || saving"
            @click="demarrer"
        >
            <i v-if="saving" class="pi pi-spin pi-spinner"></i>
            <i v-else class="pi pi-play"></i>
            {{ saving ? 'Démarrage…' : 'Démarrer la production' }}
        </button>

        <p class="dem-cancel-hint">
            Vous pouvez revenir en arrière sans démarrer —
            le lot restera en repos.
        </p>

    </template>
</div>
</template>

<style scoped>
.pwa-page {
    padding: 16px;
    padding-bottom: 40px;
}
.pwa-loading {
    display: flex;
    justify-content: center;
    padding: 60px 0;
}

/* En-tête lot */
.dem-lot-header {
    background: #1a2e1a;
    border: 1px solid #2a3f2a;
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 16px;
}
.dem-lot-num    { font-size: 22px; font-weight: 800; color: #d4f5c4; letter-spacing: .5px; }
.dem-lot-saveur { font-size: 15px; color: #9ac880; margin: 2px 0 8px; }
.dem-lot-info   { font-size: 12px; color: #5a7a50; }
.dem-lot-info strong { color: #9ac880; }

/* Blocs */
.dem-bloc {
    background: #141f14;
    border: 1px solid #1e2e1e;
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 12px;
}
.dem-bloc-titre {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: #5a7a50;
    margin-bottom: 12px;
}
.dem-bloc-hint {
    font-size: 12px;
    color: #4a6a40;
    margin: -4px 0 12px;
    line-height: 1.5;
}

/* Champs */
.dem-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
    margin-bottom: 12px;
}
.dem-field:last-child { margin-bottom: 0; }
.dem-field label {
    font-size: 13px;
    font-weight: 600;
    color: #7aaa60;
}
.dem-req  { color: #f5a040; }
.dem-unit { font-weight: 400; color: #4a6a40; font-size: 11px; }

.dem-input {
    background: #0d1a0d;
    border: 1.5px solid #2a3f2a;
    border-radius: 10px;
    color: #d4f5c4;
    font-size: 16px; /* 16px minimum pour éviter le zoom iOS */
    padding: 12px 14px;
    width: 100%;
    outline: none;
    -webkit-appearance: none;
    box-sizing: border-box;
}
.dem-input:focus { border-color: #4a9a3a; }
.dem-input::placeholder { color: #2a4a2a; }

.dem-input-num { font-variant-numeric: tabular-nums; letter-spacing: .5px; }

.dem-input-suffix-wrap {
    position: relative;
    display: flex;
    align-items: center;
}
.dem-input-suffix-wrap .dem-input { padding-right: 42px; }
.dem-input-suffix {
    position: absolute;
    right: 14px;
    font-size: 13px;
    color: #4a6a40;
    pointer-events: none;
}

.dem-textarea {
    resize: none;
    line-height: 1.5;
    min-height: 60px;
}

/* Grille 3 colonnes météo */
.dem-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
}

/* Ensoleillement */
.dem-ensol-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}
.dem-ensol-btn {
    background: #0d1a0d;
    border: 1.5px solid #2a3f2a;
    border-radius: 10px;
    color: #6a8a60;
    font-size: 14px;
    padding: 10px 8px;
    cursor: pointer;
    text-align: center;
    -webkit-tap-highlight-color: transparent;
    transition: all .15s;
}
.dem-ensol-btn--active {
    background: #1a3f1a;
    border-color: #4a9a3a;
    color: #d4f5c4;
    font-weight: 600;
}

/* Résultat net */
.dem-net {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #1a3a1a;
    border-radius: 10px;
    padding: 10px 14px;
    margin-top: 4px;
}
.dem-net-label { font-size: 13px; color: #5a7a50; }
.dem-net-val   { font-size: 20px; font-weight: 800; color: #6dbf5a; font-variant-numeric: tabular-nums; }

/* Bouton démarrer */
.dem-btn-demarrer {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 18px;
    background: #2a6a1a;
    border: none;
    border-radius: 14px;
    color: #d4f5c4;
    font-size: 17px;
    font-weight: 700;
    cursor: pointer;
    margin-top: 8px;
    -webkit-tap-highlight-color: transparent;
    transition: background .15s;
}
.dem-btn-demarrer:active { background: #3a8a2a; }
.dem-btn-demarrer--disabled {
    background: #1a2e1a;
    color: #3a5a3a;
    cursor: not-allowed;
}

.dem-cancel-hint {
    text-align: center;
    font-size: 12px;
    color: #3a5a3a;
    margin-top: 12px;
    line-height: 1.5;
}
</style>
