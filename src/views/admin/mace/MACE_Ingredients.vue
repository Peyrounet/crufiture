<script setup>
import { ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import axiosStock from '@/plugins/axiosStock';

const props = defineProps({ form: Object });
const form  = props.form;
const toast = useToast();

const UNITES = [
    { label: 'Masse',  items: [{ label: 'g' }, { label: 'kg' }] },
    { label: 'Volume', items: [{ label: 'ml' }, { label: 'cl' }, { label: 'dl' }, { label: 'L' }] },
    { label: 'Pièce',  items: [{ label: 'pièce' }, { label: 'sachet' }, { label: 'bouquet' }, { label: 'kg' }] },
];
const UNITES_FLAT = UNITES.flatMap(g => g.items.map(i => i.label));
const STOCK_VERS_MACE = { kg: 'kg', L: 'L', piece: 'pièce', g: 'g', ml: 'ml' };

// ── Autocomplete /stock ───────────────────────────────────────
const recherche   = ref('');
const suggestions = ref([]);

async function chercher(event) {
    const q = (event.query ?? '').trim();
    if (q.length < 2) { suggestions.value = []; return; }
    try {
        const res = await axiosStock.get('/articles', { params: { q, limit: 15 } });
        const items = Array.isArray(res.data?.details) ? res.data.details : [];
        suggestions.value = items.map(a => ({
            label:            a.libelle,
            stock_article_id: Number(a.id),
            unite:            a.unite ?? '',
        }));
        if (suggestions.value.length === 0) {
            suggestions.value = [{ label: `Saisir "${q}" manuellement`, stock_article_id: null, unite: '', _manuel: true }];
        }
    } catch {
        suggestions.value = [];
    }
}

function selectionner(event) {
    const item = event.value;
    if (!item) return;

    if (item._manuel) {
        const libelle = (recherche.value ?? '').trim().replace(/^Saisir "/, '').replace(/" manuellement$/, '');
        form.ingredients.push({
            stock_article_id: null,
            libelle,
            quantite:   0,
            coeff_perte:1.000,
            unite:      '',
            note:       '',
        });
        recherche.value = '';
        return;
    }

    if (form.ingredients.some(i => i.stock_article_id === item.stock_article_id)) {
        toast.add({ severity: 'warn', summary: 'Déjà présent', detail: `"${item.label}" est déjà dans la recette.`, life: 2500 });
        recherche.value = '';
        return;
    }

    const unitePrefill = STOCK_VERS_MACE[item.unite] ?? (UNITES_FLAT.includes(item.unite) ? item.unite : '');
    form.ingredients.push({
        stock_article_id: item.stock_article_id,
        libelle:    item.label,
        quantite:   0,
        coeff_perte:1.000,
        unite:      unitePrefill,
        note:       '',
    });
    recherche.value = '';
}

function supprimerIngredient(idx) {
    form.ingredients.splice(idx, 1);
}
</script>

<template>
    <div class="ft-section">
        <div class="ft-section-title">
            Ingrédients
            <span class="ft-hint">pour {{ form.nb_unites }} {{ form.unite_production || 'unité(s)' }}</span>
        </div>

        <!-- Autocomplete stock -->
        <div class="ft-search">
            <AutoComplete
                v-model="recherche"
                :suggestions="suggestions"
                option-label="label"
                placeholder="Rechercher un article dans le stock…"
                @complete="chercher"
                @item-select="selectionner"
                class="ft-autocomplete"
                :delay="300"
            />
        </div>

        <div v-if="form.ingredients.length === 0" class="ft-vide">
            Aucun ingrédient — recherchez un article stock ci-dessus.
        </div>

        <div v-else class="ft-ing-table">
            <div class="ft-ing-header">
                <span class="ft-ing-col-nom">Ingrédient</span>
                <span class="ft-ing-col-qte">Qté brute</span>
                <span class="ft-ing-col-unite">Unité</span>
                <span class="ft-ing-col-coeff">Coeff perte</span>
                <span class="ft-ing-col-nette">Qté nette</span>
                <span class="ft-ing-col-note">Note</span>
                <span class="ft-ing-col-del"></span>
            </div>

            <div v-for="(ing, idx) in form.ingredients" :key="idx" class="ft-ing-row">
                <span class="ft-ing-col-nom">
                    <InputText v-model="form.ingredients[idx].libelle" placeholder="Libellé" class="ft-ing-libelle" size="small" />
                    <span v-if="!ing.stock_article_id" class="ft-badge-stock" title="Non lié au stock">⚠ stock</span>
                </span>
                <span class="ft-ing-col-qte">
                    <InputNumber v-model="form.ingredients[idx].quantite" :min="0" :maxFractionDigits="3" class="ft-input-sm" />
                </span>
                <span class="ft-ing-col-unite">
                    <Dropdown
                        v-model="form.ingredients[idx].unite"
                        :options="UNITES"
                        option-label="label"
                        option-value="label"
                        option-group-label="label"
                        option-group-children="items"
                        placeholder="—"
                        class="ft-input-unite"
                    />
                </span>
                <span class="ft-ing-col-coeff">
                    <InputNumber v-model="form.ingredients[idx].coeff_perte" :min="1" :max="9.999" :maxFractionDigits="3" class="ft-input-sm" />
                </span>
                <span class="ft-ing-col-nette">
                    {{ ing.coeff_perte > 0 ? (Math.round((ing.quantite / ing.coeff_perte) * 1000) / 1000).toLocaleString('fr-FR') : '—' }} {{ ing.unite }}
                </span>
                <span class="ft-ing-col-note">
                    <InputText v-model="form.ingredients[idx].note" placeholder="Note…" size="small" class="ft-ing-note" />
                </span>
                <span class="ft-ing-col-del">
                    <Button icon="pi pi-trash" class="p-button-text p-button-sm p-button-danger" @click="supprimerIngredient(idx)" />
                </span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.ft-section { width: 100%; }
.ft-section-title { font-size: 15px; font-weight: 700; color: var(--text-color); margin-bottom: 1.25rem; display: flex; align-items: baseline; gap: 0.75rem; }
.ft-hint { font-size: 12px; font-weight: 400; color: var(--text-color-secondary); }
.ft-vide { color: var(--text-color-secondary); font-size: 13px; font-style: italic; margin: 1rem 0; }

.ft-search { margin-bottom: 1.25rem; }
.ft-autocomplete { width: 100%; }
.ft-autocomplete :deep(.p-autocomplete-input) { width: 100%; }

.ft-ing-table { border: 1px solid var(--surface-border); border-radius: 6px; overflow: hidden; }
.ft-ing-header,
.ft-ing-row { display: grid; grid-template-columns: 1.6fr 85px 90px 100px 100px 1fr 36px; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; }
.ft-ing-header { background: var(--surface-100, #f5f5f5); font-size: 11px; font-weight: 700; color: var(--text-color-secondary); text-transform: uppercase; letter-spacing: 0.3px; }
.ft-ing-row { border-top: 1px solid var(--surface-border); font-size: 13px; }
.ft-ing-row:hover { background: var(--surface-50, #fafafa); }
.ft-ing-col-nom { display: flex; align-items: center; gap: 0.375rem; min-width: 0; }
.ft-ing-libelle { width: 100%; font-size: 13px; }
.ft-ing-note { width: 100%; font-size: 12px; }
.ft-badge-stock { flex-shrink: 0; font-size: 10px; background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; border-radius: 4px; padding: 1px 5px; white-space: nowrap; }
.ft-input-sm :deep(.p-inputnumber-input) { width: 100%; font-size: 13px; padding: 0.375rem 0.5rem; }
.ft-input-unite { width: 100%; }
.ft-input-unite :deep(.p-dropdown) { width: 100%; }
.ft-input-unite :deep(.p-dropdown-label) { font-size: 13px; padding: 0.375rem 0.5rem; }
.ft-ing-col-nette { font-size: 12px; color: var(--text-color-secondary); white-space: nowrap; }
</style>
