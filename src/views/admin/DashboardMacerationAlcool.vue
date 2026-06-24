<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axiosCrufiture from '@/plugins/axiosCrufiture';

const router = useRouter();

const COULEUR   = '#7F77DD';
const FOND      = '#EEEDFE';

const lots   = ref([]);
const loading = ref(true);

onMounted(charger);

async function charger() {
    loading.value = true;
    try {
        const res = await axiosCrufiture.get('/mace-alcool/lots');
        if (res.data?.status === 'success') lots.value = res.data.details;
    } catch {
        // silencieux
    } finally {
        loading.value = false;
    }
}

// ── KPIs calculés ─────────────────────────────────────────────
const kpis = computed(() => {
    const actifs       = lots.value.filter(l => !['stock', 'abandonne'].includes(l.statut));
    const alertesMac   = lots.value.filter(l => l.alerte_maceration);
    const alertesMat   = lots.value.filter(l => l.alerte_maturation);
    const stocksAnnee  = lots.value.filter(l => l.statut === 'stock');
    return {
        actifs:      actifs.length,
        alertesMac:  alertesMac.length,
        alertesMat:  alertesMat.length,
        stocks:      stocksAnnee.length,
    };
});

const lotsActifs   = computed(() => lots.value.filter(l => !['stock', 'abandonne'].includes(l.statut)));
const derniersStocks = computed(() => lots.value.filter(l => l.statut === 'stock').slice(0, 6));

// ── Statut ────────────────────────────────────────────────────
const STATUT_CONFIG = {
    preparation:   { label: 'Préparation',  severity: 'secondary', icon: 'pi-clock' },
    en_maceration: { label: 'Macération',   severity: 'info',      icon: 'pi-bolt' },
    filtration:    { label: 'Filtration',   severity: 'warning',   icon: 'pi-filter' },
    assemblage:    { label: 'Assemblage',   severity: 'warning',   icon: 'pi-th-large' },
    maturation:    { label: 'Maturation',   severity: 'info',      icon: 'pi-clock' },
    stock:         { label: 'En stock',     severity: 'success',   icon: 'pi-check-circle' },
    abandonne:     { label: 'Abandonné',    severity: 'danger',    icon: 'pi-times-circle' },
};
function infosStatut(s) {
    return STATUT_CONFIG[s] ?? { label: s, severity: 'secondary', icon: 'pi-circle' };
}

function formatDate(d) {
    if (!d) return '—';
    const [y, m, j] = d.split('-');
    return `${j}/${m}/${y}`;
}
</script>

<template>
<div class="col-12">

    <!-- ── En-tête ──────────────────────────────────────────── -->
    <div class="ma-header">
        <div>
            <div class="ma-badge-gamme" :style="{ background: FOND, color: COULEUR }">
                <i class="pi pi-fw pi-chart-pie" style="font-size:12px" />
                Macération alcoolique
            </div>
            <h2 class="ma-titre">Dashboard</h2>
            <p class="ma-sous-titre">Suivi des lots en cours et alertes</p>
        </div>
        <div class="ma-header-actions">
            <Button
                label="Nouvelle recette"
                icon="pi pi-plus"
                outlined
                @click="router.push('/dashboard/maceration_alcool/recettes')"
            />
            <Button
                label="Nouveau lot"
                icon="pi pi-plus"
                :style="{ background: COULEUR, borderColor: COULEUR }"
                @click="router.push('/dashboard/maceration_alcool/lots/nouveau')"
            />
        </div>
    </div>

    <!-- ── KPIs ─────────────────────────────────────────────── -->
    <div class="ma-kpis">
        <div class="ma-kpi" :class="{ 'ma-kpi-alerte': kpis.alertesMac > 0 || kpis.alertesMat > 0 }">
            <span class="ma-kpi-val">{{ loading ? '—' : kpis.actifs }}</span>
            <span class="ma-kpi-label">lot{{ kpis.actifs !== 1 ? 's' : '' }} en cours</span>
        </div>
        <div class="ma-kpi" :class="{ 'ma-kpi-alerte-rouge': kpis.alertesMac > 0 }">
            <span class="ma-kpi-val">{{ loading ? '—' : kpis.alertesMac }}</span>
            <span class="ma-kpi-label">alerte{{ kpis.alertesMac !== 1 ? 's' : '' }} macération</span>
        </div>
        <div class="ma-kpi" :class="{ 'ma-kpi-alerte-rouge': kpis.alertesMat > 0 }">
            <span class="ma-kpi-val">{{ loading ? '—' : kpis.alertesMat }}</span>
            <span class="ma-kpi-label">alerte{{ kpis.alertesMat !== 1 ? 's' : '' }} maturation</span>
        </div>
        <div class="ma-kpi">
            <span class="ma-kpi-val">{{ loading ? '—' : kpis.stocks }}</span>
            <span class="ma-kpi-label">stocké{{ kpis.stocks !== 1 ? 's' : '' }}</span>
        </div>
    </div>

    <!-- ── Alertes ───────────────────────────────────────────── -->
    <div v-if="!loading && (kpis.alertesMac > 0 || kpis.alertesMat > 0)" class="ma-alertes">
        <div
            v-for="lot in lots.filter(l => l.alerte_maceration || l.alerte_maturation)"
            :key="lot.id"
            class="ma-alerte-item"
            @click="router.push('/dashboard/maceration_alcool/lots/' + lot.id)"
        >
            <i class="pi pi-exclamation-triangle ma-alerte-icon" />
            <span class="ma-alerte-num">{{ lot.numero_lot }}</span>
            <span class="ma-alerte-msg">
                {{ lot.alerte_maceration ? 'Macération terminée — à filtrer' : 'Maturation terminée — à stocker' }}
            </span>
            <Button icon="pi pi-arrow-right" text rounded size="small" />
        </div>
    </div>

    <!-- ── Deux colonnes ─────────────────────────────────────── -->
    <div class="ma-cols">

        <!-- Lots en cours -->
        <div class="ma-col">
            <div class="ma-col-header">
                <span class="ma-col-titre">Lots en cours</span>
                <Tag v-if="lotsActifs.length" :value="lotsActifs.length" rounded />
            </div>

            <div v-if="loading" class="ma-loading"><ProgressSpinner style="width:28px;height:28px" /></div>
            <p v-else-if="lotsActifs.length === 0" class="ma-empty">Aucun lot en cours.</p>
            <ul v-else class="ma-liste">
                <li
                    v-for="lot in lotsActifs"
                    :key="lot.id"
                    class="ma-item"
                    :class="{ 'ma-item-alerte': lot.alerte_maceration || lot.alerte_maturation }"
                    @click="router.push('/dashboard/maceration_alcool/lots/' + lot.id)"
                >
                    <span class="ma-lot-num">{{ lot.numero_lot }}</span>
                    <span class="ma-lot-recette">{{ lot.recette_nom }}</span>
                    <i v-if="lot.alerte_maceration || lot.alerte_maturation"
                       class="pi pi-exclamation-triangle"
                       style="color:#f59e0b;font-size:12px;flex-shrink:0" />
                    <Tag
                        :value="infosStatut(lot.statut).label"
                        :severity="infosStatut(lot.statut).severity"
                        style="font-size:10px;flex-shrink:0"
                    />
                </li>
            </ul>
        </div>

        <!-- Derniers stocks -->
        <div class="ma-col">
            <div class="ma-col-header">
                <span class="ma-col-titre">Dernières mises en stock</span>
            </div>

            <div v-if="loading" class="ma-loading"><ProgressSpinner style="width:28px;height:28px" /></div>
            <p v-else-if="derniersStocks.length === 0" class="ma-empty">Aucun lot stocké.</p>
            <ul v-else class="ma-liste">
                <li
                    v-for="lot in derniersStocks"
                    :key="lot.id"
                    class="ma-item"
                    @click="router.push('/dashboard/maceration_alcool/lots/' + lot.id)"
                >
                    <span class="ma-lot-num">{{ lot.numero_lot }}</span>
                    <span class="ma-lot-recette">{{ lot.recette_nom }}</span>
                    <span class="ma-lot-date">{{ formatDate(lot.date_production) }}</span>
                    <i class="pi pi-check-circle" style="color:#1D9E75;font-size:14px;flex-shrink:0" />
                </li>
            </ul>
        </div>

    </div>

    <!-- ── Liens rapides ─────────────────────────────────────── -->
    <div class="ma-liens">
        <div class="ma-lien" @click="router.push('/dashboard/maceration_alcool/lots')">
            <i class="pi pi-list" :style="{ color: COULEUR }" />
            <span>Tous les lots</span>
        </div>
        <div class="ma-lien" @click="router.push('/dashboard/maceration_alcool/recettes')">
            <i class="pi pi-book" :style="{ color: COULEUR }" />
            <span>Recettes</span>
        </div>
        <div class="ma-lien" @click="router.push('/dashboard/maceration_alcool/lots/nouveau')">
            <i class="pi pi-plus-circle" :style="{ color: COULEUR }" />
            <span>Nouveau lot</span>
        </div>
    </div>

</div>
</template>

<style scoped>
.ma-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 1.5rem;
}
.ma-badge-gamme {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 5px;
    margin-bottom: 6px;
    letter-spacing: 0.3px;
}
.ma-titre { font-size: 22px; font-weight: 700; color: var(--text-color); margin: 0 0 2px; }
.ma-sous-titre { font-size: 13px; color: var(--text-color-secondary); margin: 0; }
.ma-header-actions { display: flex; gap: 8px; align-items: center; }

.ma-kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.ma-kpi {
    background: var(--surface-card);
    border: 1px solid var(--surface-border);
    border-radius: 10px;
    padding: 1.125rem 1.25rem;
    display: flex; flex-direction: column; gap: 4px;
}
.ma-kpi-alerte      { border-left: 3px solid #f59e0b; }
.ma-kpi-alerte-rouge { border-left: 3px solid #ef4444; }
.ma-kpi-val   { font-size: 32px; font-weight: 700; color: var(--text-color); line-height: 1; }
.ma-kpi-label { font-size: 12px; color: var(--text-color-secondary); }

.ma-alertes {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 10px;
    margin-bottom: 1.5rem;
    overflow: hidden;
}
.ma-alerte-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 1.125rem;
    border-bottom: 1px solid #fde68a;
    cursor: pointer;
    transition: background 0.12s;
}
.ma-alerte-item:last-child { border-bottom: none; }
.ma-alerte-item:hover { background: #fef3c7; }
.ma-alerte-icon { color: #f59e0b; font-size: 14px; flex-shrink: 0; }
.ma-alerte-num  { font-family: monospace; font-size: 13px; font-weight: 700; flex-shrink: 0; }
.ma-alerte-msg  { font-size: 13px; color: #92400e; flex: 1; }

.ma-cols {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.ma-col {
    background: var(--surface-card);
    border: 1px solid var(--surface-border);
    border-radius: 10px;
    padding: 1rem 1.125rem;
}
.ma-col-header {
    display: flex; align-items: center; gap: 0.5rem;
    margin-bottom: 0.875rem;
}
.ma-col-titre { font-size: 14px; font-weight: 600; color: var(--text-color); flex: 1; }
.ma-liste { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 4px; }
.ma-item {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 6px;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.12s;
    border-bottom: 1px solid var(--surface-border);
}
.ma-item:last-child { border-bottom: none; }
.ma-item:hover { background: var(--surface-hover); }
.ma-item-alerte { background: #fffbeb; }
.ma-lot-num     { font-family: monospace; font-size: 13px; font-weight: 700; flex-shrink: 0; }
.ma-lot-recette { font-size: 12px; color: var(--text-color-secondary); flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ma-lot-date    { font-size: 12px; color: var(--text-color-secondary); flex-shrink: 0; }

.ma-liens {
    display: flex;
    gap: 1rem;
}
.ma-lien {
    flex: 1;
    background: var(--surface-card);
    border: 1px solid var(--surface-border);
    border-radius: 10px;
    padding: 1rem;
    display: flex; align-items: center; gap: 10px;
    cursor: pointer;
    transition: background 0.12s, border-color 0.12s;
    font-size: 14px; font-weight: 500; color: var(--text-color);
}
.ma-lien:hover { background: #EEEDFE; border-color: #7F77DD; }
.ma-lien i { font-size: 18px; }

.ma-empty  { color: #bbb; font-size: 13px; font-style: italic; margin: 0; padding: 8px 0; }
.ma-loading { display: flex; justify-content: center; padding: 1rem 0; }
</style>
