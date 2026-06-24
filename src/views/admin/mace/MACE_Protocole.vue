<script setup>
import { computed } from 'vue';
import draggable from 'vuedraggable';

const props = defineProps({ form: Object });

// Numérotation globale continue
const numerosEtapes = computed(() => {
    let num = 0;
    return props.form.phases.map(phase => phase.etapes.map(() => ++num));
});

function ajouterPhase() {
    props.form.phases.push({ temporalite: '', label: '', etapes: [] });
}
function supprimerPhase(pi) {
    props.form.phases.splice(pi, 1);
}
function ajouterEtape(pi) {
    props.form.phases[pi].etapes.push({ _uid: Date.now() + '-' + Math.random(), description: '' });
}
function supprimerEtape(pi, ei) {
    props.form.phases[pi].etapes.splice(ei, 1);
}
</script>

<template>
    <div class="ft-section">
        <div class="ft-section-title">Protocole de fabrication</div>

        <div v-if="form.phases.length === 0" class="ft-vide">
            Aucune phase — ajoutez une phase pour commencer le protocole.
        </div>

        <div class="ft-phases">
            <div v-for="(phase, pi) in form.phases" :key="pi" class="ft-phase">

                <div class="ft-phase-header">
                    <span class="ft-phase-icon pi pi-list" />
                    <InputText
                        v-model="form.phases[pi].temporalite"
                        placeholder="Moment (ex : J0, J0–J30)"
                        class="ft-phase-temporalite-input"
                        size="small"
                    />
                    <span class="ft-phase-sep">—</span>
                    <InputText
                        v-model="form.phases[pi].label"
                        placeholder="Activité (ex : Préparation du macérat)"
                        class="ft-phase-label-input"
                        size="small"
                    />
                    <Button
                        icon="pi pi-trash"
                        text
                        rounded
                        severity="danger"
                        size="small"
                        v-tooltip.top="'Supprimer la phase et ses étapes'"
                        @click="supprimerPhase(pi)"
                    />
                </div>

                <draggable
                    v-model="form.phases[pi].etapes"
                    :group="{ name: 'etapes' }"
                    item-key="_uid"
                    handle=".drag-handle"
                    class="ft-etapes"
                    ghost-class="ft-etape-ghost"
                >
                    <template #item="{ element: etape, index: ei }">
                        <div class="ft-etape">
                            <span class="drag-handle pi pi-ellipsis-v" title="Déplacer" />
                            <div class="ft-etape-num">{{ numerosEtapes[pi]?.[ei] ?? '?' }}</div>
                            <Textarea
                                v-model="form.phases[pi].etapes[ei].description"
                                :rows="2"
                                auto-resize
                                placeholder="Décrivez l'étape…"
                                class="ft-etape-input"
                            />
                            <Button
                                icon="pi pi-trash"
                                text
                                rounded
                                severity="danger"
                                size="small"
                                @click="supprimerEtape(pi, ei)"
                            />
                        </div>
                    </template>
                    <template #footer>
                        <div v-if="form.phases[pi].etapes.length === 0" class="ft-phase-vide">
                            Aucune étape — ajoutez-en une ou faites glisser depuis une autre phase.
                        </div>
                    </template>
                </draggable>

                <Button
                    label="Ajouter une étape"
                    icon="pi pi-plus"
                    text
                    size="small"
                    style="margin-top:0.5rem"
                    @click="ajouterEtape(pi)"
                />
            </div>
        </div>

        <Button
            label="Ajouter une phase"
            icon="pi pi-plus"
            outlined
            size="small"
            style="margin-top:0.75rem"
            @click="ajouterPhase"
        />
    </div>
</template>

<style scoped>
.ft-section { width: 100%; }
.ft-section-title { font-size: 15px; font-weight: 700; color: var(--text-color); margin-bottom: 1.25rem; }
.ft-vide { color: var(--text-color-secondary); font-size: 13px; font-style: italic; margin-bottom: 1rem; }

.ft-phases { display: flex; flex-direction: column; gap: 1rem; }

.ft-phase { border: 1px solid var(--surface-border); border-radius: 8px; padding: 0.75rem; background: var(--surface-card); }

.ft-phase-header { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; }
.ft-phase-icon { color: #7F77DD; font-size: 14px; flex-shrink: 0; }
.ft-phase-temporalite-input { width: 170px; flex-shrink: 0; }
.ft-phase-sep { color: var(--text-color-secondary); font-weight: 600; flex-shrink: 0; }
.ft-phase-label-input { flex: 1; }

.ft-etapes { display: flex; flex-direction: column; gap: 0.4rem; min-height: 32px; }

.ft-etape { display: flex; align-items: flex-start; gap: 0.6rem; padding: 0.4rem 0.5rem; border-radius: 6px; background: var(--surface-50, #fafafa); border: 1px solid var(--surface-border); cursor: default; }
.ft-etape-ghost { opacity: 0.4; background: #EEEDFE; }

.drag-handle { color: var(--text-color-secondary); font-size: 14px; cursor: grab; flex-shrink: 0; margin-top: 6px; padding: 0 2px; }
.drag-handle:active { cursor: grabbing; }

.ft-etape-num { width: 24px; height: 24px; border-radius: 50%; background: #7F77DD; color: white; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 4px; }
.ft-etape-input { flex: 1; }
.ft-etape-input :deep(.p-inputtextarea) { width: 100%; }

.ft-phase-vide { color: var(--text-color-secondary); font-size: 12px; font-style: italic; padding: 0.5rem; text-align: center; border: 1px dashed var(--surface-border); border-radius: 4px; }
</style>
