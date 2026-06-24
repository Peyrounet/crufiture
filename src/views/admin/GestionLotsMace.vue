<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';

const router = useRouter();
const toast  = useToast();

const COULEUR = '#7F77DD';
const FOND    = '#EEEDFE';

const lots      = ref([]);
const loading   = ref(true);
const filtre    = ref('tous');

const FILTRES = [
    { value: 'tous',     label: 'Tous' },
    { value: 'actifs',   label: 'En cours' },
    { value: 'alertes',  label: 'Alertes' },
    { value: 'stock',    label: 'Stockés' },
    { value: 'tests',    label: 'Tests' },
    { value: 'abandonne',label: 'Abandonnés' },
];

onMounted(charger);

async function charger() {
    loading.value = true;
    try {
        const res = await axiosCrufiture.get('/mace-alcool/lots');
        if (res.data?.status === 'success') lots.value = res.data.details;
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger les lots.', life: 4000 });
    } finally { loading.value = false; }
}

const lotsAffiches = computed(() => {
    switch (filtre.value) {
        case 'actifs':    return lots.value.filter(l => !['stock','abandonne'].includes(l.statut));
        case 'alertes':   return lots.value.filter(l => l.alerte_maceration || l.alerte_maturation);
        case 'stock':     return lots.value.filter(l => l.statut === 'stock');
        case 'tests':     return lots.value.filter(l => l.lot_test);
        case 'abandonne': return lots.value.filter(l => l.statut === 'abandonne');
        default:          return lots.value;
    }
});

const STATUT_CONFIG = {
    preparation:   { label: 'Préparation',  severity: 'secondary', icon: 'pi-clock' },
    en_maceration: { label: 'Macération',   severity: 'info',      icon: 'pi-bolt' },
    filtration:    { label: 'Filtration',   severity: 'warning',   icon: 'pi-filter' },
    assemblage:    { label: 'Assemblage',   severity: 'warning',   icon: 'pi-th-large' },
    maturation:    { label: 'Maturation',   severity: 'info',      icon: 'pi-clock' },
    stock:         { label: 'En stock',     severity: 'success',   icon: 'pi-check-circle' },
    abandonne:     { label: 'Abandonné',    severity: 'danger',    icon: 'pi-times-circle' },
};
function infosStatut(s) { return STATUT_CONFIG[s] ?? { label: s, severity: 'secondary', icon: 'pi-circle' }; }

function formatDate(d) {
    if (!d) return '—';
    const [y, m, j] = d.slice(0, 10).split('-');
    return `${j}/${m}/${y}`;
}

function progressWorkflow(statut) {
    const ordre = ['preparation','en_maceration','filtration','assemblage','maturation','stock'];
    const idx = ordre.indexOf(statut);
    return idx >= 0 ? Math.round(((idx + 1) / ordre.length) * 100) : (statut === 'abandonne' ? 0 : 0);
}
</script>

<template>
<div class="col-12">
    <PageCard titre="Lots — Macération alcoolique">
        <template #actions>
            <Button label="Nouveau lot" icon="pi pi-plus"
                    :style="{ background: COULEUR, borderColor: COULEUR }"
                    @click="router.push('/dashboard/maceration_alcool/lots/nouveau')" />
        </template>

        <!-- Filtres -->
        <div class="gl-filtres">
            <button
                v-for="f in FILTRES" :key="f.value"
                class="gl-filtre-btn"
                :class="{ 'gl-filtre-actif': filtre === f.value }"
                :style="filtre === f.value ? { background: FOND, color: COULEUR, borderColor: COULEUR } : {}"
                @click="filtre = f.value"
            >{{ f.label }}</button>
        </div>

        <div v-if="loading" class="flex justify-content-center p-5"><ProgressSpinner /></div>
        <p v-else-if="lotsAffiches.length === 0" class="gl-empty">Aucun lot dans cette catégorie.</p>

        <div v-else class="gl-liste">
            <div
                v-for="lot in lotsAffiches" :key="lot.id"
                class="gl-item"
                :class="{
                    'gl-item-alerte': lot.alerte_maceration || lot.alerte_maturation,
                    'gl-item-abandonne': lot.statut === 'abandonne',
                }"
                @click="router.push('/dashboard/maceration_alcool/lots/' + lot.id)"
            >
                <!-- Ligne principale -->
                <div class="gl-item-main">
                    <div class="gl-numero">{{ lot.numero_lot }}</div>

                    <div class="gl-recette">
                        <span class="gl-recette-nom">{{ lot.recette_nom }}</span>
                        <span class="gl-recette-version">v{{ lot.recette_version_numero }}</span>
                    </div>

                    <div v-if="lot.alerte_maceration || lot.alerte_maturation" class="gl-alerte-badge">
                        <i class="pi pi-exclamation-triangle" style="font-size:11px" />
                        {{ lot.alerte_maceration ? 'À filtrer' : 'À stocker' }}
                    </div>

                    <span v-if="lot.lot_test" class="gl-test-badge">TEST</span>

                    <Tag
                        :value="infosStatut(lot.statut).label"
                        :severity="infosStatut(lot.statut).severity"
                        style="font-size:10px;flex-shrink:0"
                    />

                    <span class="gl-date">{{ formatDate(lot.date_production) }}</span>

                    <i class="pi pi-chevron-right gl-chevron" />
                </div>

                <!-- Barre progression (lots actifs non abandonnés) -->
                <div v-if="lot.statut !== 'abandonne' && lot.statut !== 'stock'" class="gl-progress-bar">
                    <div class="gl-progress-fill" :style="{ width: progressWorkflow(lot.statut) + '%', background: COULEUR }" />
                </div>
            </div>
        </div>
    </PageCard>
</div>
</template>

<style scoped>
.gl-filtres { display:flex;gap:6px;margin-bottom:1.25rem;flex-wrap:wrap; }
.gl-filtre-btn { padding:4px 12px;border:1px solid var(--surface-border);border-radius:5px;background:transparent;cursor:pointer;font-size:12px;font-weight:500;color:var(--text-color-secondary);transition:all 0.12s;line-height:1.5; }
.gl-filtre-btn:hover { background:var(--surface-hover); }
.gl-filtre-actif { font-weight:700; }

.gl-empty { color:#bbb;font-size:13px;font-style:italic;margin:0;padding:8px 0; }

.gl-liste { display:flex;flex-direction:column;gap:0; }
.gl-item {
    border-bottom:1px solid var(--surface-border);
    cursor:pointer;
    transition:background 0.12s;
}
.gl-item:last-child { border-bottom:none; }
.gl-item:hover { background:var(--surface-hover); }
.gl-item-alerte { background:#fffbeb; }
.gl-item-alerte:hover { background:#fef3c7; }
.gl-item-abandonne { opacity:0.6; }

.gl-item-main {
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 6px 8px;
}
.gl-numero { font-family:monospace;font-size:14px;font-weight:800;color:var(--text-color);flex-shrink:0;min-width:80px; }
.gl-recette { display:flex;flex-direction:column;flex:1;min-width:0;overflow:hidden; }
.gl-recette-nom { font-size:13px;font-weight:600;color:var(--text-color);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.gl-recette-version { font-size:11px;color:var(--text-color-secondary); }

.gl-alerte-badge { flex-shrink:0;display:inline-flex;align-items:center;gap:4px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:4px;padding:2px 7px;font-size:10px;font-weight:700; }
.gl-test-badge { flex-shrink:0;background:#EEEDFE;color:#7F77DD;border:1px solid #c4c1f7;border-radius:4px;padding:2px 6px;font-size:9px;font-weight:800;letter-spacing:0.04em; }

.gl-date { font-size:11px;color:var(--text-color-secondary);flex-shrink:0; }
.gl-chevron { font-size:10px;color:var(--text-color-secondary);flex-shrink:0; }

.gl-progress-bar { height:2px;background:var(--surface-border);margin:0 6px 6px; }
.gl-progress-fill { height:100%;border-radius:1px;transition:width 0.3s; }
</style>
