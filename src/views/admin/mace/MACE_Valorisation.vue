<script setup>
const props = defineProps({ form: Object });
</script>

<template>
    <div class="ft-section">
        <div class="ft-section-title">Valorisation & Provenance</div>

        <div class="ft-section-subtitle">Notes libres</div>
        <div class="ft-field" style="margin-bottom:1.5rem">
            <label class="ft-label">Description — histoire, provenance, accord</label>
            <Textarea
                v-model="form.description"
                :rows="8"
                auto-resize
                placeholder="ex : Recette familiale revisitée — l'alcool de base est notre eau-de-vie de prune distillée en 2024. Les citrons viennent de nos citronniers en serre.&#10;&#10;Accord suggéré : digestif frais ou en cocktail d'été.&#10;&#10;Certifié bio — production propre."
            />
            <span class="ft-sublabel">Contenu libre : provenance de l'alcool de base, origine des matières premières, histoire de la recette, accords suggérés, informations pour les contrôles bio.</span>
        </div>

        <div class="ft-hint-block">
            <i class="pi pi-info-circle" style="font-size:13px;color:#7F77DD;flex-shrink:0" />
            <span>Ce texte apparaît dans la fiche technique PDF. Il peut être communiqué lors des ventes directes ou présenté lors des contrôles bio.</span>
        </div>
    </div>
</template>

<style scoped>
.ft-section { width: 100%; }
.ft-section-title { font-size: 15px; font-weight: 700; color: var(--text-color); margin-bottom: 1.25rem; }
.ft-section-subtitle { font-size: 12px; font-weight: 600; color: var(--text-color-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 0.5rem; }
.ft-field { display: flex; flex-direction: column; gap: 0.375rem; }
.ft-label { font-size: 12px; font-weight: 600; color: var(--text-color-secondary); }
.ft-sublabel { font-size: 11px; color: var(--text-color-secondary); font-style: italic; line-height: 1.4; }
.ft-field :deep(.p-inputtextarea) { width: 100%; }

.ft-hint-block { display: flex; align-items: flex-start; gap: 0.625rem; padding: 0.75rem 1rem; background: #EEEDFE; border: 1px solid #C4C0F5; border-radius: 6px; font-size: 12px; color: #4B4595; line-height: 1.5; }
</style>
