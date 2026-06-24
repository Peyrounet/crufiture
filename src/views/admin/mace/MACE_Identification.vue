<script setup>
const props = defineProps({ form: Object, isNouvelle: Boolean });

const difficultOptions = [
    { label: '★☆☆  Facile',    value: 1 },
    { label: '★★☆  Moyen',     value: 2 },
    { label: '★★★  Difficile', value: 3 },
];
</script>

<template>
    <div class="ft-section">
        <div class="ft-section-title">Identification</div>

        <!-- Nom + famille — création uniquement -->
        <template v-if="isNouvelle">
            <div class="ft-field ft-field-nom">
                <label class="ft-label">Nom de la recette *</label>
                <InputText v-model="form.nom" placeholder="ex : Macérat de citron sur eau-de-vie de prune" class="ft-input-nom" />
            </div>
            <div class="ft-row">
                <div class="ft-field">
                    <label class="ft-label">Famille</label>
                    <InputText v-model="form.famille" placeholder="ex : Liqueurs / Eaux-de-vie" />
                </div>
            </div>
        </template>

        <!-- Notes de version -->
        <div class="ft-field" style="margin-bottom:1rem">
            <label class="ft-label">Notes de version</label>
            <InputText v-model="form.notes_version" placeholder="ex : Premier essai, ajout d'épices…" />
        </div>

        <!-- Production -->
        <div class="ft-section-subtitle">Production</div>
        <div class="ft-row">
            <div class="ft-field">
                <label class="ft-label">Nombre d'unités</label>
                <InputNumber v-model="form.nb_unites" :min="1" :max="9999" show-buttons />
            </div>
            <div class="ft-field">
                <label class="ft-label">Unité de production</label>
                <InputText v-model="form.unite_production" placeholder="ex : bouteille 70 cl" />
            </div>
        </div>

        <div class="ft-row">
            <div class="ft-field">
                <label class="ft-label">Difficulté</label>
                <Dropdown v-model="form.difficulte" :options="difficultOptions" option-label="label" option-value="value" placeholder="Choisir" show-clear />
            </div>
            <div class="ft-field">
                <label class="ft-label">Conservation</label>
                <InputText v-model="form.conservation" placeholder="ex : 3 ans à l'abri de la lumière" />
            </div>
        </div>
    </div>
</template>

<style scoped>
.ft-section { width: 100%; }
.ft-section-title { font-size: 15px; font-weight: 700; color: var(--text-color); margin-bottom: 1.25rem; }
.ft-section-subtitle { font-size: 12px; font-weight: 600; color: var(--text-color-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin: 1.25rem 0 0.5rem; }
.ft-field { display: flex; flex-direction: column; gap: 0.375rem; flex: 1; }
.ft-field-nom { margin-bottom: 1rem; }
.ft-label { font-size: 12px; font-weight: 600; color: var(--text-color-secondary); }
.ft-row { display: flex; gap: 1rem; margin-bottom: 1rem; }
.ft-input-nom { font-size: 18px; font-weight: 600; }
.ft-field :deep(.p-inputtext),
.ft-field :deep(.p-dropdown),
.ft-field :deep(.p-inputnumber) { width: 100%; }
</style>
