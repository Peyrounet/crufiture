<script setup>
const props = defineProps({ form: Object });
</script>

<template>
    <div class="ft-section">
        <div class="ft-section-title">Paramètres macération alcoolique</div>

        <div class="ft-section-subtitle">Durées</div>
        <div class="ft-row">
            <div class="ft-field">
                <label class="ft-label">Macération cible (jours)</label>
                <InputNumber v-model="form.duree_maceration_cible_j" :min="0" :max="999" show-buttons suffix=" j" />
            </div>
            <div class="ft-field">
                <label class="ft-label">Maturation cible (jours)</label>
                <InputNumber v-model="form.duree_maturation_cible_j" :min="0" :max="999" show-buttons suffix=" j" />
            </div>
        </div>

        <div class="ft-section-subtitle">Cibles analytiques</div>
        <div class="ft-row">
            <div class="ft-field">
                <label class="ft-label">ABV cible (%vol)</label>
                <InputNumber v-model="form.abv_cible_pct" :min="0" :max="100" :maxFractionDigits="2" suffix=" %" />
            </div>
            <div class="ft-field">
                <label class="ft-label">Brix cible — liqueurs uniquement (°Bx)</label>
                <InputNumber v-model="form.brix_cible" :min="0" :max="100" :maxFractionDigits="2" suffix=" °Bx" :disabled="!form.avec_assemblage" />
            </div>
        </div>

        <div class="ft-section-subtitle">Type de produit</div>
        <div class="ft-field assemblage-field">
            <div class="assemblage-row">
                <InputSwitch v-model="form.avec_assemblage" />
                <div class="assemblage-label">
                    <span class="assemblage-titre">{{ form.avec_assemblage ? 'Liqueur — avec assemblage (ajout sirop de sucre)' : 'Eau-de-vie — sans assemblage' }}</span>
                    <span class="assemblage-hint">{{ form.avec_assemblage ? 'Renseignez le Brix cible ci-dessus.' : 'Le Brix cible ne s\'applique pas.' }}</span>
                </div>
            </div>
        </div>

        <div class="ft-section-subtitle">Matériel</div>
        <div class="ft-field">
            <label class="ft-label">Matériel nécessaire</label>
            <Textarea v-model="form.materiel" :rows="2" auto-resize placeholder="ex : Bonbonne 5 L, filtre à café, entonnoir, capsuleuse…" />
        </div>
    </div>
</template>

<style scoped>
.ft-section { width: 100%; }
.ft-section-title { font-size: 15px; font-weight: 700; color: var(--text-color); margin-bottom: 1.25rem; }
.ft-section-subtitle { font-size: 12px; font-weight: 600; color: var(--text-color-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin: 1.25rem 0 0.5rem; }
.ft-field { display: flex; flex-direction: column; gap: 0.375rem; flex: 1; }
.ft-label { font-size: 12px; font-weight: 600; color: var(--text-color-secondary); }
.ft-row { display: flex; gap: 1rem; margin-bottom: 1rem; }
.ft-field :deep(.p-inputtext),
.ft-field :deep(.p-dropdown),
.ft-field :deep(.p-inputnumber),
.ft-field :deep(.p-inputtextarea) { width: 100%; }

.assemblage-field { margin-bottom: 1rem; }
.assemblage-row { display: flex; align-items: center; gap: 1rem; padding: 0.875rem 1rem; background: var(--surface-50, #fafafa); border: 1px solid var(--surface-border); border-radius: 8px; }
.assemblage-label { display: flex; flex-direction: column; gap: 2px; }
.assemblage-titre { font-size: 14px; font-weight: 600; color: var(--text-color); }
.assemblage-hint { font-size: 12px; color: var(--text-color-secondary); }
</style>
