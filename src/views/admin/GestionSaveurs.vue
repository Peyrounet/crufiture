<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';
import axiosStock from '@/plugins/axiosStock';

const toast = useToast();

// ── Données ───────────────────────────────────────────────────
const saveurs    = ref([]);
const loading    = ref(true);
const recherche  = ref('');

const saveursFiltrees = computed(() => {
    const q = recherche.value.trim().toLowerCase();
    if (!q) return saveurs.value;
    return saveurs.value.filter(s => s.nom.toLowerCase().includes(q));
});

// ── Avatar ────────────────────────────────────────────────────
// Génère 2 initiales et une couleur stable depuis le nom
const COULEURS = [
    '#e57373', '#f06292', '#ba68c8', '#7986cb',
    '#4fc3f7', '#4db6ac', '#81c784', '#ffb74d',
    '#a1887f', '#90a4ae',
];

function initiales(nom) {
    const mots = nom.trim().split(/\s+/);
    if (mots.length === 1) return mots[0].substring(0, 2).toUpperCase();
    return (mots[0][0] + mots[1][0]).toUpperCase();
}

function couleurAvatar(nom) {
    let hash = 0;
    for (let i = 0; i < nom.length; i++) hash = nom.charCodeAt(i) + ((hash << 5) - hash);
    return COULEURS[Math.abs(hash) % COULEURS.length];
}

// ── Dialog création / édition ─────────────────────────────────
const dialogVisible = ref(false);
const modeEdition   = ref(false);
const saving        = ref(false);
const idEnEdition   = ref(null);

const formVide = () => ({
    nom:              '',
    slug:             '',
    brix_cible:       70,
    pa_cible:         68,
    pct_fructose:     50,
    note:             '',
    stock_article_id: null,
    stock_article_libelle: '', // affiché dans l'input de recherche
});

// ── Autocomplétion article stock ──────────────────────────────
const stockRecherche    = ref('');
const stockResultats    = ref([]);
const stockChargement   = ref(false);
let   stockDebounce     = null;

function onStockInput() {
    clearTimeout(stockDebounce);
    const q = stockRecherche.value.trim();
    if (q.length < 2) { stockResultats.value = []; return; }
    stockDebounce = setTimeout(() => rechercherStock(q), 300);
}

async function rechercherStock(q) {
    stockChargement.value = true;
    try {
        const res = await axiosStock.get('/articles', { params: { q } });
        stockResultats.value = Array.isArray(res.data) ? res.data : [];
    } catch {
        stockResultats.value = [];
    } finally {
        stockChargement.value = false;
    }
}

function selectionnerArticle(article) {
    form.stock_article_id      = article.id;
    form.stock_article_libelle = article.libelle;
    stockRecherche.value       = '';
    stockResultats.value       = [];
}

function supprimerLiaisonStock() {
    form.stock_article_id      = null;
    form.stock_article_libelle = '';
}

const form = reactive(formVide());

// ── Confirmation suppression ──────────────────────────────────
const confirmVisible   = ref(false);
const saveurASupprimer = ref(null);
const suppression      = ref(false);

// ── Chargement ────────────────────────────────────────────────
onMounted(charger);

async function charger() {
    loading.value = true;
    try {
        const res = await axiosCrufiture.get('/saveurs');
        if (res.data?.status === 'success') saveurs.value = res.data.details;
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger les saveurs.', life: 4000 });
    } finally {
        loading.value = false;
    }
}

// ── Slug auto-généré depuis le nom (création uniquement) ──────
function genererSlug() {
    if (modeEdition.value) return;
    form.slug = form.nom
        .toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

// ── Ouvrir dialog ─────────────────────────────────────────────
function ouvrirCreation() {
    modeEdition.value   = false;
    idEnEdition.value   = null;
    Object.assign(form, formVide());
    dialogVisible.value = true;
}

function ouvrirEdition(saveur) {
    modeEdition.value   = true;
    idEnEdition.value   = saveur.id;
    Object.assign(form, {
        nom:                   saveur.nom,
        slug:                  saveur.slug,
        brix_cible:            saveur.brix_cible,
        pa_cible:              saveur.pa_cible,
        pct_fructose:          saveur.pct_fructose,
        note:                  saveur.note ?? '',
        stock_article_id:      saveur.stock_article_id ?? null,
        stock_article_libelle: saveur.stock_article_libelle ?? '',
    });
    stockRecherche.value  = '';
    stockResultats.value  = [];
    dialogVisible.value   = true;
}

function fermerDialog() {
    dialogVisible.value = false;
}

// ── Sauvegarder ───────────────────────────────────────────────
async function sauvegarder() {
    if (!form.nom.trim() || !form.slug.trim()) {
        toast.add({ severity: 'warn', summary: 'Champs manquants', detail: 'Nom et slug sont obligatoires.', life: 3000 });
        return;
    }

    saving.value = true;
    try {
        const payload = {
            nom:              form.nom.trim(),
            slug:             form.slug.trim(),
            brix_cible:       form.brix_cible,
            pa_cible:         form.pa_cible,
            pct_fructose:     form.pct_fructose,
            note:             form.note.trim(),
            stock_article_id: form.stock_article_id ?? null,
        };

        if (modeEdition.value) {
            await axiosCrufiture.put(`/saveurs/${idEnEdition.value}`, payload);
            toast.add({ severity: 'success', summary: 'Enregistré', detail: 'Saveur mise à jour.', life: 3000 });
        } else {
            await axiosCrufiture.post('/saveurs', payload);
            toast.add({ severity: 'success', summary: 'Créée', detail: 'Saveur créée avec succès.', life: 3000 });
        }

        dialogVisible.value = false;
        await charger();
    } catch (err) {
        const detail = err.response?.data?.message ?? 'Erreur lors de l\'enregistrement.';
        toast.add({ severity: 'error', summary: 'Erreur', detail, life: 4000 });
    } finally {
        saving.value = false;
    }
}

// ── Suppression ───────────────────────────────────────────────
function confirmerSuppression(saveur) {
    saveurASupprimer.value = saveur;
    confirmVisible.value   = true;
}

async function supprimer() {
    suppression.value = true;
    try {
        const res = await axiosCrufiture.delete(`/saveurs/${saveurASupprimer.value.id}`);
        toast.add({ severity: 'success', summary: 'OK', detail: res.data?.message ?? 'Saveur supprimée.', life: 3000 });
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
    <PageCard titre="Saveurs">
        <template #actions>
            <Button label="Nouvelle saveur" icon="pi pi-plus" @click="ouvrirCreation" />
        </template>

        <!-- Barre de recherche -->
        <div class="cruf-search-bar">
            <IconField iconPosition="left" class="w-full">
                <InputIcon class="pi pi-search" />
                <InputText
                    v-model="recherche"
                    placeholder="Rechercher une saveur…"
                    class="w-full"
                />
            </IconField>
        </div>

        <!-- État chargement -->
        <div v-if="loading" class="flex justify-content-center p-5">
            <ProgressSpinner />
        </div>

        <!-- Liste vide -->
        <p v-else-if="saveursFiltrees.length === 0" class="cruf-empty">
            {{ recherche ? 'Aucune saveur ne correspond à cette recherche.' : 'Aucune saveur enregistrée.' }}
        </p>

        <!-- Cards -->
        <div v-else class="cruf-list">
            <div
                v-for="saveur in saveursFiltrees"
                :key="saveur.id"
                class="cruf-card"
            >
                <!-- Avatar -->
                <div
                    class="cruf-avatar"
                    :style="{ background: couleurAvatar(saveur.nom) }"
                >
                    {{ initiales(saveur.nom) }}
                </div>

                <!-- Contenu -->
                <div class="cruf-card-body">
                    <div class="cruf-card-nom">{{ saveur.nom }}</div>
                    <div class="cruf-card-meta">
                        <span class="cruf-meta-item">
                            <span class="cruf-meta-label">BRIX CIBLE</span>
                            <span class="cruf-meta-val">{{ saveur.brix_cible }} °Bx</span>
                        </span>
                        <span class="cruf-meta-sep">·</span>
                        <span class="cruf-meta-item">
                            <span class="cruf-meta-label">PA CIBLE</span>
                            <span class="cruf-meta-val">{{ saveur.pa_cible }} g/100g</span>
                        </span>
                        <span class="cruf-meta-sep">·</span>
                        <span class="cruf-meta-item">
                            <span class="cruf-meta-label">FRUCTOSE</span>
                            <span class="cruf-meta-val">{{ saveur.pct_fructose }} %</span>
                        </span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="cruf-card-actions">
                    <Button
                        icon="pi pi-pencil"
                        text rounded
                        v-tooltip.top="'Modifier'"
                        @click="ouvrirEdition(saveur)"
                    />
                    <Button
                        icon="pi pi-trash"
                        text rounded
                        severity="danger"
                        v-tooltip.top="'Supprimer'"
                        @click="confirmerSuppression(saveur)"
                    />
                </div>
            </div>
        </div>
    </PageCard>

    <!-- ── Dialog création / édition ──────────────────────────── -->
    <Dialog
        v-model:visible="dialogVisible"
        :header="modeEdition ? 'Modifier la saveur' : 'Nouvelle saveur'"
        :style="{ width: '500px' }"
        modal
        :closable="!saving"
    >
        <div class="saveur-form">

            <div class="saveur-field">
                <label>Nom <span class="cruf-required">*</span></label>
                <InputText
                    v-model="form.nom"
                    class="w-full"
                    placeholder="ex : Rhubarbe Fleur de Sureau"
                    @input="genererSlug"
                />
            </div>

            <div class="saveur-field">
                <label>Slug <span class="cruf-required">*</span></label>
                <InputText
                    v-model="form.slug"
                    class="w-full"
                    placeholder="ex : rhubarbe-fleur-sureau"
                />
                <small class="cruf-hint">Identifiant technique unique — auto-généré depuis le nom</small>
            </div>

            <div class="saveur-grid3">
                <div class="saveur-field">
                    <label>Brix cible (°Bx)</label>
                    <InputNumber
                        v-model="form.brix_cible"
                        class="w-full"
                        :min="0" :max="100"
                        :minFractionDigits="1" :maxFractionDigits="2"
                        inputClass="w-full"
                    />
                </div>
                <div class="saveur-field">
                    <label>PA cible (g/100g)</label>
                    <InputNumber
                        v-model="form.pa_cible"
                        class="w-full"
                        :min="0" :max="100"
                        :minFractionDigits="1" :maxFractionDigits="2"
                        inputClass="w-full"
                    />
                </div>
                <div class="saveur-field">
                    <label>% Fructose</label>
                    <InputNumber
                        v-model="form.pct_fructose"
                        class="w-full"
                        :min="0" :max="100"
                        :minFractionDigits="1" :maxFractionDigits="2"
                        inputClass="w-full"
                    />
                </div>
            </div>

            <div class="saveur-field">
                <label>Note</label>
                <Textarea
                    v-model="form.note"
                    class="w-full"
                    :autoResize="true"
                    rows="3"
                    placeholder="Remarques, variantes, conseils de préparation…"
                />
            </div>

            <!-- Liaison article /stock — édition uniquement -->
            <div v-if="modeEdition" class="saveur-field">
                <label>Article stock lié</label>
                <small class="cruf-hint">
                    Utilisé pour déclarer l'entrée en stock à la mise en jarres du lot.
                </small>

                <!-- Article actuellement lié -->
                <div v-if="form.stock_article_id" class="stock-article-lié">
                    <span class="stock-article-badge">
                        <i class="pi pi-box"></i>
                        {{ form.stock_article_libelle || 'Article #' + form.stock_article_id }}
                    </span>
                    <Button
                        icon="pi pi-times"
                        text rounded
                        severity="danger"
                        size="small"
                        v-tooltip.top="'Supprimer la liaison'"
                        @click="supprimerLiaisonStock"
                    />
                </div>

                <!-- Champ de recherche -->
                <div class="stock-search-wrap">
                    <input
                        type="text"
                        class="p-inputtext p-component w-full"
                        v-model="stockRecherche"
                        @input="onStockInput"
                        :placeholder="form.stock_article_id ? 'Changer l\'article lié…' : 'Rechercher un article stock…'"
                        autocomplete="off"
                    />
                    <i v-if="stockChargement" class="pi pi-spin pi-spinner stock-search-spinner"></i>
                </div>

                <!-- Résultats -->
                <div v-if="stockResultats.length" class="stock-resultats">
                    <div
                        v-for="article in stockResultats"
                        :key="article.id"
                        class="stock-resultat-item"
                        @click="selectionnerArticle(article)"
                    >
                        <span class="stock-res-libelle">{{ article.libelle }}</span>
                        <span class="stock-res-meta">{{ article.unite }} · {{ article.disponible }} disponible</span>
                    </div>
                </div>
            </div>

        </div>

        <template #footer>
            <Button label="Annuler" text :disabled="saving" @click="fermerDialog" />
            <Button
                :label="modeEdition ? 'Enregistrer' : 'Créer'"
                icon="pi pi-check"
                :loading="saving"
                @click="sauvegarder"
            />
        </template>
    </Dialog>

    <!-- ── Dialog confirmation suppression ────────────────────── -->
    <Dialog
        v-model:visible="confirmVisible"
        header="Supprimer la saveur ?"
        :style="{ width: '400px' }"
        modal
    >
        <p style="margin:0">
            Supprimer <strong>{{ saveurASupprimer?.nom }}</strong> ?
        </p>
        <p class="cruf-hint" style="margin-top: 8px">
            Si des lots sont rattachés à cette saveur, elle sera désactivée plutôt que supprimée définitivement.
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
/* ── Recherche ────────────────────────────────────────────── */
.cruf-search-bar {
    margin-bottom: 1rem;
    max-width: 420px;
}

/* ── Liste de cards ───────────────────────────────────────── */
.cruf-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.cruf-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 1rem;
    border: 1px solid var(--surface-border);
    border-radius: 8px;
    background: var(--surface-card);
    transition: box-shadow 0.15s;
}

.cruf-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

/* ── Avatar ───────────────────────────────────────────────── */
.cruf-avatar {
    flex-shrink: 0;
    width: 42px;
    height: 42px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    color: #fff;
    letter-spacing: 0.5px;
}

/* ── Corps de la card ─────────────────────────────────────── */
.cruf-card-body {
    flex: 1;
    min-width: 0;
}

.cruf-card-nom {
    font-weight: 600;
    font-size: 15px;
    color: var(--text-color);
    margin-bottom: 3px;
}

.cruf-card-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.cruf-meta-item {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
}

.cruf-meta-label {
    font-size: 10px;
    font-weight: 600;
    color: #aaa;
    letter-spacing: 0.4px;
    text-transform: uppercase;
}

.cruf-meta-val {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-color-secondary);
}

.cruf-meta-sep {
    color: #ccc;
    font-size: 16px;
    align-self: center;
    margin-bottom: 2px;
}

/* ── Actions ──────────────────────────────────────────────── */
.cruf-card-actions {
    flex-shrink: 0;
    display: flex;
    gap: 2px;
}

/* ── États ────────────────────────────────────────────────── */
.cruf-empty {
    color: #aaa;
    font-size: 13px;
    font-style: italic;
    padding: 8px 0;
    margin: 0;
}

/* ── Formulaire dialog ────────────────────────────────────── */
.saveur-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding-top: 0.25rem;
}

.saveur-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.saveur-field label {
    font-size: 13px;
    font-weight: 600;
    color: #444;
}

.saveur-grid3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
}

.cruf-required {
    color: #e53935;
    margin-left: 2px;
}

.cruf-hint {
    font-size: 12px;
    color: #999;
    font-style: italic;
}

/* ── Liaison article stock ────────────────────────────────── */
.stock-article-lié {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}

.stock-article-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #a5d6a7;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 13px;
    font-weight: 600;
}

.stock-article-badge .pi { font-size: 12px; }

.stock-search-wrap {
    position: relative;
}

.stock-search-spinner {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #aaa;
    font-size: 13px;
}

.stock-resultats {
    border: 1px solid var(--surface-border);
    border-radius: 6px;
    overflow: hidden;
    margin-top: 4px;
    background: var(--surface-card);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.stock-resultat-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid var(--surface-border);
    transition: background 0.12s;
}

.stock-resultat-item:last-child { border-bottom: none; }
.stock-resultat-item:hover { background: var(--surface-hover); }

.stock-res-libelle {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-color);
}

.stock-res-meta {
    font-size: 11px;
    color: #999;
}
</style>