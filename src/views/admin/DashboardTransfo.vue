<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axiosCrufiture from '@/plugins/axiosCrufiture';

const router = useRouter();

// ── Couleurs par slug (hardcodées) ────────────────────────────
const COULEURS_GAMME = {
    crufiture:         { couleur: '#1D9E75', fond: '#E1F5EE' },
    jus:               { couleur: '#BA7517', fond: '#FAEEDA' },
    sechage:           { couleur: '#639922', fond: '#EAF3DE' },
    maceration_alcool: { couleur: '#7F77DD', fond: '#EEEDFE' },
    maceration_huile:  { couleur: '#D4537E', fond: '#FBEAF0' },
    distillation:      { couleur: '#378ADD', fond: '#E6F1FB' },
};

function stylesGamme(slug) {
    return COULEURS_GAMME[slug] ?? { couleur: '#888', fond: '#f0f0f0' };
}

const ICONE_STATUT = {
    preparation: { icon: 'pi pi-clock',       label: 'Préparation', severity: 'secondary' },
    en_repos:    { icon: 'pi pi-moon',         label: 'En repos',    severity: 'info' },
    production:  { icon: 'pi pi-bolt',         label: 'Production',  severity: 'warning' },
    stock:       { icon: 'pi pi-check-circle', label: 'En stock',    severity: 'success' },
    'abandonné': { icon: 'pi pi-times-circle', label: 'Abandonné',   severity: 'danger' },
};

function infosStatut(statut) {
    return ICONE_STATUT[statut] ?? { icon: 'pi pi-circle', label: statut, severity: 'secondary' };
}

// ── Données ───────────────────────────────────────────────────
const kpis          = ref(null);
const lotsEnCours   = ref([]);
const derniersStocks = ref([]);
const parGamme      = ref([]);
const loading       = ref(true);

onMounted(async () => {
    try {
        const res = await axiosCrufiture.get('/dashboard-transfo');
        if (res.data?.status === 'success') {
            const d       = res.data.details;
            kpis.value           = d.kpis;
            lotsEnCours.value    = d.lots_en_cours  ?? [];
            derniersStocks.value = d.derniers_stocks ?? [];
            parGamme.value       = d.par_gamme       ?? [];
        }
    } catch {
        // silencieux — l'UI affiche des zéros
    } finally {
        loading.value = false;
    }
});

// ── Navigation ────────────────────────────────────────────────
const allerLots = (slug) => router.push(slug === 'crufiture' ? '/dashboard/crufiture/lots' : `/dashboard/${slug}/lots`);
const allerGamme = (slug) => router.push(slug === 'crufiture' ? '/dashboard/crufiture' : `/dashboard/${slug}`);

function formatDate(d) {
    if (!d) return '—';
    const [y, m, j] = d.split('-');
    return `${j}/${m}/${y}`;
}
</script>

<template>
<div class="col-12">

    <!-- ── En-tête ────────────────────────────────────────────── -->
    <div class="dt-header">
        <div>
            <h2 class="dt-titre">Transformations</h2>
            <p class="dt-sous-titre">Vue d'ensemble multi-gammes</p>
        </div>
        <Button
            label="Gammes & Produits"
            icon="pi pi-th-large"
            outlined
            @click="() => router.push('/dashboard/gammes')"
        />
    </div>

    <!-- ── KPIs ───────────────────────────────────────────────── -->
    <div class="dt-kpis">
        <div class="dt-kpi">
            <span class="dt-kpi-val">{{ loading ? '—' : (kpis?.gammes_actives ?? 0) }}</span>
            <span class="dt-kpi-label">gamme{{ (kpis?.gammes_actives ?? 0) > 1 ? 's' : '' }} active{{ (kpis?.gammes_actives ?? 0) > 1 ? 's' : '' }}</span>
        </div>
        <div class="dt-kpi dt-kpi-accent">
            <span class="dt-kpi-val">{{ loading ? '—' : (kpis?.lots_actifs ?? 0) }}</span>
            <span class="dt-kpi-label">lot{{ (kpis?.lots_actifs ?? 0) > 1 ? 's' : '' }} en cours</span>
        </div>
        <div class="dt-kpi">
            <span class="dt-kpi-val">{{ loading ? '—' : (kpis?.lots_stocks_annee ?? 0) }}</span>
            <span class="dt-kpi-label">stocké{{ (kpis?.lots_stocks_annee ?? 0) > 1 ? 's' : '' }} {{ kpis?.annee ?? '' }}</span>
        </div>
        <div class="dt-kpi">
            <span class="dt-kpi-val">{{ loading ? '—' : (kpis?.lots_total_annee ?? 0) }}</span>
            <span class="dt-kpi-label">lot{{ (kpis?.lots_total_annee ?? 0) > 1 ? 's' : '' }} total {{ kpis?.annee ?? '' }}</span>
        </div>
    </div>

    <!-- ── Deux colonnes : en cours + derniers stocks ─────────── -->
    <div class="dt-cols">

        <!-- En cours -->
        <div class="dt-col">
            <div class="dt-col-header">
                <span class="dt-col-titre">Lots en cours</span>
                <Tag v-if="lotsEnCours.length > 0" :value="lotsEnCours.length" rounded />
            </div>

            <div v-if="loading" class="dt-loading">
                <ProgressSpinner style="width:28px;height:28px" />
            </div>
            <p v-else-if="lotsEnCours.length === 0" class="dt-empty">
                Aucun lot en cours.
            </p>
            <ul v-else class="dt-liste">
                <li v-for="lot in lotsEnCours" :key="lot.id" class="dt-item">
                    <span
                        class="dt-gamme-badge"
                        :style="{ background: stylesGamme(lot.gamme_slug).fond, color: stylesGamme(lot.gamme_slug).couleur }"
                    >{{ lot.gamme_libelle }}</span>
                    <span class="dt-lot-num">{{ lot.numero_lot }}</span>
                    <span class="dt-lot-date">{{ formatDate(lot.date_production) }}</span>
                    <Tag
                        :value="infosStatut(lot.statut).label"
                        :severity="infosStatut(lot.statut).severity"
                        class="dt-statut-tag"
                    />
                </li>
            </ul>
        </div>

        <!-- Derniers stocks -->
        <div class="dt-col">
            <div class="dt-col-header">
                <span class="dt-col-titre">Dernières mises en stock</span>
            </div>

            <div v-if="loading" class="dt-loading">
                <ProgressSpinner style="width:28px;height:28px" />
            </div>
            <p v-else-if="derniersStocks.length === 0" class="dt-empty">
                Aucun lot stocké pour le moment.
            </p>
            <ul v-else class="dt-liste">
                <li v-for="lot in derniersStocks" :key="lot.id" class="dt-item">
                    <span
                        class="dt-gamme-badge"
                        :style="{ background: stylesGamme(lot.gamme_slug).fond, color: stylesGamme(lot.gamme_slug).couleur }"
                    >{{ lot.gamme_libelle }}</span>
                    <span class="dt-lot-num">{{ lot.numero_lot }}</span>
                    <span class="dt-lot-date">{{ formatDate(lot.date_production) }}</span>
                    <i class="pi pi-check-circle dt-icon-stock" />
                </li>
            </ul>
        </div>

    </div>

    <!-- ── Bande récapitulative par gamme ─────────────────────── -->
    <div v-if="parGamme.length > 0" class="dt-strip">
        <div
            v-for="g in parGamme"
            :key="g.id"
            class="dt-strip-gamme"
            @click="allerGamme(g.slug)"
        >
            <div
                class="dt-strip-icone"
                :style="{ background: stylesGamme(g.slug).fond, color: stylesGamme(g.slug).couleur }"
            >
                {{ g.libelle.substring(0, 2).toUpperCase() }}
            </div>
            <div class="dt-strip-info">
                <span class="dt-strip-nom">{{ g.libelle }}</span>
                <span class="dt-strip-stats">
                    {{ g.nb_produits }} produit{{ g.nb_produits !== 1 ? 's' : '' }}
                    · {{ g.nb_en_cours }} en cours
                </span>
            </div>
            <Button
                :label="`${g.nb_stocks_annee} lot${g.nb_stocks_annee !== 1 ? 's' : ''}`"
                icon="pi pi-list"
                text size="small"
                class="dt-strip-btn"
                @click.stop="allerLots(g.slug)"
            />
        </div>
    </div>

</div>
</template>

<style scoped>
/* ── En-tête ──────────────────────────────────────────────── */
.dt-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 1.5rem;
}

.dt-titre {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-color);
    margin: 0 0 2px;
}

.dt-sous-titre {
    font-size: 13px;
    color: var(--text-color-secondary);
    margin: 0;
}

/* ── KPIs ─────────────────────────────────────────────────── */
.dt-kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.dt-kpi {
    background: var(--surface-card);
    border: 1px solid var(--surface-border);
    border-radius: 10px;
    padding: 1.125rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.dt-kpi-accent {
    border-left: 3px solid #1D9E75;
}

.dt-kpi-val {
    font-size: 32px;
    font-weight: 700;
    color: var(--text-color);
    line-height: 1;
}

.dt-kpi-label {
    font-size: 12px;
    color: var(--text-color-secondary);
}

/* ── Deux colonnes ────────────────────────────────────────── */
.dt-cols {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.dt-col {
    background: var(--surface-card);
    border: 1px solid var(--surface-border);
    border-radius: 10px;
    padding: 1rem 1.125rem;
}

.dt-col-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.875rem;
}

.dt-col-titre {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-color);
    flex: 1;
}

/* ── Liste lots ───────────────────────────────────────────── */
.dt-liste {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.dt-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 0;
    border-bottom: 1px solid var(--surface-border);
}

.dt-item:last-child { border-bottom: none; }

.dt-gamme-badge {
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 4px;
    flex-shrink: 0;
    white-space: nowrap;
}

.dt-lot-num {
    font-family: monospace;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-color);
    flex-shrink: 0;
}

.dt-lot-date {
    font-size: 12px;
    color: var(--text-color-secondary);
    flex: 1;
}

.dt-statut-tag { font-size: 10px; flex-shrink: 0; }

.dt-icon-stock {
    color: #1D9E75;
    font-size: 14px;
    flex-shrink: 0;
}

/* ── Bande gammes ─────────────────────────────────────────── */
.dt-strip {
    background: var(--surface-card);
    border: 1px solid var(--surface-border);
    border-radius: 10px;
    overflow: hidden;
}

.dt-strip-gamme {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.875rem 1.125rem;
    border-bottom: 1px solid var(--surface-border);
    cursor: pointer;
    transition: background 0.12s;
}

.dt-strip-gamme:last-child { border-bottom: none; }

.dt-strip-gamme:hover { background: var(--surface-hover); }

.dt-strip-icone {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
}

.dt-strip-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.dt-strip-nom {
    font-weight: 600;
    font-size: 14px;
    color: var(--text-color);
}

.dt-strip-stats {
    font-size: 12px;
    color: var(--text-color-secondary);
}

.dt-strip-btn { flex-shrink: 0; }

/* ── États ────────────────────────────────────────────────── */
.dt-empty {
    color: #bbb;
    font-size: 13px;
    font-style: italic;
    margin: 0;
    padding: 8px 0;
}

.dt-loading {
    display: flex;
    justify-content: center;
    padding: 1rem 0;
}
</style>
