<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';

const router = useRouter();
const toast  = useToast();

const COULEUR = '#7F77DD';

// ── Sélection recette ─────────────────────────────────────────
const recettes        = ref([]);
const versionSelectId = ref(null);
const loadingRecettes = ref(true);
const dateProduction  = ref(null);
const note            = ref('');
const saving          = ref(false);

onMounted(charger);

async function charger() {
    loadingRecettes.value = true;
    try {
        const res = await axiosCrufiture.get('/recettes-transfo', { params: { gamme_id: '' } });
        if (res.data?.status === 'success') {
            recettes.value = res.data.details
                .filter(r => r.gamme_slug === 'maceration_alcool')
                .map(r => ({
                    ...r,
                    versions: r.versions.filter(v => ['validee', 'en_test'].includes(v.statut)),
                }))
                .filter(r => r.versions.length > 0);
        }
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger les recettes.', life: 4000 });
    } finally { loadingRecettes.value = false; }
}

// Options Dropdown — une entrée par version validée ou en_test
const optionsVersions = computed(() => {
    const opts = [];
    for (const r of recettes.value) {
        for (const v of r.versions) {
            const testLabel = v.statut === 'en_test' ? ' [TEST]' : '';
            opts.push({
                label:   r.nom + ' · v' + v.numero + testLabel + (v.notes_version ? ' — ' + v.notes_version : ''),
                value:   v.id,
                estTest: v.statut === 'en_test',
                version: v,
            });
        }
    }
    return opts;
});

const versionEstTest = computed(() => {
    if (!versionSelectId.value) return false;
    const opt = optionsVersions.value.find(o => o.value === versionSelectId.value);
    return opt?.estTest ?? false;
});

// Résumé de la version sélectionnée
const detailsVersion = ref(null);
const loadingVersion  = ref(false);

async function onSelectionVersion(e) {
    const vId = typeof e === 'object' ? e.value : e;
    if (!vId) { detailsVersion.value = null; return; }
    loadingVersion.value = true;
    try {
        const res = await axiosCrufiture.get('/recettes-transfo/version', { params: { version_id: vId } });
        if (res.data?.status === 'success') detailsVersion.value = res.data.details;
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger la version.', life: 4000 });
    } finally { loadingVersion.value = false; }
}

// ── Créer le lot ──────────────────────────────────────────────
async function creer() {
    if (!versionSelectId.value) {
        toast.add({ severity: 'warn', summary: 'Recette manquante', detail: 'Sélectionnez une recette validée.', life: 3000 });
        return;
    }
    saving.value = true;
    try {
        const res = await axiosCrufiture.post('/mace-alcool/lots', {
            recette_version_id: Number(versionSelectId.value),
            lot_test:           versionEstTest.value ? 1 : 0,
            date_production:    dateProduction.value ? new Date(dateProduction.value).toISOString().slice(0, 10) : null,
            note:               note.value,
        });
        if (res.data?.status === 'success') {
            toast.add({ severity: 'success', summary: 'Lot créé', detail: res.data.details.numero_lot, life: 3000 });
            router.push('/dashboard/maceration_alcool/lots/' + res.data.details.id);
        }
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: e.response?.data?.message ?? 'Impossible de créer le lot.', life: 4000 });
    } finally { saving.value = false; }
}
</script>

<template>
<div class="col-12">
<PageCard titre="Nouveau lot — Macération alcoolique">
    <template #actions>
        <Button label="Annuler" icon="pi pi-times" text
                @click="router.push('/dashboard/maceration_alcool/lots')" />
        <Button label="Créer le lot" icon="pi pi-check"
                :style="{ background: COULEUR, borderColor: COULEUR }"
                :loading="saving"
                :disabled="!versionSelectId"
                @click="creer" />
    </template>

    <div class="cl-form">

        <!-- Sélection recette -->
        <div class="cl-section">
            <h3 class="cl-section-titre">Recette à utiliser</h3>

            <div v-if="loadingRecettes" class="flex justify-content-center p-4"><ProgressSpinner style="width:28px;height:28px" /></div>

            <div v-else-if="optionsVersions.length === 0" class="cl-empty">
                Aucune recette validée disponible.
                <Button label="Créer une recette" text size="small"
                        @click="router.push('/dashboard/maceration_alcool/recettes/nouvelle')" />
            </div>

            <div v-else class="cl-field">
                <Dropdown
                    v-model="versionSelectId"
                    :options="optionsVersions"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Sélectionner une version validée…"
                    class="w-full"
                    @change="onSelectionVersion"
                />
            </div>

            <!-- Avertissement lot de test -->
            <div v-if="versionEstTest" class="cl-test-banner">
                <i class="pi pi-exclamation-triangle" style="font-size:13px" />
                Version en test — ce lot sera marqué <strong>lot de test</strong>. Les bouteilles produites seront optionnellement déclarées en stock lors de la mise en stock.
            </div>

            <!-- Résumé version sélectionnée -->
            <div v-if="detailsVersion" class="cl-version-resume">
                <div class="cl-resume-grid">
                    <span v-if="detailsVersion.mace_alcool?.duree_maceration_cible_j">
                        <i class="pi pi-clock" />
                        Macération : {{ detailsVersion.mace_alcool.duree_maceration_cible_j }}j
                    </span>
                    <span v-if="detailsVersion.mace_alcool?.duree_maturation_cible_j">
                        <i class="pi pi-clock" />
                        Maturation : {{ detailsVersion.mace_alcool.duree_maturation_cible_j }}j
                    </span>
                    <span v-if="detailsVersion.mace_alcool?.abv_cible_pct">
                        <i class="pi pi-percentage" />
                        ABV cible : {{ detailsVersion.mace_alcool.abv_cible_pct }}%
                    </span>
                    <span v-if="detailsVersion.mace_alcool?.avec_assemblage">
                        <i class="pi pi-plus-circle" />
                        Avec assemblage (sirop)
                    </span>
                    <span v-if="detailsVersion.ingredients?.length">
                        <i class="pi pi-list" />
                        {{ detailsVersion.ingredients.length }} ingrédient{{ detailsVersion.ingredients.length > 1 ? 's' : '' }} (ajustables sur la fiche lot)
                    </span>
                </div>
            </div>
        </div>

        <!-- Date de production -->
        <div class="cl-section">
            <h3 class="cl-section-titre">Date de production</h3>
            <Calendar v-model="dateProduction"
                      dateFormat="dd/mm/yy"
                      placeholder="Aujourd'hui par défaut"
                      class="w-full"
                      showIcon />
        </div>

        <!-- Note -->
        <div class="cl-section">
            <h3 class="cl-section-titre">Note (optionnelle)</h3>
            <Textarea v-model="note" rows="3" class="w-full" placeholder="Remarques sur ce lot…" autoResize />
        </div>

    </div>
</PageCard>
</div>
</template>

<style scoped>
.cl-form { display:flex;flex-direction:column;gap:1.25rem; }
.cl-section { background:var(--surface-ground,#f9f9f9);border:1px solid var(--surface-border);border-radius:10px;padding:1.125rem 1.25rem; }
.cl-section-titre { font-size:14px;font-weight:700;color:var(--text-color);margin:0 0 0.875rem; }
.cl-field { margin-bottom:0.5rem; }
.cl-empty { font-size:13px;color:#bbb;font-style:italic;margin:0;padding:4px 0; }

.cl-version-resume { margin-top:0.75rem;padding:8px 12px;background:#EEEDFE;border-radius:7px; }
.cl-resume-grid { display:flex;flex-wrap:wrap;gap:12px; }
.cl-resume-grid span { font-size:12px;color:#7F77DD;font-weight:600;display:flex;align-items:center;gap:5px; }

.cl-test-banner { display:flex;align-items:flex-start;gap:8px;margin-top:0.75rem;padding:10px 14px;background:#FEF3C7;border:1px solid #FDE68A;border-radius:7px;font-size:12px;color:#92400E;line-height:1.5; }
</style>
