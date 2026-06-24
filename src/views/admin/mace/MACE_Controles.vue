<script setup>
const props = defineProps({ form: Object });

function ajouterControle() {
    props.form.controles.push({ etape_label: '', point_controle: '', valeur_cible: '', action_corrective: '' });
}
function supprimerControle(idx) {
    props.form.controles.splice(idx, 1);
}
</script>

<template>
    <div class="ft-section">
        <div class="ft-section-title">Points de contrôle</div>

        <div v-if="form.controles.length === 0" class="ft-vide">
            Aucun point de contrôle — recommandé pour les étapes critiques (ABV, filtration, assemblage, mise en bouteille).
        </div>

        <div class="ft-controles">
            <div v-for="(ctrl, idx) in form.controles" :key="idx" class="ft-ctrl-bloc">
                <div class="ft-ctrl-num">{{ idx + 1 }}</div>
                <div class="ft-ctrl-champs">
                    <div class="ft-ctrl-row">
                        <div class="ft-field">
                            <label class="ft-label">Étape</label>
                            <InputText v-model="form.controles[idx].etape_label" placeholder="ex : Filtration" />
                        </div>
                        <div class="ft-field ft-field-large">
                            <label class="ft-label">Point de contrôle *</label>
                            <InputText v-model="form.controles[idx].point_controle" placeholder="ex : Aspect du macérat après filtration" />
                        </div>
                    </div>
                    <div class="ft-ctrl-row">
                        <div class="ft-field">
                            <label class="ft-label">Valeur cible</label>
                            <InputText v-model="form.controles[idx].valeur_cible" placeholder="ex : Limpide, couleur ambrée" />
                        </div>
                        <div class="ft-field ft-field-large">
                            <label class="ft-label">Action corrective</label>
                            <InputText v-model="form.controles[idx].action_corrective" placeholder="ex : Refiltrer sur filtre fin" />
                        </div>
                    </div>
                </div>
                <Button icon="pi pi-trash" class="p-button-text p-button-sm p-button-danger ft-ctrl-del" @click="supprimerControle(idx)" />
            </div>
        </div>

        <Button
            label="Ajouter un point de contrôle"
            icon="pi pi-plus"
            class="p-button-outlined p-button-sm"
            style="margin-top:0.75rem"
            @click="ajouterControle"
        />
    </div>
</template>

<style scoped>
.ft-section { width: 100%; }
.ft-section-title { font-size: 15px; font-weight: 700; color: var(--text-color); margin-bottom: 1.25rem; }
.ft-vide { color: var(--text-color-secondary); font-size: 13px; font-style: italic; margin-bottom: 1rem; }
.ft-controles { display: flex; flex-direction: column; gap: 0.75rem; }
.ft-ctrl-bloc { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.75rem; border-radius: 6px; background: var(--surface-50, #fafafa); border: 1px solid var(--surface-border); }
.ft-ctrl-num { width: 24px; height: 24px; border-radius: 4px; background: var(--surface-200, #e5e5e5); font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
.ft-ctrl-champs { flex: 1; display: flex; flex-direction: column; gap: 0.5rem; }
.ft-ctrl-row { display: flex; gap: 0.75rem; }
.ft-ctrl-del { flex-shrink: 0; margin-top: 2px; }
.ft-field { display: flex; flex-direction: column; gap: 0.25rem; flex: 1; }
.ft-field-large { flex: 2; }
.ft-label { font-size: 11px; font-weight: 600; color: var(--text-color-secondary); }
.ft-field :deep(.p-inputtext) { width: 100%; font-size: 13px; }
</style>
