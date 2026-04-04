<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axiosCrufiture from '@/plugins/axiosCrufiture';

const router = useRouter();

const lots    = ref([]);
const saveurs = ref([]);
const loading = ref(true);

const filtres = reactive({
    numero:    '',
    saveur_id: null,
});

const statutOptions = [
    { label: 'Tous les statuts', value: null },
    { label: 'Préparation',      value: 'preparation' },
    { label: 'En repos',         value: 'en_repos' },
    { label: 'Production',       value: 'production' },
    { label: 'Stock',            value: 'stock' },
    { label: 'Abandonné',        value: 'abandonné' },
];

const saveurOptions = computed(() => {
    const opts = [{ label: 'Toutes les saveurs', value: null }];
    saveurs.value.forEach(s => opts.push({ label: s.nom, value: s.id }));
    return opts;
});

// ── Chargement ────────────────────────────────────────────────
const charger = async () => {
    loading.value = true;
    try {
        const params = {};
        if (filtres.numero)    params.numero    = filtres.numero;
        if (filtres.saveur_id) params.saveur_id = filtres.saveur_id;
        const res = await axiosCrufiture.get('/lots', { params });
        lots.value = res.data.details || [];
    } catch (e) {
        lots.value = [];
    } finally {
        loading.value = false;
    }
};

const chargerSaveurs = async () => {
    try {
        const res = await axiosCrufiture.get('/saveurs');
        saveurs.value = res.data.details || [];
    } catch (e) {}
};

onMounted(() => {
    chargerSaveurs();
    charger();
});

// ── Statuts ───────────────────────────────────────────────────
const statutConfig = {
    preparation: { label: 'Préparation', severity: 'secondary' },
    en_repos:    { label: 'En repos',    severity: 'info'      },
    production:  { label: 'Production',  severity: 'warning'   },
    stock:       { label: 'Stock',       severity: 'success'   },
    'abandonné': { label: 'Abandonné',   severity: 'danger'    },
};

const getStatutSeverity = (s) => statutConfig[s]?.severity ?? 'secondary';
const getStatutLabel    = (s) => statutConfig[s]?.label    ?? s;

// ── Poids affiché ─────────────────────────────────────────────
const poidsPrincipal = (lot) => {
    if (lot.statut === 'stock' && lot.poids_reel_kg != null) {
        return { val: lot.poids_reel_kg.toFixed(3) + ' kg', label: 'réel' };
    }
    if (lot.cible_kg != null && lot.cible_kg > 0) {
        return { val: lot.cible_kg.toFixed(3) + ' kg', label: 'cible' };
    }
    return null;
};

// ── Navigation ────────────────────────────────────────────────
const ouvrirLot  = (lot) => router.push('/dashboard/lots/' + lot.id);
const nouveauLot = ()    => router.push('/dashboard/lots/nouveau');

// ── Recherche avec debounce ───────────────────────────────────
let debounceTimer = null;
const onRechercheNumero = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(charger, 300);
};
</script>

<template>
<div class="col-12">
<PageCard titre="Lots de production">
    <template #actions>
        <Button label="Nouveau lot" icon="pi pi-plus" size="small" @click="nouveauLot" />
    </template>

    <!-- Filtres -->
    <div class="flex flex-wrap gap-2 mb-4 align-items-center">
        <IconField iconPosition="left" style="width:180px">
            <InputIcon class="pi pi-search" />
            <InputText
                v-model="filtres.numero"
                placeholder="N° lot…"
                style="width:100%"
                @input="onRechercheNumero"
            />
        </IconField>
        <Dropdown
            v-model="filtres.saveur_id"
            :options="saveurOptions"
            optionLabel="label"
            optionValue="value"
            style="min-width:180px"
            @change="charger"
        />
    </div>

    <!-- Tableau -->
    <DataTable
        :value="lots"
        :loading="loading"
        rowHover
        @row-click="(e) => ouvrirLot(e.data)"
        style="cursor:pointer"
    >
        <Column field="numero_lot" header="N° lot" style="width:100px;font-family:monospace;font-weight:600" />
        <Column field="date_production" header="Date" style="width:110px" />
        <Column field="saveur_nom" header="Saveur" />
        <Column field="recette_titre" header="Recette">
            <template #body="{ data }">
                <span class="text-color-secondary text-sm">{{ data.recette_titre ?? '—' }}</span>
            </template>
        </Column>
        <Column header="Statut" style="width:130px">
            <template #body="{ data }">
                <Tag
                    :value="getStatutLabel(data.statut)"
                    :severity="getStatutSeverity(data.statut)"
                />
            </template>
        </Column>
        <Column header="Poids" style="width:130px">
            <template #body="{ data }">
                <template v-if="poidsPrincipal(data)">
                    <span>{{ poidsPrincipal(data).val }}</span>
                    <span class="text-color-secondary text-xs ml-1">({{ poidsPrincipal(data).label }})</span>
                </template>
                <span v-else class="text-color-secondary">—</span>
            </template>
        </Column>
        <Column header="Relevés" style="width:80px;text-align:center">
            <template #body="{ data }">
                <span class="text-color-secondary">{{ data.nb_releves }}</span>
            </template>
        </Column>
        <Column style="width:50px">
            <template #body="{ data }">
                <Button icon="pi pi-chevron-right" text rounded size="small" @click.stop="ouvrirLot(data)" />
            </template>
        </Column>

        <template #empty>
            <div class="text-center text-color-secondary py-4">Aucun lot trouvé.</div>
        </template>
    </DataTable>
</PageCard>
</div>
</template>
