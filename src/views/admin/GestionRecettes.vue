<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';

const router = useRouter();
const toast  = useToast();

// ── Données ───────────────────────────────────────────────────────
const recettes  = ref([]);
const loading   = ref(true);
const recherche = ref('');

onMounted(charger);

async function charger() {
    loading.value = true;
    try {
        const res = await axiosCrufiture.get('/recettes');
        if (res.data?.status === 'success') recettes.value = res.data.details;
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger les recettes.', life: 4000 });
    } finally {
        loading.value = false;
    }
}

// ── Groupement par saveur + filtre recherche ──────────────────────
const groupes = computed(() => {
    const q = recherche.value.trim().toLowerCase();

    const filtrees = q
        ? recettes.value.filter(r =>
            r.titre.toLowerCase().includes(q) ||
            r.saveur_nom.toLowerCase().includes(q)
          )
        : recettes.value;

    // Grouper par saveur_id
    const map = new Map();
    for (const r of filtrees) {
        if (!map.has(r.saveur_id)) {
            map.set(r.saveur_id, { saveur_id: r.saveur_id, saveur_nom: r.saveur_nom, recettes: [] });
        }
        map.get(r.saveur_id).recettes.push(r);
    }
    return Array.from(map.values());
});

// ── Avatar couleur stable ─────────────────────────────────────────
const COULEURS = [
    '#e57373','#f06292','#ba68c8','#7986cb',
    '#4fc3f7','#4db6ac','#81c784','#ffb74d',
    '#a1887f','#90a4ae',
];
function couleurAvatar(nom) {
    let hash = 0;
    for (let i = 0; i < nom.length; i++) hash = nom.charCodeAt(i) + ((hash << 5) - hash);
    return COULEURS[Math.abs(hash) % COULEURS.length];
}
function initiales(nom) {
    const mots = nom.trim().split(/\s+/);
    if (mots.length === 1) return mots[0].substring(0, 2).toUpperCase();
    return (mots[0][0] + mots[1][0]).toUpperCase();
}

// ── Navigation ────────────────────────────────────────────────────
function ouvrirRecette(id) {
    router.push('/dashboard/recettes/' + id);
}

function nouvelleRecette() {
    router.push('/dashboard/recettes/nouvelle');
}

// ── Duplication (nouvelle version) ───────────────────────────────
const duplicating = ref(null);

async function dupliquer(recette) {
    duplicating.value = recette.id;
    try {
        const res = await axiosCrufiture.post('/recettes/' + recette.id + '/dupliquer');
        if (res.data?.status === 'success') {
            toast.add({
                severity: 'success',
                summary: 'Nouvelle version créée',
                detail: 'v' + res.data.details.version + ' — ' + recette.titre,
                life: 3000,
            });
            await charger();
            router.push('/dashboard/recettes/' + res.data.details.id);
        }
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de dupliquer.', life: 4000 });
    } finally {
        duplicating.value = null;
    }
}

// ── Suppression ───────────────────────────────────────────────────
const confirmVisible   = ref(false);
const recetteASupp     = ref(null);
const suppression      = ref(false);

function confirmerSuppression(recette) {
    recetteASupp.value   = recette;
    confirmVisible.value = true;
}

async function supprimer() {
    suppression.value = true;
    try {
        const res = await axiosCrufiture.delete('/recettes/' + recetteASupp.value.id);
        toast.add({ severity: 'success', summary: 'OK', detail: res.data?.message ?? 'Recette supprimée.', life: 3000 });
        confirmVisible.value = false;
        await charger();
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de supprimer.', life: 4000 });
    } finally {
        suppression.value = false;
    }
}
</script>

<template>
<div class="col-12">
    <PageCard titre="Recettes">
        <template #actions>
            <Button label="Nouvelle recette" icon="pi pi-plus" @click="nouvelleRecette" />
        </template>

        <!-- Recherche -->
        <div class="cruf-search-bar">
            <IconField iconPosition="left" class="w-full">
                <InputIcon class="pi pi-search" />
                <InputText
                    v-model="recherche"
                    placeholder="Rechercher par titre ou saveur…"
                    class="w-full"
                />
            </IconField>
        </div>

        <!-- Chargement -->
        <div v-if="loading" class="flex justify-content-center p-5">
            <ProgressSpinner />
        </div>

        <!-- Vide -->
        <p v-else-if="groupes.length === 0" class="cruf-empty">
            {{ recherche ? 'Aucune recette ne correspond à cette recherche.' : 'Aucune recette enregistrée.' }}
        </p>

        <!-- Groupes par saveur -->
        <div v-else class="rec-groupes">
            <div v-for="groupe in groupes" :key="groupe.saveur_id" class="rec-groupe">

                <!-- Header saveur -->
                <div class="rec-groupe-header">
                    <div
                        class="rec-saveur-avatar"
                        :style="{ background: couleurAvatar(groupe.saveur_nom) }"
                    >
                        {{ initiales(groupe.saveur_nom) }}
                    </div>
                    <span class="rec-saveur-nom">{{ groupe.saveur_nom }}</span>
                    <span class="rec-saveur-count">{{ groupe.recettes.length }} recette{{ groupe.recettes.length > 1 ? 's' : '' }}</span>
                </div>

                <!-- Cards recettes de ce groupe -->
                <div class="rec-list">
                    <div
                        v-for="recette in groupe.recettes"
                        :key="recette.id"
                        class="rec-card"
                        @click="ouvrirRecette(recette.id)"
                    >
                        <!-- Badge version -->
                        <div class="rec-version-badge">v{{ recette.version }}</div>

                        <!-- Corps -->
                        <div class="rec-card-body">
                            <div class="rec-card-titre">{{ recette.titre }}</div>
                            <div class="rec-card-meta">
                                <span class="rec-meta-chip">
                                    <i class="pi pi-list" style="font-size:10px" />
                                    {{ recette.nb_ingredients }} ingrédient{{ recette.nb_ingredients > 1 ? 's' : '' }}
                                </span>
                                <span class="rec-meta-chip">
                                    <i class="pi pi-align-left" style="font-size:10px" />
                                    {{ recette.nb_etapes }} étape{{ recette.nb_etapes > 1 ? 's' : '' }}
                                </span>
                                <span v-if="recette.actif === 0" class="rec-meta-chip rec-chip-inactive">
                                    Désactivée
                                </span>
                            </div>
                        </div>

                        <!-- Actions — stopper propagation du click card -->
                        <div class="rec-card-actions" @click.stop>
                            <Button
                                icon="pi pi-copy"
                                text rounded
                                v-tooltip.top="'Nouvelle version'"
                                :loading="duplicating === recette.id"
                                @click="dupliquer(recette)"
                            />
                            <Button
                                icon="pi pi-pencil"
                                text rounded
                                v-tooltip.top="'Éditer'"
                                @click="ouvrirRecette(recette.id)"
                            />
                            <Button
                                icon="pi pi-trash"
                                text rounded
                                severity="danger"
                                v-tooltip.top="'Supprimer'"
                                @click="confirmerSuppression(recette)"
                            />
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </PageCard>

    <!-- Confirmation suppression -->
    <Dialog
        v-model:visible="confirmVisible"
        header="Supprimer la recette ?"
        :style="{ width: '420px' }"
        modal
    >
        <p style="margin:0">
            Supprimer <strong>{{ recetteASupp?.titre }}</strong>
            <span style="color:#999"> (v{{ recetteASupp?.version }})</span> ?
        </p>
        <p class="cruf-hint" style="margin-top:8px">
            Si des lots y sont rattachés, elle sera désactivée plutôt que supprimée définitivement.
        </p>
        <template #footer>
            <Button label="Annuler" text :disabled="suppression" @click="confirmVisible = false" />
            <Button
                label="Confirmer"
                severity="danger"
                icon="pi pi-trash"
                :loading="suppression"
                @click="supprimer"
            />
        </template>
    </Dialog>

</div>
</template>

<style scoped>
/* ── Recherche ───────────────────────────────────────────────── */
.cruf-search-bar {
    margin-bottom: 1.25rem;
    max-width: 420px;
}

/* ── Groupes ─────────────────────────────────────────────────── */
.rec-groupes {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.rec-groupe-header {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    margin-bottom: 0.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--surface-border);
}

.rec-saveur-avatar {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: #fff;
}

.rec-saveur-nom {
    font-weight: 700;
    font-size: 14px;
    color: var(--text-color);
}

.rec-saveur-count {
    font-size: 12px;
    color: #aaa;
    margin-left: auto;
}

/* ── Liste de cards ──────────────────────────────────────────── */
.rec-list {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    padding-left: 0.25rem;
}

.rec-card {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.625rem 0.875rem;
    border: 1px solid var(--surface-border);
    border-radius: 8px;
    background: var(--surface-card);
    cursor: pointer;
    transition: box-shadow 0.15s, border-color 0.15s;
}

.rec-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-color: var(--primary-300, #fbbf24);
}

/* ── Badge version ───────────────────────────────────────────── */
.rec-version-badge {
    flex-shrink: 0;
    background: var(--surface-100, #f5f5f5);
    border: 1px solid var(--surface-border);
    border-radius: 5px;
    padding: 2px 7px;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-color-secondary);
    letter-spacing: 0.3px;
    white-space: nowrap;
}

/* ── Corps card ──────────────────────────────────────────────── */
.rec-card-body {
    flex: 1;
    min-width: 0;
}

.rec-card-titre {
    font-weight: 600;
    font-size: 14px;
    color: var(--text-color);
    margin-bottom: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.rec-card-meta {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.rec-meta-chip {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 11px;
    color: #999;
}

.rec-chip-inactive {
    background: #fff3e0;
    color: #e65100;
    padding: 1px 6px;
    border-radius: 4px;
    font-weight: 600;
}

/* ── Actions ─────────────────────────────────────────────────── */
.rec-card-actions {
    flex-shrink: 0;
    display: flex;
    gap: 2px;
}

/* ── États ───────────────────────────────────────────────────── */
.cruf-empty {
    color: #aaa;
    font-size: 13px;
    font-style: italic;
    padding: 8px 0;
    margin: 0;
}

.cruf-hint {
    font-size: 12px;
    color: #999;
    font-style: italic;
}
</style>
