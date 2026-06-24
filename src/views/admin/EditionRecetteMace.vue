<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';
import { useGammeStore } from '@/stores/gammeStore';

import MACE_Identification from './mace/MACE_Identification.vue';
import MACE_Parametres     from './mace/MACE_Parametres.vue';
import MACE_Ingredients    from './mace/MACE_Ingredients.vue';
import MACE_Protocole      from './mace/MACE_Protocole.vue';
import MACE_Controles      from './mace/MACE_Controles.vue';
import MACE_Valorisation   from './mace/MACE_Valorisation.vue';

const route      = useRoute();
const router     = useRouter();
const toast      = useToast();
const gammeStore = useGammeStore();

const isNouvelle  = computed(() => route.params.id === 'nouvelle');
const versionId   = computed(() => isNouvelle.value ? null : Number(route.params.id));

const loading = ref(false);
const saving  = ref(false);

const sectionActive = ref('identification');

const sections = [
    { key: 'identification', label: 'Identification'           },
    { key: 'parametres',     label: 'Paramètres macération'    },
    { key: 'ingredients',    label: 'Ingrédients'              },
    { key: 'protocole',      label: 'Protocole'                },
    { key: 'controles',      label: 'Points de contrôle'       },
    { key: 'valorisation',   label: 'Valorisation & Provenance'},
];

const form = reactive({
    // transfo_recette
    recette_id: null,
    nom:        '',
    famille:    '',
    // transfo_recette_version
    version_id:       null,
    numero:           null,
    statut:           'brouillon',
    notes_version:    '',
    description:      '',
    nb_unites:        1,
    unite_production: '',
    materiel:         '',
    difficulte:       null,
    conservation:     '',
    // mace_alcool_recette_version
    duree_maceration_cible_j: null,
    duree_maturation_cible_j: null,
    abv_cible_pct:            null,
    brix_cible:               null,
    avec_assemblage:          false,
    // relations
    ingredients: [],
    phases:      [],
    controles:   [],
});

// ── Complétude ────────────────────────────────────────────────
function isComplete() {
    const nomOk = isNouvelle.value ? !!form.nom?.trim() : !!form.recette_id;
    return nomOk
        && form.ingredients.length > 0
        && form.phases.some(p => p.etapes.length > 0);
}

function sectionRemplie(key) {
    if (key === 'identification') {
        return isNouvelle.value
            ? !!form.nom?.trim()
            : !!(form.notes_version?.trim() || form.nb_unites > 1 || form.unite_production?.trim());
    }
    if (key === 'parametres')   return form.duree_maceration_cible_j !== null;
    if (key === 'ingredients')  return form.ingredients.length > 0;
    if (key === 'protocole')    return form.phases.some(p => p.etapes.length > 0);
    if (key === 'controles')    return form.controles.length > 0;
    if (key === 'valorisation') return !!form.description?.trim();
    return false;
}

// ── Chargement ────────────────────────────────────────────────
onMounted(async () => {
    loading.value = true;
    await gammeStore.charger();
    if (!isNouvelle.value) {
        try {
            const res = await axiosCrufiture.get('/recettes-transfo/version', {
                params: { version_id: versionId.value }
            });
            if (res.data?.status !== 'success') throw new Error(res.data?.message);
            const d = res.data.details;

            Object.assign(form, {
                recette_id:       d.recette_id,
                nom:              d.nom              ?? '',
                famille:          d.famille          ?? '',
                version_id:       d.id,
                numero:           d.numero,
                statut:           d.statut,
                notes_version:    d.notes_version    ?? '',
                description:      d.description      ?? '',
                nb_unites:        d.nb_unites        ?? 1,
                unite_production: d.unite_production ?? '',
                materiel:         d.materiel         ?? '',
                difficulte:       d.difficulte       ?? null,
                conservation:     d.conservation     ?? '',
            });

            if (d.mace_alcool) {
                form.duree_maceration_cible_j = d.mace_alcool.duree_maceration_cible_j;
                form.duree_maturation_cible_j = d.mace_alcool.duree_maturation_cible_j;
                form.abv_cible_pct            = d.mace_alcool.abv_cible_pct;
                form.brix_cible               = d.mace_alcool.brix_cible;
                form.avec_assemblage          = !!d.mace_alcool.avec_assemblage;
            }

            form.ingredients = (d.ingredients ?? []).map(i => ({
                stock_article_id: i.stock_article_id,
                libelle:          i.libelle,
                quantite:         i.quantite,
                coeff_perte:      i.coeff_perte ?? 1,
                unite:            i.unite ?? '',
                note:             i.note ?? '',
            }));

            form.phases = (d.phases ?? []).map(p => ({
                temporalite: p.temporalite ?? '',
                label:       p.label ?? '',
                etapes:      (p.etapes ?? []).map(e => ({ _uid: e.id ?? Math.random(), description: e.description })),
            }));

            form.controles = (d.controles ?? []).map(c => ({
                etape_label:       c.etape_label       ?? '',
                point_controle:    c.point_controle    ?? '',
                valeur_cible:      c.valeur_cible      ?? '',
                action_corrective: c.action_corrective ?? '',
            }));

        } catch {
            toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger la recette.', life: 4000 });
        }
    }
    loading.value = false;
});

// ── Sauvegarde ────────────────────────────────────────────────
async function sauvegarder() {
    if (isNouvelle.value && !form.nom?.trim()) {
        toast.add({ severity: 'warn', summary: 'Nom requis', detail: 'Donnez un nom à la recette avant d\'enregistrer.', life: 3000 });
        sectionActive.value = 'identification';
        return;
    }

    saving.value = true;
    try {
        const payload = {
            // identité recette
            recette_id:       form.recette_id,
            nom:              form.nom.trim(),
            famille:          form.famille || null,
            // version
            version_id:       form.version_id,
            notes_version:    form.notes_version    || null,
            description:      form.description      || null,
            nb_unites:        form.nb_unites,
            unite_production: form.unite_production || null,
            materiel:         form.materiel         || null,
            difficulte:       form.difficulte       ?? null,
            conservation:     form.conservation     || null,
            // mace_alcool — paramètres à plat (attendus directement par le contrôleur PHP)
            duree_maceration_cible_j: form.duree_maceration_cible_j,
            duree_maturation_cible_j: form.duree_maturation_cible_j,
            abv_cible_pct:            form.abv_cible_pct,
            brix_cible:               form.brix_cible,
            avec_assemblage:          form.avec_assemblage ? 1 : 0,
            // relations
            ingredients: form.ingredients.map(i => ({
                stock_article_id: i.stock_article_id,
                libelle:          i.libelle,
                quantite:         i.quantite,
                coeff_perte:      i.coeff_perte,
                unite:            i.unite,
                note:             i.note || null,
            })),
            phases: form.phases.map((phase, pi) => ({
                ordre:       pi,
                temporalite: phase.temporalite || '',
                label:       phase.label       || '',
                etapes:      phase.etapes.map((e, ei) => ({ ordre: ei, description: e.description })),
            })),
            controles: form.controles,
        };

        let res;
        if (isNouvelle.value) {
            const gamme = gammeStore.gammes.find(g => g.slug === 'maceration_alcool');
            if (!gamme) throw new Error('Gamme macération alcoolique introuvable.');
            payload.gamme_id = gamme.id;
            res = await axiosCrufiture.post('/recettes-transfo', payload);
            if (res.data?.status !== 'success') throw new Error(res.data?.message);
            const newVersionId = res.data.details?.version_id;
            form.recette_id = res.data.details?.recette_id;
            form.version_id = newVersionId;
            form.numero     = 1;
            router.replace({ name: 'EditionRecetteMace', params: { id: String(newVersionId) } });
        } else {
            res = await axiosCrufiture.put('/recettes-transfo', payload);
            if (res.data?.status !== 'success') throw new Error(res.data?.message);
        }

        toast.add({ severity: 'success', summary: 'Enregistré', detail: 'Recette sauvegardée.', life: 2500 });

    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: e.message || 'Impossible d\'enregistrer.', life: 4000 });
    } finally {
        saving.value = false;
    }
}

// ── Changement de statut ──────────────────────────────────────
const statutOptions = [
    { label: 'Brouillon', value: 'brouillon' },
    { label: 'En test',   value: 'en_test'   },
    { label: 'Validée',   value: 'validee'   },
];

async function changerStatut(e) {
    if (!form.version_id) return;
    try {
        const res = await axiosCrufiture.put('/recettes-transfo/statut', {
            version_id: form.version_id,
            statut:     e.value,
        });
        if (res.data?.status !== 'success') throw new Error(res.data?.message);
        toast.add({ severity: 'success', summary: 'Statut mis à jour', life: 2000 });
    } catch (err) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: err.message, life: 3000 });
    }
}

// ── Header ────────────────────────────────────────────────────
const titreHeader = computed(() =>
    isNouvelle.value
        ? (form.nom?.trim() || 'Nouvelle recette')
        : (form.nom?.trim() || 'Recette')
);
</script>

<template>
    <div class="ft-page" v-if="!loading">

        <!-- ── Header ─────────────────────────────────────────────── -->
        <div class="ft-header">
            <router-link to="/dashboard/maceration_alcool/recettes" class="ft-retour">
                <i class="pi pi-arrow-left" style="font-size:12px" /> Recettes
            </router-link>
            <span class="ft-header-titre">
                <span>{{ titreHeader }}</span>
                <span v-if="form.numero" class="ft-version-badge">v{{ form.numero }}</span>
            </span>
            <div class="ft-header-actions">
                <Dropdown
                    v-if="form.version_id"
                    v-model="form.statut"
                    :options="statutOptions"
                    option-label="label"
                    option-value="value"
                    :disabled="!isComplete()"
                    v-tooltip.bottom="!isComplete() ? 'Complétez la recette pour changer le statut' : ''"
                    class="ft-statut-dropdown"
                    @change="changerStatut"
                />
                <Button
                    label="Enregistrer"
                    icon="pi pi-save"
                    :loading="saving"
                    :style="{ background: '#7F77DD', borderColor: '#7F77DD' }"
                    @click="sauvegarder"
                />
            </div>
        </div>

        <!-- ── Layout 2 colonnes ──────────────────────────────────── -->
        <div class="ft-layout">

            <!-- Nav gauche -->
            <nav class="ft-nav">
                <div
                    v-for="s in sections"
                    :key="s.key"
                    class="ft-nav-item"
                    :class="{ 'ft-nav-active': sectionActive === s.key }"
                    @click="sectionActive = s.key"
                >
                    <span class="ft-nav-dot">
                        <i v-if="sectionActive === s.key" class="pi pi-circle-fill" style="font-size:8px;color:#7F77DD" />
                        <i v-else-if="sectionRemplie(s.key)" class="pi pi-check-circle" style="font-size:12px;color:var(--green-500)" />
                        <i v-else class="pi pi-circle" style="font-size:12px;color:var(--surface-400)" />
                    </span>
                    {{ s.label }}
                </div>
            </nav>

            <!-- Zone contenu -->
            <div class="ft-contenu">
                <div class="ft-card">
                    <MACE_Identification v-if="sectionActive === 'identification'" :form="form" :isNouvelle="isNouvelle" />
                    <MACE_Parametres     v-if="sectionActive === 'parametres'"     :form="form" />
                    <MACE_Ingredients    v-if="sectionActive === 'ingredients'"    :form="form" />
                    <MACE_Protocole      v-if="sectionActive === 'protocole'"      :form="form" />
                    <MACE_Controles      v-if="sectionActive === 'controles'"      :form="form" />
                    <MACE_Valorisation   v-if="sectionActive === 'valorisation'"   :form="form" />
                </div>
            </div>

        </div>

    </div>

    <div v-else class="ft-loading">
        <i class="pi pi-spin pi-spinner" style="font-size:2rem" />
    </div>
</template>

<style scoped>
.ft-page { display: flex; flex-direction: column; height: calc(100vh - 80px); overflow: hidden; width: 100%; }
.ft-loading { display: flex; align-items: center; justify-content: center; height: 200px; }

/* Header */
.ft-header {
    display: flex; align-items: center; gap: 1rem;
    padding: 0.6rem 1.25rem;
    border-bottom: 1px solid var(--surface-border);
    background: var(--surface-card);
    flex-shrink: 0;
}
.ft-retour {
    font-size: 13px; color: var(--text-color-secondary);
    text-decoration: none; display: flex; align-items: center; gap: 4px;
    flex-shrink: 0; white-space: nowrap;
}
.ft-retour:hover { color: #7F77DD; }
.ft-header-titre {
    font-size: 15px; font-weight: 700; color: var(--text-color);
    flex: 1; display: flex; align-items: center; gap: 0.5rem;
    overflow: hidden;
}
.ft-header-titre span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ft-version-badge {
    font-size: 11px; font-weight: 600;
    background: var(--surface-100, #f5f5f5); border: 1px solid var(--surface-border);
    border-radius: 5px; padding: 2px 7px; color: var(--text-color-secondary);
    flex-shrink: 0;
}
.ft-header-actions { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; }
.ft-statut-dropdown { font-size: 13px; }

/* Layout */
.ft-layout { display: flex; flex: 1; overflow: hidden; background: var(--surface-ground, #f8f9fa); }

/* Nav gauche */
.ft-nav {
    width: 210px; flex-shrink: 0;
    padding: 1.25rem 0;
    border-right: 1px solid var(--surface-border);
    background: var(--surface-card);
    overflow-y: auto;
    display: flex; flex-direction: column; gap: 2px;
}
.ft-nav-item {
    display: flex; align-items: center; gap: 0.625rem;
    padding: 0.625rem 1.125rem;
    font-size: 13px; color: var(--text-color-secondary);
    cursor: pointer; border-left: 3px solid transparent;
    transition: background 0.12s, color 0.12s;
}
.ft-nav-item:hover { background: var(--surface-hover); color: var(--text-color); }
.ft-nav-active {
    color: #7F77DD !important;
    border-left-color: #7F77DD;
    background: #EEEDFE;
    font-weight: 600;
}
.ft-nav-dot { width: 16px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

/* Zone contenu */
.ft-contenu { flex: 1; overflow-y: auto; padding: 1.5rem; min-width: 0; }
.ft-card {
    background: var(--surface-card);
    border: 1px solid var(--surface-border);
    border-radius: 8px;
    padding: 1.75rem 2rem;
    min-height: 100%;
    box-sizing: border-box;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
</style>
