<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';
import { useGammeStore } from '@/stores/gammeStore';

const gammeStore = useGammeStore();

const router = useRouter();
const toast  = useToast();

// ── Couleurs par slug (hardcodées — conventions CLAUDE.md) ────
const COULEURS_GAMME = {
    crufiture:         { couleur: '#1D9E75', fond: '#E1F5EE' },
    jus:               { couleur: '#BA7517', fond: '#FAEEDA' },
    sechage:           { couleur: '#639922', fond: '#EAF3DE' },
    maceration_alcool: { couleur: '#7F77DD', fond: '#EEEDFE' },
    maceration_huile:  { couleur: '#D4537E', fond: '#FBEAF0' },
    distillation:      { couleur: '#378ADD', fond: '#E6F1FB' },
};

function stylesGamme(slug) {
    return COULEURS_GAMME[slug] ?? { couleur: '#888', fond: '#f0f0f0' };
}

function initialesGamme(libelle) {
    const mots = libelle.trim().split(/\s+/);
    if (mots.length === 1) return mots[0].substring(0, 2).toUpperCase();
    return (mots[0][0] + mots[1][0]).toUpperCase();
}

// ── Données ───────────────────────────────────────────────────
const gammes  = ref([]);
const loading = ref(true);

onMounted(charger);

async function charger() {
    loading.value = true;
    try {
        const res = await axiosCrufiture.get('/gammes');
        if (res.data?.status === 'success') gammes.value = res.data.details;
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger les gammes.', life: 4000 });
    } finally {
        loading.value = false;
    }
}

// ── Dialog création / édition ─────────────────────────────────
const dialogVisible = ref(false);
const modeEdition   = ref(false);
const saving        = ref(false);
const idEnEdition   = ref(null);

const formVide = () => ({ libelle: '', slug: '', actif: 1 });
const form = reactive(formVide());

function genererSlug() {
    if (modeEdition.value) return;
    form.slug = form.libelle
        .toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[éèêë]/g, 'e').replace(/[àâä]/g, 'a')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

function ouvrirCreation() {
    modeEdition.value   = false;
    idEnEdition.value   = null;
    Object.assign(form, formVide());
    dialogVisible.value = true;
}

function ouvrirEdition(gamme) {
    modeEdition.value   = true;
    idEnEdition.value   = gamme.id;
    Object.assign(form, { libelle: gamme.libelle, slug: gamme.slug, actif: gamme.actif });
    dialogVisible.value = true;
}

async function sauvegarder() {
    if (!form.libelle.trim() || (!modeEdition.value && !form.slug.trim())) {
        toast.add({ severity: 'warn', summary: 'Champs manquants', detail: 'Libellé et slug sont obligatoires.', life: 3000 });
        return;
    }
    saving.value = true;
    try {
        const payload = { libelle: form.libelle.trim(), actif: form.actif };
        if (!modeEdition.value) payload.slug = form.slug.trim();

        if (modeEdition.value) {
            await axiosCrufiture.put(`/gammes/${idEnEdition.value}`, payload);
            toast.add({ severity: 'success', summary: 'Enregistré', detail: 'Gamme mise à jour.', life: 3000 });
        } else {
            await axiosCrufiture.post('/gammes', payload);
            toast.add({ severity: 'success', summary: 'Créée', detail: 'Gamme créée.', life: 3000 });
        }
        dialogVisible.value = false;
        await charger();
        await gammeStore.charger();
    } catch (err) {
        const detail = err.response?.data?.message ?? 'Erreur lors de l\'enregistrement.';
        toast.add({ severity: 'error', summary: 'Erreur', detail, life: 4000 });
    } finally {
        saving.value = false;
    }
}

// ── Suppression ───────────────────────────────────────────────
const confirmVisible  = ref(false);
const gammeASupprimer = ref(null);
const suppression     = ref(false);

function confirmerSuppression(gamme) {
    gammeASupprimer.value = gamme;
    confirmVisible.value  = true;
}

async function supprimer() {
    suppression.value = true;
    try {
        const res = await axiosCrufiture.delete(`/gammes/${gammeASupprimer.value.id}`);
        toast.add({ severity: 'success', summary: 'OK', detail: res.data?.message ?? 'Gamme supprimée.', life: 3000 });
        confirmVisible.value = false;
        await charger();
        await gammeStore.charger();
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de supprimer.', life: 4000 });
    } finally {
        suppression.value = false;
    }
}

// ── Navigation ────────────────────────────────────────────────
const gererproduits = (gamme) => router.push('/dashboard/gammes/' + gamme.id);
const allerDashboard = (gamme) => router.push('/dashboard/' + gamme.slug);
</script>

<template>
<div class="col-12">
    <PageCard titre="Gammes & Produits">
        <template #actions>
            <Button label="Nouvelle gamme" icon="pi pi-plus" @click="ouvrirCreation" />
        </template>

        <!-- Chargement -->
        <div v-if="loading" class="flex justify-content-center p-5">
            <ProgressSpinner />
        </div>

        <!-- Vide -->
        <p v-else-if="gammes.length === 0" class="gc-empty">
            Aucune gamme enregistrée.
        </p>

        <!-- Grille de cards -->
        <div v-else class="gc-grille">
            <div v-for="gamme in gammes" :key="gamme.id" class="gc-card">

                <!-- En-tête card -->
                <div class="gc-card-top">
                    <div
                        class="gc-icone"
                        :style="{ background: stylesGamme(gamme.slug).fond, color: stylesGamme(gamme.slug).couleur }"
                    >
                        {{ initialesGamme(gamme.libelle) }}
                    </div>
                    <div class="gc-card-title">
                        <span class="gc-libelle">{{ gamme.libelle }}</span>
                        <span class="gc-slug">{{ gamme.slug }}</span>
                    </div>
                    <Tag
                        :value="gamme.actif ? 'active' : 'inactive'"
                        :severity="gamme.actif ? 'success' : 'secondary'"
                        class="gc-badge-statut"
                    />
                </div>

                <!-- Stats -->
                <div class="gc-stats">
                    <div class="gc-stat">
                        <span class="gc-stat-val">{{ gamme.nb_produits }}</span>
                        <span class="gc-stat-label">produit{{ gamme.nb_produits !== 1 ? 's' : '' }}</span>
                    </div>
                    <div class="gc-stat-sep"></div>
                    <div class="gc-stat">
                        <span class="gc-stat-val">{{ gamme.nb_lots_actifs }}</span>
                        <span class="gc-stat-label">lot{{ gamme.nb_lots_actifs !== 1 ? 's' : '' }} en cours</span>
                    </div>
                    <div class="gc-stat-sep"></div>
                    <div class="gc-stat">
                        <span class="gc-stat-val">{{ gamme.nb_lots_total }}</span>
                        <span class="gc-stat-label">lot{{ gamme.nb_lots_total !== 1 ? 's' : '' }} total</span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="gc-card-actions">
                    <Button
                        label="Modifier"
                        icon="pi pi-pencil"
                        text size="small"
                        @click="ouvrirEdition(gamme)"
                    />
                    <Button
                        :label="`Produits (${gamme.nb_produits})`"
                        icon="pi pi-box"
                        text size="small"
                        @click="gererproduits(gamme)"
                    />
                    <Button
                        v-if="gamme.nb_lots_total === 0 && gamme.nb_produits === 0"
                        icon="pi pi-trash"
                        text rounded size="small"
                        severity="danger"
                        v-tooltip.top="'Supprimer'"
                        @click="confirmerSuppression(gamme)"
                        class="gc-btn-delete"
                    />
                    <Button
                        v-if="gamme.actif"
                        label="Dashboard"
                        icon="pi pi-arrow-right"
                        size="small"
                        @click="allerDashboard(gamme)"
                        class="gc-btn-dashboard"
                    />
                </div>
            </div>
        </div>
    </PageCard>

    <!-- ── Dialog création / édition ──────────────────────────── -->
    <Dialog
        v-model:visible="dialogVisible"
        :header="modeEdition ? 'Modifier la gamme' : 'Nouvelle gamme'"
        :style="{ width: '420px' }"
        modal
        :closable="!saving"
    >
        <div class="gc-form">
            <div class="gc-field">
                <label>Libellé <span class="gc-required">*</span></label>
                <InputText
                    v-model="form.libelle"
                    class="w-full"
                    placeholder="ex : Jus de fruit"
                    @input="genererSlug"
                />
            </div>
            <div class="gc-field">
                <label>Slug <span class="gc-required" v-if="!modeEdition">*</span></label>
                <InputText
                    v-model="form.slug"
                    class="w-full"
                    :readonly="modeEdition"
                    :class="{ 'p-disabled': modeEdition }"
                    placeholder="ex : jus"
                />
                <small class="gc-hint">
                    {{ modeEdition ? 'Le slug ne peut pas être modifié après création.' : 'Identifiant technique unique — lettres, chiffres, underscore.' }}
                </small>
            </div>
            <div v-if="modeEdition" class="gc-field gc-field-row">
                <label>Active</label>
                <InputSwitch :modelValue="!!form.actif" @update:modelValue="form.actif = $event ? 1 : 0" />
            </div>
        </div>
        <template #footer>
            <Button label="Annuler" text :disabled="saving" @click="dialogVisible = false" />
            <Button
                :label="modeEdition ? 'Enregistrer' : 'Créer'"
                icon="pi pi-check"
                :loading="saving"
                @click="sauvegarder"
            />
        </template>
    </Dialog>

    <!-- ── Confirm suppression ─────────────────────────────────── -->
    <Dialog
        v-model:visible="confirmVisible"
        header="Supprimer la gamme ?"
        :style="{ width: '380px' }"
        modal
    >
        <p style="margin:0">Supprimer <strong>{{ gammeASupprimer?.libelle }}</strong> ?</p>
        <p class="gc-hint" style="margin-top:8px">
            Cette action est irréversible — la gamme sera supprimée définitivement.
        </p>
        <template #footer>
            <Button label="Annuler" text :disabled="suppression" @click="confirmVisible = false" />
            <Button label="Supprimer" severity="danger" icon="pi pi-trash" :loading="suppression" @click="supprimer" />
        </template>
    </Dialog>
</div>
</template>

<style scoped>
/* ── Grille de cards ──────────────────────────────────────── */
.gc-grille {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1rem;
}

.gc-card {
    border: 1px solid var(--surface-border);
    border-radius: 10px;
    background: var(--surface-card);
    padding: 1rem 1.125rem;
    display: flex;
    flex-direction: column;
    gap: 0.875rem;
    transition: box-shadow 0.15s;
}

.gc-card:hover {
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
}

/* ── En-tête card ─────────────────────────────────────────── */
.gc-card-top {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.gc-icone {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.5px;
}

.gc-card-title {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.gc-libelle {
    font-weight: 600;
    font-size: 15px;
    color: var(--text-color);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.gc-slug {
    font-size: 11px;
    color: var(--text-color-secondary);
    font-family: monospace;
}

.gc-badge-statut { flex-shrink: 0; }

/* ── Stats ────────────────────────────────────────────────── */
.gc-stats {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.625rem 0;
    border-top: 1px solid var(--surface-border);
    border-bottom: 1px solid var(--surface-border);
}

.gc-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1px;
    flex: 1;
}

.gc-stat-val {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-color);
    line-height: 1;
}

.gc-stat-label {
    font-size: 10px;
    color: var(--text-color-secondary);
    text-align: center;
}

.gc-stat-sep {
    width: 1px;
    height: 28px;
    background: var(--surface-border);
    flex-shrink: 0;
}

/* ── Actions ──────────────────────────────────────────────── */
.gc-card-actions {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}

.gc-btn-delete { margin-left: auto; }
.gc-btn-dashboard { margin-left: auto; }

/* ── Formulaire dialog ────────────────────────────────────── */
.gc-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding-top: 0.25rem;
}

.gc-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.gc-field label {
    font-size: 13px;
    font-weight: 600;
    color: #444;
}

.gc-field-row {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
}

.gc-required { color: #e53935; margin-left: 2px; }
.gc-hint { font-size: 12px; color: #999; font-style: italic; }
.gc-empty { color: #aaa; font-size: 13px; font-style: italic; padding: 8px 0; margin: 0; }
</style>
