<script setup>
/**
 * ProductionStock.vue — PWA mobile /crufiture/production/lot/:id/stocker
 *
 * Workflow de mise en stock terrain :
 *   Pour chaque jarre : tare (vide) + poids pleine → contenu calculé
 *   Contrôle qualité obligatoire : Brix, Aw, pH
 *   PUT /lots/:id/stocker
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

// ── Jarres ────────────────────────────────────────────────────
const jarres = ref([{ tare_kg: '', poids_pleine_kg: '' }]);

const ajouterJarre = () => {
    jarres.value.push({ tare_kg: '', poids_pleine_kg: '' });
};
const supprimerJarre = (idx) => {
    if (jarres.value.length > 1) jarres.value.splice(idx, 1);
};

const contenuJarre = (jarre) => {
    const t = parseFloat(jarre.tare_kg);
    const p = parseFloat(jarre.poids_pleine_kg);
    if (isNaN(t) || isNaN(p) || p <= t) return null;
    return Math.round((p - t) * 1000) / 1000;
};

const totalContenu = computed(() =>
    jarres.value.reduce((s, j) => {
        const c = contenuJarre(j);
        return c !== null ? Math.round((s + c) * 1000) / 1000 : s;
    }, 0)
);

const pertePoids = computed(() => {
    if (!lot.value) return null;
    const dernier = lot.value.releves?.length
        ? lot.value.releves[lot.value.releves.length - 1]
        : null;
    if (!dernier || totalContenu.value <= 0) return null;
    return Math.round((dernier.poids_brut_kg - totalContenu.value) * 1000) / 1000;
});

// ── Contrôle qualité ─────────────────────────────────────────
const controle = reactive({
    brix_mesure: '',
    aw_mesure:   '',
    ph_mesure:   '',
    aspect:      '',
    remarque:    '',
});

const controleRenseigne = computed(() =>
    controle.brix_mesure !== '' || controle.aw_mesure !== '' || controle.ph_mesure !== ''
);

// ── Validation ────────────────────────────────────────────────
const peutValider = computed(() => {
    const jarresValides = jarres.value.some(j => contenuJarre(j) !== null && contenuJarre(j) > 0);
    return jarresValides && controleRenseigne.value && !saving.value;
});

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

// ── Valider la mise en stock ──────────────────────────────────
const valider = async () => {
    if (!peutValider.value) return;
    saving.value = true;
    try {
        const jarresPayload = jarres.value
            .filter(j => contenuJarre(j) !== null)
            .map(j => ({
                tare_kg:         parseFloat(j.tare_kg),
                poids_pleine_kg: parseFloat(j.poids_pleine_kg),
            }));

        const payload = {
            jarres: jarresPayload,
            controle: {
                type_controle: 'mise_en_pot',
                date_controle: new Date().toISOString().slice(0, 10),
                brix_mesure:   controle.brix_mesure !== '' ? parseFloat(controle.brix_mesure) : null,
                aw_mesure:     controle.aw_mesure   !== '' ? parseFloat(controle.aw_mesure)   : null,
                ph_mesure:     controle.ph_mesure   !== '' ? parseFloat(controle.ph_mesure)   : null,
                aspect:        controle.aspect      || null,
                remarque:      controle.remarque    || null,
            },
        };

        const res = await axiosCrufiture.put('/lots/' + lotId.value + '/stocker', payload);

        if (res.data.status === 'success') {
            toast.add({ severity: 'success', summary: 'Mis en stock', detail: totalContenu.value.toFixed(3) + ' kg en ' + jarresPayload.length + ' jarre(s).', life: 4000 });
            router.replace('/production');
        } else {
            toast.add({ severity: 'error', summary: 'Erreur', detail: res.data.message, life: 5000 });
        }
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Une erreur est survenue.', life: 4000 });
    } finally {
        saving.value = false;
    }
};
</script>

<template>
<div class="pwa-page">

    <div v-if="loading" class="pwa-loading">
        <i class="pi pi-spin pi-spinner" style="font-size:28px;color:#6dbf5a"></i>
    </div>

    <template v-else-if="lot">

        <!-- En-tête -->
        <div class="stk-header">
            <div class="stk-lot-num">{{ lot.numero_lot }}</div>
            <div class="stk-lot-saveur">{{ lot.saveur_nom }}</div>
            <div class="stk-lot-ref" v-if="lot.releves?.length">
                Dernier relevé net :
                <strong>{{ Number(lot.releves[lot.releves.length - 1].poids_brut_kg).toFixed(3) }} kg</strong>
            </div>
        </div>

        <!-- ── Jarres ──────────────────────────────────────────── -->
        <div class="stk-bloc">
            <div class="stk-bloc-header">
                <div class="stk-bloc-titre">Jarres</div>
                <button class="stk-add-btn" @click="ajouterJarre" type="button">
                    + Ajouter une jarre
                </button>
            </div>
            <p class="stk-bloc-hint">Pesez chaque jarre à vide, puis remplissez et pesez à nouveau.</p>

            <div
                v-for="(jarre, idx) in jarres"
                :key="idx"
                class="stk-jarre-bloc"
            >
                <div class="stk-jarre-header">
                    <span class="stk-jarre-num">Jarre {{ idx + 1 }}</span>
                    <button
                        v-if="jarres.length > 1"
                        class="stk-jarre-del"
                        @click="supprimerJarre(idx)"
                        type="button"
                    >
                        <i class="pi pi-times"></i>
                    </button>
                </div>

                <div class="stk-jarre-row">
                    <div class="stk-field">
                        <label>Tare (à vide)</label>
                        <div class="stk-input-suffix-wrap">
                            <input
                                type="number"
                                v-model="jarres[idx].tare_kg"
                                class="stk-input stk-input-num"
                                placeholder="0.000"
                                step="0.001"
                                min="0"
                                inputmode="decimal"
                            />
                            <span class="stk-input-suffix">kg</span>
                        </div>
                    </div>
                    <div class="stk-field">
                        <label>Poids pleine</label>
                        <div class="stk-input-suffix-wrap">
                            <input
                                type="number"
                                v-model="jarres[idx].poids_pleine_kg"
                                class="stk-input stk-input-num"
                                placeholder="0.000"
                                step="0.001"
                                min="0"
                                inputmode="decimal"
                            />
                            <span class="stk-input-suffix">kg</span>
                        </div>
                    </div>
                    <div class="stk-field">
                        <label>Contenu</label>
                        <div class="stk-contenu" :class="{ 'stk-contenu--ok': contenuJarre(jarre) !== null && contenuJarre(jarre) > 0 }">
                            {{ contenuJarre(jarre) !== null ? contenuJarre(jarre).toFixed(3) + ' kg' : '—' }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Résumé -->
            <div class="stk-resume">
                <div class="stk-resume-row">
                    <span>Total mis en pot</span>
                    <strong>{{ totalContenu.toFixed(3) }} kg</strong>
                </div>
                <div v-if="pertePoids !== null" class="stk-resume-row stk-perte">
                    <span>Perte</span>
                    <strong>{{ pertePoids.toFixed(3) }} kg</strong>
                </div>
            </div>
        </div>

        <!-- ── Contrôle qualité ───────────────────────────────── -->
        <div class="stk-bloc">
            <div class="stk-bloc-titre">Contrôle qualité <span class="stk-req">*</span></div>
            <p class="stk-bloc-hint">Au moins un des trois mesures est requis.</p>

            <div class="stk-ctrl-grid">
                <div class="stk-field">
                    <label>Brix <span class="stk-unit">°Bx</span></label>
                    <input type="number" v-model="controle.brix_mesure" class="stk-input stk-input-num" placeholder="—" step="0.1" inputmode="decimal" />
                </div>
                <div class="stk-field">
                    <label>Aw</label>
                    <input type="number" v-model="controle.aw_mesure" class="stk-input stk-input-num" placeholder="—" step="0.0001" min="0" max="1" inputmode="decimal" />
                </div>
                <div class="stk-field">
                    <label>pH</label>
                    <input type="number" v-model="controle.ph_mesure" class="stk-input stk-input-num" placeholder="—" step="0.01" min="0" max="14" inputmode="decimal" />
                </div>
            </div>

            <div class="stk-field">
                <label>Aspect</label>
                <input type="text" v-model="controle.aspect" class="stk-input" placeholder="Texture, couleur, odeur…" autocomplete="off" />
            </div>
            <div class="stk-field">
                <label>Remarque</label>
                <textarea v-model="controle.remarque" class="stk-input stk-textarea" placeholder="Optionnel…" rows="2"></textarea>
            </div>
        </div>

        <!-- ── Bouton valider ─────────────────────────────────── -->
        <button
            class="stk-btn-valider"
            :class="{ 'stk-btn--disabled': !peutValider }"
            :disabled="!peutValider"
            @click="valider"
        >
            <i v-if="saving" class="pi pi-spin pi-spinner"></i>
            <i v-else class="pi pi-check-circle"></i>
            {{ saving ? 'Enregistrement…' : 'Valider la mise en stock' }}
        </button>

    </template>
</div>
</template>

<style scoped>
.pwa-page { padding: 16px; padding-bottom: 40px; }
.pwa-loading { display: flex; justify-content: center; padding: 60px 0; }

/* En-tête */
.stk-header {
    background: #111e2e;
    border: 1px solid #1e3a5a;
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 16px;
}
.stk-lot-num    { font-size: 22px; font-weight: 800; color: #d4f5c4; }
.stk-lot-saveur { font-size: 14px; color: #9ac880; margin: 2px 0 8px; }
.stk-lot-ref    { font-size: 12px; color: #4a7a9a; }
.stk-lot-ref strong { color: #7ab8f5; }

/* Blocs */
.stk-bloc {
    background: #141f14;
    border: 1px solid #1e2e1e;
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 12px;
}
.stk-bloc-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
}
.stk-bloc-titre {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: #5a7a50;
}
.stk-bloc-hint { font-size: 12px; color: #4a6a40; margin: 0 0 12px; line-height: 1.5; }
.stk-req { color: #f5a040; }
.stk-unit { font-weight: 400; color: #4a6a40; font-size: 11px; }

/* Ajout jarre */
.stk-add-btn {
    background: none;
    border: 1px solid #2a4a2a;
    color: #5a9a4a;
    border-radius: 20px;
    padding: 5px 14px;
    font-size: 12px;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}

/* Jarre */
.stk-jarre-bloc {
    border-bottom: 1px dashed #1e2e1e;
    padding-bottom: 12px;
    margin-bottom: 12px;
}
.stk-jarre-bloc:last-of-type { border-bottom: none; margin-bottom: 0; }

.stk-jarre-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.stk-jarre-num { font-size: 13px; font-weight: 600; color: #6a8a60; }
.stk-jarre-del {
    background: none;
    border: none;
    color: #5a3a3a;
    cursor: pointer;
    padding: 4px;
    -webkit-tap-highlight-color: transparent;
}

.stk-jarre-row {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 8px;
    align-items: end;
}

/* Champs */
.stk-field { display: flex; flex-direction: column; gap: 4px; }
.stk-field label { font-size: 12px; font-weight: 600; color: #6a8a60; }

.stk-input {
    background: #0d1a0d;
    border: 1.5px solid #2a3f2a;
    border-radius: 10px;
    color: #d4f5c4;
    font-size: 16px;
    padding: 10px 12px;
    width: 100%;
    outline: none;
    -webkit-appearance: none;
    box-sizing: border-box;
}
.stk-input:focus { border-color: #4a9a3a; }
.stk-input::placeholder { color: #2a4a2a; }
.stk-input-num { font-variant-numeric: tabular-nums; }
.stk-textarea { resize: none; min-height: 60px; line-height: 1.5; }

.stk-input-suffix-wrap { position: relative; display: flex; align-items: center; }
.stk-input-suffix-wrap .stk-input { padding-right: 36px; }
.stk-input-suffix { position: absolute; right: 10px; font-size: 11px; color: #4a6a40; pointer-events: none; }

.stk-contenu {
    font-size: 16px;
    font-weight: 700;
    color: #4a6a40;
    padding: 10px 4px;
    font-variant-numeric: tabular-nums;
}
.stk-contenu--ok { color: #6dbf5a; }

/* Résumé */
.stk-resume {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #1e2e1e;
}
.stk-resume-row {
    display: flex;
    justify-content: space-between;
    font-size: 14px;
    color: #6a8a60;
    margin-bottom: 4px;
}
.stk-resume-row strong { color: #d4f5c4; font-size: 16px; }
.stk-perte { color: #8a6a40; }
.stk-perte strong { color: #f5b84a; font-size: 14px; }

/* Grille contrôle */
.stk-ctrl-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 12px;
}

/* Bouton valider */
.stk-btn-valider {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 18px;
    background: #1a4a6a;
    border: none;
    border-radius: 14px;
    color: #7ab8f5;
    font-size: 17px;
    font-weight: 700;
    cursor: pointer;
    margin-top: 8px;
    -webkit-tap-highlight-color: transparent;
    transition: background .15s;
}
.stk-btn-valider:active { background: #2a5a8a; }
.stk-btn--disabled { background: #1a2a2a; color: #3a5a6a; cursor: not-allowed; }
</style>
