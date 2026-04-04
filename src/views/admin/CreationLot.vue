<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';

const router = useRouter();
const toast  = useToast();

// ── Données ───────────────────────────────────────────────────
const saveurs  = ref([]);
const recettes = ref([]);
const loading  = ref(true);
const saving   = ref(false);

// ── Formulaire bloc 1 ─────────────────────────────────────────
const form = reactive({
    saveur_id:       null,
    recette_id:      null,
    date_production: new Date().toISOString().slice(0, 10),
    installation:    '',
});

// ── Calcul à rebours ──────────────────────────────────────────
const rendements     = ref(null);
const cibleSouhaitee = ref(null);

const poidsBrutNecessaire = computed(() => {
    if (!rendements.value?.disponible) return null;
    if (!cibleSouhaitee.value || cibleSouhaitee.value <= 0) return null;
    const result = cibleSouhaitee.value / rendements.value.rdt_pulpe_cruf / rendements.value.rdt_brut_pulpe;
    return Math.round(result * 100) / 100;
});

// ── Chargement saveurs ────────────────────────────────────────
onMounted(async () => {
    try {
        const res = await axiosCrufiture.get('/saveurs');
        saveurs.value = (res.data.details || []).filter(s => s.actif);
    } catch {}
    loading.value = false;
});

// ── Changement de saveur → charger recettes + rendements ─────
watch(() => form.saveur_id, async (sid) => {
    form.recette_id  = null;
    recettes.value   = [];
    rendements.value = null;
    cibleSouhaitee.value = null;

    if (!sid) return;

    // Pré-remplir paramètres depuis la saveur
    const saveur = saveurs.value.find(s => s.id === sid);

    // Charger les recettes de cette saveur
    try {
        const res = await axiosCrufiture.get('/recettes');
        recettes.value = (res.data.details || [])
            .filter(r => r.saveur_id === sid && r.actif)
            .sort((a, b) => b.version - a.version); // plus récente en premier
    } catch {}

    // Rendements historiques : chercher un lot en stock de cette saveur
    try {
        const res = await axiosCrufiture.get('/lots', {
            params: { statut: 'stock', saveur_id: sid }
        });
        const lots = res.data.details || [];
        if (lots.length > 0) {
            const res2 = await axiosCrufiture.get('/lots/' + lots[0].id + '/rendements');
            rendements.value = res2.data.details;
        }
    } catch {}
});

// ── Options ───────────────────────────────────────────────────
const saveurOptions = computed(() =>
    saveurs.value.map(s => ({ label: s.nom, value: s.id }))
);

const recetteOptions = computed(() =>
    recettes.value.map(r => ({
        label: 'v' + r.version + ' — ' + r.titre,
        value: r.id,
    }))
);

// ── Création ──────────────────────────────────────────────────
const creer = async () => {
    if (!form.saveur_id) {
        toast.add({ severity: 'warn', summary: 'Manquant', detail: 'Sélectionnez une saveur.', life: 3000 });
        return;
    }
    if (!form.recette_id) {
        toast.add({ severity: 'warn', summary: 'Manquant', detail: 'Sélectionnez une recette.', life: 3000 });
        return;
    }
    if (!form.date_production) {
        toast.add({ severity: 'warn', summary: 'Manquant', detail: 'Renseignez la date de production.', life: 3000 });
        return;
    }

    saving.value = true;
    try {
        const res = await axiosCrufiture.post('/lots', {
            saveur_id:       Number(form.saveur_id),
            recette_id:      Number(form.recette_id),
            date_production: form.date_production,
            installation:    form.installation || null,
        });

        if (res.data.status === 'success') {
            const { id, numero_lot } = res.data.details;
            toast.add({
                severity: 'success',
                summary:  'Lot ' + numero_lot + ' créé',
                detail:   'Complétez maintenant la fiche.',
                life:     3000,
            });
            router.push('/dashboard/lots/' + id);
        } else {
            toast.add({ severity: 'error', summary: 'Erreur', detail: res.data.message, life: 4000 });
        }
    } catch (err) {
        const detail = err.response?.data?.message ?? 'Impossible de créer le lot.';
        toast.add({ severity: 'error', summary: 'Erreur', detail, life: 4000 });
    } finally {
        saving.value = false;
    }
};
</script>

<template>
<div class="col-12">
<PageCard titre="Nouveau lot">
    <template #actions>
        <Button
            label="Retour"
            icon="pi pi-arrow-left"
            text
            size="small"
            @click="router.push('/dashboard/lots')"
        />
    </template>

    <div v-if="loading" class="flex justify-content-center p-5">
        <ProgressSpinner />
    </div>

    <template v-else>
        <div class="creation-layout">

            <!-- ── Colonne gauche : formulaire ──────────────── -->
            <div class="creation-bloc">
                <div class="creation-bloc-titre">
                    <i class="pi pi-info-circle mr-2"></i>Identité du lot
                </div>

                <div class="creation-field">
                    <label>Saveur <span class="creation-required">*</span></label>
                    <Dropdown
                        v-model="form.saveur_id"
                        :options="saveurOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Choisir une saveur…"
                        class="w-full"
                    />
                </div>

                <div class="creation-field">
                    <label>
                        Recette <span class="creation-required">*</span>
                        <span v-if="form.saveur_id && recetteOptions.length === 0" class="creation-hint-warn">
                            — aucune recette active pour cette saveur
                        </span>
                    </label>
                    <Dropdown
                        v-model="form.recette_id"
                        :options="recetteOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Choisir une recette…"
                        :disabled="!form.saveur_id || recetteOptions.length === 0"
                        class="w-full"
                    />
                </div>

                <div class="creation-grid-2">
                    <div class="creation-field">
                        <label>Date de production <span class="creation-required">*</span></label>
                        <input
                            type="date"
                            v-model="form.date_production"
                            class="p-inputtext p-component w-full"
                        />
                    </div>
                    <div class="creation-field">
                        <label>Installation</label>
                        <InputText
                            v-model="form.installation"
                            placeholder="ex: Inox, Plastique"
                            class="w-full"
                        />
                    </div>
                </div>

                <div class="creation-actions">
                    <Button
                        label="Créer le lot et compléter la fiche"
                        icon="pi pi-arrow-right"
                        iconPos="right"
                        :loading="saving"
                        :disabled="!form.saveur_id || !form.recette_id"
                        @click="creer"
                    />
                </div>
            </div>

            <!-- ── Colonne droite : calcul à rebours ────────── -->
            <div class="creation-bloc creation-bloc-aide">
                <div class="creation-bloc-titre">
                    <i class="pi pi-chart-bar mr-2"></i>Calcul à rebours
                    <span class="creation-bloc-opt">optionnel</span>
                </div>

                <template v-if="!form.saveur_id">
                    <p class="creation-aide-msg">
                        Sélectionnez une saveur pour voir les rendements historiques.
                    </p>
                </template>

                <template v-else-if="!rendements?.disponible">
                    <p class="creation-aide-msg">
                        Pas encore de lots précédents en stock pour cette saveur.<br>
                        Le calcul à rebours sera disponible après votre premier lot terminé.
                    </p>
                </template>

                <template v-else>
                    <p class="creation-aide-note">
                        Basé sur <strong>{{ rendements.nb_lots }} lot{{ rendements.nb_lots > 1 ? 's' : '' }}</strong> précédent{{ rendements.nb_lots > 1 ? 's' : '' }} de cette saveur.
                    </p>

                    <div class="creation-rendements">
                        <div class="creation-rdt-item">
                            <div class="creation-rdt-val">{{ Math.round(rendements.rdt_brut_pulpe * 100) }}%</div>
                            <div class="creation-rdt-lbl">Rdt brut → pulpe</div>
                        </div>
                        <div class="creation-rdt-item">
                            <div class="creation-rdt-val">{{ Math.round(rendements.rdt_pulpe_cruf * 100) }}%</div>
                            <div class="creation-rdt-lbl">Rdt pulpe → crufiture</div>
                        </div>
                    </div>

                    <div class="creation-field mt-3">
                        <label>Quantité de crufiture souhaitée <span class="creation-unit">kg</span></label>
                        <InputNumber
                            v-model="cibleSouhaitee"
                            :min="0"
                            :maxFractionDigits="2"
                            inputClass="w-full"
                            placeholder="ex: 2.5"
                        />
                    </div>

                    <div v-if="poidsBrutNecessaire" class="creation-rebours-resultat">
                        <div class="creation-rebours-label">Fruits bruts à prévoir</div>
                        <div class="creation-rebours-val">~{{ poidsBrutNecessaire }} kg</div>
                    </div>
                </template>
            </div>

        </div>
    </template>
</PageCard>
</div>
</template>

<style scoped>
/* ── Layout ──────────────────────────────────────────────── */
.creation-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 800px) {
    .creation-layout { grid-template-columns: 1fr; }
}

/* ── Blocs ───────────────────────────────────────────────── */
.creation-bloc {
    background: var(--surface-50);
    border: 1px solid var(--surface-border);
    border-radius: 10px;
    padding: 1.25rem;
}
.creation-bloc-aide {
    background: var(--surface-0);
}
.creation-bloc-titre {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #888;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 6px;
}
.creation-bloc-opt {
    font-size: 11px;
    font-weight: 400;
    color: #bbb;
    text-transform: none;
    letter-spacing: 0;
    margin-left: 4px;
}

/* ── Champs ──────────────────────────────────────────────── */
.creation-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-bottom: 1rem;
}
.creation-field label {
    font-size: 12px;
    font-weight: 600;
    color: #555;
}
.creation-required { color: #e53935; }
.creation-unit { font-weight: 400; color: #bbb; font-size: 10px; margin-left: 2px; }
.creation-hint-warn { font-size: 11px; color: #b45309; font-weight: 400; }

.creation-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

/* ── Actions ─────────────────────────────────────────────── */
.creation-actions {
    margin-top: 1.5rem;
    padding-top: 1rem;
    border-top: 1px solid var(--surface-200);
}

/* ── Colonne aide ────────────────────────────────────────── */
.creation-aide-msg {
    font-size: 13px;
    color: #aaa;
    font-style: italic;
    line-height: 1.6;
    margin: 0;
}
.creation-aide-note {
    font-size: 13px;
    color: #666;
    margin: 0 0 1rem;
}

/* ── Rendements ──────────────────────────────────────────── */
.creation-rendements {
    display: flex;
    gap: 1rem;
}
.creation-rdt-item {
    flex: 1;
    background: var(--surface-50);
    border: 1px solid var(--surface-200);
    border-radius: 8px;
    padding: 0.75rem;
    text-align: center;
}
.creation-rdt-val {
    font-size: 24px;
    font-weight: 800;
    color: var(--primary-color);
}
.creation-rdt-lbl {
    font-size: 11px;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-top: 2px;
}

/* ── Résultat calcul à rebours ───────────────────────────── */
.creation-rebours-resultat {
    background: #fef3c7;
    border: 1px solid #fcd34d;
    border-radius: 8px;
    padding: 1rem;
    margin-top: 0.75rem;
    text-align: center;
}
.creation-rebours-label {
    font-size: 11px;
    color: #92400e;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 4px;
}
.creation-rebours-val {
    font-size: 28px;
    font-weight: 800;
    color: #b45309;
}
</style>
