<script setup>
import { ref, onMounted } from 'vue';
import axiosCrufiture from '@/plugins/axiosCrufiture';

const loading = ref(true);
const data    = ref(null);

onMounted(async () => {
    const res = await axiosCrufiture.get('/dashboard');
    if (res.data?.status === 'success') data.value = res.data.details;
    loading.value = false;
});

const statutLabel = {
    formule:       { label: 'Formulé',      severity: 'info'    },
    en_production: { label: 'En production', severity: 'warning' },
    mis_en_pot:    { label: 'Mis en pot',    severity: 'success' },
    controle:      { label: 'Contrôlé',      severity: 'success' },
    archive:       { label: 'Archivé',       severity: 'secondary' },
};

const formatDate = (s) => s
    ? new Date(s + 'T00:00:00').toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' })
    : '-';
</script>

<template>
<div class="col-12">

    <div v-if="loading" class="flex justify-content-center p-6">
        <ProgressSpinner />
    </div>

    <div v-else-if="data" class="grid">

        <!-- ── KPIs annuels ─────────────────────────────────────── -->
        <div class="col-12 md:col-4">
            <PageCard titre="Lots cette année">
                <div class="cruf-stat-big">{{ data.annee?.nb_lots ?? 0 }}</div>
                <div class="cruf-stat-label">lots produits</div>
            </PageCard>
        </div>

        <div class="col-12 md:col-4">
            <PageCard titre="Kilogrammes produits">
                <div class="cruf-stat-big">{{ data.annee?.kg_produits?.toFixed(2) ?? '0.00' }}</div>
                <div class="cruf-stat-label">kg de crufiture</div>
            </PageCard>
        </div>

        <div class="col-12 md:col-4">
            <PageCard titre="Stock en jarres">
                <div class="cruf-stat-big">{{ data.stock_kg?.toFixed(3) ?? '0.000' }}</div>
                <div class="cruf-stat-label">kg disponibles</div>
            </PageCard>
        </div>

        <!-- ── Statuts ──────────────────────────────────────────── -->
        <div class="col-12 md:col-6">
            <PageCard titre="Répartition par statut">
                <div v-if="data.statuts && Object.keys(data.statuts).length" class="flex flex-wrap gap-3 pt-1">
                    <div
                        v-for="(nb, statut) in data.statuts"
                        :key="statut"
                        class="cruf-statut-chip"
                    >
                        <Tag
                            :severity="statutLabel[statut]?.severity ?? 'info'"
                            :value="(statutLabel[statut]?.label ?? statut) + ' · ' + nb"
                        />
                    </div>
                </div>
                <p v-else class="cruf-empty">Aucun lot enregistré.</p>
            </PageCard>
        </div>

        <!-- ── Derniers lots ────────────────────────────────────── -->
        <div class="col-12 md:col-6">
            <PageCard titre="Derniers lots">
                <DataTable
                    v-if="data.derniers_lots?.length"
                    :value="data.derniers_lots"
                    size="small"
                    :showGridlines="false"
                    stripedRows
                >
                    <Column field="numero_lot" header="N° lot" />
                    <Column field="saveur" header="Saveur" />
                    <Column header="Date">
                        <template #body="{ data: row }">
                            {{ formatDate(row.date_production) }}
                        </template>
                    </Column>
                    <Column header="Statut">
                        <template #body="{ data: row }">
                            <Tag
                                :severity="statutLabel[row.statut]?.severity ?? 'info'"
                                :value="statutLabel[row.statut]?.label ?? row.statut"
                            />
                        </template>
                    </Column>
                    <Column header="Kg réel">
                        <template #body="{ data: row }">
                            {{ row.poids_reel_kg != null ? row.poids_reel_kg + ' kg' : '—' }}
                        </template>
                    </Column>
                </DataTable>
                <p v-else class="cruf-empty">Aucun lot enregistré.</p>
            </PageCard>
        </div>

    </div>

    <div v-else class="col-12">
        <PageCard titre="Tableau de bord">
            <p class="cruf-empty">
                <i class="pi pi-exclamation-triangle mr-2"></i>
                Impossible de charger les données.
            </p>
        </PageCard>
    </div>

</div>
</template>

<style scoped>
.cruf-stat-big {
    font-size: 40px;
    font-weight: 800;
    color: var(--primary-color);
    line-height: 1;
    margin-bottom: 4px;
}
.cruf-stat-label {
    font-size: 13px;
    color: #888;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.cruf-statut-chip { display: inline-flex; }
.cruf-empty {
    color: #aaa;
    font-size: 13px;
    font-style: italic;
    padding: 8px 0;
    margin: 0;
}
</style>
