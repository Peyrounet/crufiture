<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';
import axiosStock from '@/plugins/axiosStock';

const route  = useRoute();
const router = useRouter();
const toast  = useToast();

const gammeId = computed(() => Number(route.params.id));

// ── Couleurs par slug ──────────────────────────────────────────
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

// ── Chargement gamme + produits ────────────────────────────────
const gamme    = ref(null);
const produits = ref([]);
const loading  = ref(true);

onMounted(charger);

async function charger() {
    loading.value = true;
    try {
        const [resGammes, resProduits] = await Promise.all([
            axiosCrufiture.get('/gammes'),
            axiosCrufiture.get(`/gammes/${gammeId.value}/produits`),
        ]);
        if (resGammes.data?.status === 'success') {
            gamme.value = (resGammes.data.details || []).find(g => g.id === gammeId.value) ?? null;
            if (gamme.value) {
                formGamme.libelle = gamme.value.libelle;
                formGamme.actif   = gamme.value.actif;
            }
        }
        if (resProduits.data?.status === 'success') {
            produits.value = resProduits.data.details || [];
        }
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger la fiche.', life: 4000 });
    } finally {
        loading.value = false;
    }
}

// ── Édition de la gamme ────────────────────────────────────────
const formGamme  = reactive({ libelle: '', actif: 1 });
const savingGamme = ref(false);

async function sauvegarderGamme() {
    if (!formGamme.libelle.trim()) {
        toast.add({ severity: 'warn', summary: 'Champ manquant', detail: 'Le libellé est obligatoire.', life: 3000 });
        return;
    }
    savingGamme.value = true;
    try {
        await axiosCrufiture.put(`/gammes/${gammeId.value}`, {
            libelle: formGamme.libelle.trim(),
            actif:   formGamme.actif,
        });
        toast.add({ severity: 'success', summary: 'Enregistré', detail: 'Gamme mise à jour.', life: 3000 });
        await charger();
    } catch (err) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: err.response?.data?.message ?? 'Erreur lors de l\'enregistrement.', life: 4000 });
    } finally {
        savingGamme.value = false;
    }
}

// ── Autocomplete articles stock ────────────────────────────────
const articlesSuggestions = ref([]);

async function rechercherArticles(event) {
    const q = event.query ?? '';
    if (q.length < 2) { articlesSuggestions.value = []; return; }
    try {
        const res = await axiosStock.get('/articles', { params: { q } });
        articlesSuggestions.value = (res.data?.details ?? res.data ?? []).map(a => ({
            id:      Number(a.id),
            label:   a.libelle + (a.unite ? ' (' + a.unite + ')' : ''),
            libelle: a.libelle,
            unite:   a.unite,
        }));
    } catch {
        articlesSuggestions.value = [];
    }
}

// ── Dialog produit création / édition ─────────────────────────
const dialogProduitVisible = ref(false);
const modeEditionProduit   = ref(false);
const savingProduit        = ref(false);
const idProduitEdite       = ref(null);

const formProduitVide = () => ({ nom: '', slug: '', articleSelectionne: null, note: '', actif: 1 });
const formProduit = reactive(formProduitVide());

function genererSlugProduit() {
    if (modeEditionProduit.value) return;
    const base = (gamme.value?.slug ?? '') + '_' + formProduit.nom;
    formProduit.slug = base
        .toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[éèêë]/g, 'e').replace(/[àâä]/g, 'a').replace(/[ùûü]/g, 'u').replace(/[îï]/g, 'i').replace(/[ôö]/g, 'o')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

function ouvrirCreationProduit() {
    modeEditionProduit.value   = false;
    idProduitEdite.value       = null;
    Object.assign(formProduit, formProduitVide());
    dialogProduitVisible.value = true;
}

function ouvrirEditionProduit(produit) {
    modeEditionProduit.value   = true;
    idProduitEdite.value       = produit.id;
    formProduit.nom             = produit.nom;
    formProduit.slug            = produit.slug;
    formProduit.note            = produit.note ?? '';
    formProduit.actif           = produit.actif;
    formProduit.articleSelectionne = produit.stock_article_id
        ? { id: produit.stock_article_id, label: produit.stock_article_libelle, libelle: produit.stock_article_libelle }
        : null;
    dialogProduitVisible.value = true;
}

async function sauvegarderProduit() {
    if (!formProduit.nom.trim() || (!modeEditionProduit.value && !formProduit.slug.trim())) {
        toast.add({ severity: 'warn', summary: 'Champs manquants', detail: 'Nom et slug sont obligatoires.', life: 3000 });
        return;
    }
    savingProduit.value = true;
    try {
        const articleId = formProduit.articleSelectionne?.id ?? null;
        const payload   = {
            nom:              formProduit.nom.trim(),
            note:             formProduit.note.trim() || null,
            stock_article_id: articleId,
            actif:            formProduit.actif,
        };
        if (!modeEditionProduit.value) payload.slug = formProduit.slug.trim();

        if (modeEditionProduit.value) {
            await axiosCrufiture.put(`/gammes/${gammeId.value}/produits/${idProduitEdite.value}`, payload);
            toast.add({ severity: 'success', summary: 'Enregistré', detail: 'Produit mis à jour.', life: 3000 });
        } else {
            await axiosCrufiture.post(`/gammes/${gammeId.value}/produits`, payload);
            toast.add({ severity: 'success', summary: 'Créé', detail: 'Produit créé.', life: 3000 });
        }
        dialogProduitVisible.value = false;
        await charger();
    } catch (err) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: err.response?.data?.message ?? 'Erreur lors de l\'enregistrement.', life: 4000 });
    } finally {
        savingProduit.value = false;
    }
}

// ── Suppression produit ────────────────────────────────────────
const confirmProduitVisible  = ref(false);
const produitASupprimer      = ref(null);
const suppressionProduit     = ref(false);

function confirmerSuppressionProduit(produit) {
    produitASupprimer.value     = produit;
    confirmProduitVisible.value = true;
}

async function supprimerProduit() {
    suppressionProduit.value = true;
    try {
        const res = await axiosCrufiture.delete(`/gammes/${gammeId.value}/produits/${produitASupprimer.value.id}`);
        toast.add({ severity: 'success', summary: 'OK', detail: res.data?.message ?? 'Produit supprimé.', life: 3000 });
        confirmProduitVisible.value = false;
        await charger();
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de supprimer.', life: 4000 });
    } finally {
        suppressionProduit.value = false;
    }
}

// ── Navigation ─────────────────────────────────────────────────
const retour = () => router.push('/dashboard/gammes');
</script>

<template>
<div class="col-12">

    <!-- ── En-tête de page ───────────────────────────────────── -->
    <div v-if="loading" class="flex justify-content-center p-5">
        <ProgressSpinner />
    </div>

    <template v-else-if="!gamme">
        <div class="fg-not-found">
            <i class="pi pi-exclamation-triangle" style="font-size:2rem;color:#aaa"></i>
            <p>Gamme introuvable.</p>
            <Button label="Retour au catalogue" icon="pi pi-arrow-left" text @click="retour" />
        </div>
    </template>

    <template v-else>

        <!-- Fil d'Ariane / retour -->
        <div class="fg-breadcrumb">
            <Button icon="pi pi-arrow-left" text size="small" @click="retour" />
            <span class="fg-bc-sep">Gammes & Produits</span>
            <span class="fg-bc-sep">›</span>
            <span class="fg-bc-current">{{ gamme.libelle }}</span>
        </div>

        <!-- Bandeau gamme -->
        <div
            class="fg-banner"
            :style="{ borderLeftColor: stylesGamme(gamme.slug).couleur }"
        >
            <div
                class="fg-banner-icone"
                :style="{ background: stylesGamme(gamme.slug).fond, color: stylesGamme(gamme.slug).couleur }"
            >
                {{ gamme.libelle.substring(0, 2).toUpperCase() }}
            </div>
            <div class="fg-banner-info">
                <span class="fg-banner-nom">{{ gamme.libelle }}</span>
                <span class="fg-banner-slug">{{ gamme.slug }}</span>
            </div>
            <Tag
                :value="gamme.actif ? 'active' : 'inactive'"
                :severity="gamme.actif ? 'success' : 'secondary'"
            />
        </div>

        <!-- ── Section : Informations gamme ──────────────────── -->
        <PageCard titre="Informations">
            <div class="fg-form-inline">
                <div class="fg-field">
                    <label>Libellé <span class="fg-required">*</span></label>
                    <InputText v-model="formGamme.libelle" class="w-full" />
                </div>
                <div class="fg-field fg-field-slug">
                    <label>Slug</label>
                    <InputText :value="gamme.slug" readonly class="w-full p-disabled" />
                    <small class="fg-hint">Non modifiable après création.</small>
                </div>
                <div class="fg-field fg-field-actif">
                    <label>Active</label>
                    <InputSwitch :modelValue="!!formGamme.actif" @update:modelValue="formGamme.actif = $event ? 1 : 0" />
                </div>
                <div class="fg-field fg-field-save">
                    <Button
                        label="Enregistrer"
                        icon="pi pi-check"
                        :loading="savingGamme"
                        @click="sauvegarderGamme"
                    />
                </div>
            </div>
        </PageCard>

        <!-- ── Section : Produits ─────────────────────────────── -->
        <PageCard titre="Produits">
            <template #actions>
                <Button label="Nouveau produit" icon="pi pi-plus" @click="ouvrirCreationProduit" />
            </template>

            <!-- Vide -->
            <p v-if="produits.length === 0" class="fg-empty">
                Aucun produit pour cette gamme.
            </p>

            <!-- Tableau -->
            <DataTable v-else :value="produits" class="p-datatable-sm" responsiveLayout="scroll">
                <Column field="nom" header="Nom">
                    <template #body="{ data }">
                        <span :class="{ 'fg-inactif': !data.actif }">{{ data.nom }}</span>
                        <Tag v-if="!data.actif" value="inactif" severity="secondary" class="ml-2" style="font-size:10px" />
                    </template>
                </Column>
                <Column field="slug" header="Slug">
                    <template #body="{ data }">
                        <span class="fg-slug">{{ data.slug }}</span>
                    </template>
                </Column>
                <Column field="stock_article_libelle" header="Article stock">
                    <template #body="{ data }">
                        <span v-if="data.stock_article_libelle">{{ data.stock_article_libelle }}</span>
                        <span v-else class="fg-vide">—</span>
                    </template>
                </Column>
                <Column field="nb_lots" header="Lots" style="width:80px;text-align:center">
                    <template #body="{ data }">
                        <span>{{ data.nb_lots }}</span>
                    </template>
                </Column>
                <Column header="" style="width:110px">
                    <template #body="{ data }">
                        <div class="fg-actions-cell">
                            <Button
                                icon="pi pi-pencil"
                                text rounded size="small"
                                v-tooltip.top="'Modifier'"
                                @click="ouvrirEditionProduit(data)"
                            />
                            <Button
                                icon="pi pi-trash"
                                text rounded size="small"
                                severity="danger"
                                v-tooltip.top="data.nb_lots > 0 ? 'Désactiver' : 'Supprimer'"
                                @click="confirmerSuppressionProduit(data)"
                            />
                        </div>
                    </template>
                </Column>
            </DataTable>
        </PageCard>

    </template>

    <!-- ── Dialog produit ────────────────────────────────────── -->
    <Dialog
        v-model:visible="dialogProduitVisible"
        :header="modeEditionProduit ? 'Modifier le produit' : 'Nouveau produit'"
        :style="{ width: '480px' }"
        modal
        :closable="!savingProduit"
    >
        <div class="fg-form">
            <div class="fg-field">
                <label>Nom <span class="fg-required">*</span></label>
                <InputText
                    v-model="formProduit.nom"
                    class="w-full"
                    placeholder="ex : Framboise"
                    @input="genererSlugProduit"
                />
            </div>
            <div class="fg-field">
                <label>Slug <span v-if="!modeEditionProduit" class="fg-required">*</span></label>
                <InputText
                    v-model="formProduit.slug"
                    class="w-full"
                    :readonly="modeEditionProduit"
                    :class="{ 'p-disabled': modeEditionProduit }"
                    placeholder="ex : crufiture_framboise"
                />
                <small class="fg-hint">
                    {{ modeEditionProduit ? 'Non modifiable après création.' : 'Auto-généré — peut être ajusté avant création.' }}
                </small>
            </div>
            <div class="fg-field">
                <label>Article stock lié</label>
                <AutoComplete
                    v-model="formProduit.articleSelectionne"
                    :suggestions="articlesSuggestions"
                    optionLabel="label"
                    :delay="300"
                    class="w-full"
                    inputClass="w-full"
                    placeholder="Rechercher un article (3 caractères min.)"
                    @complete="rechercherArticles"
                    forceSelection
                />
                <small class="fg-hint">Facultatif — article utilisé pour les mouvements de stock.</small>
            </div>
            <div class="fg-field">
                <label>Note</label>
                <Textarea v-model="formProduit.note" class="w-full" rows="2" autoResize />
            </div>
            <div v-if="modeEditionProduit" class="fg-field fg-field-row">
                <label>Actif</label>
                <InputSwitch :modelValue="!!formProduit.actif" @update:modelValue="formProduit.actif = $event ? 1 : 0" />
            </div>
        </div>
        <template #footer>
            <Button label="Annuler" text :disabled="savingProduit" @click="dialogProduitVisible = false" />
            <Button
                :label="modeEditionProduit ? 'Enregistrer' : 'Créer'"
                icon="pi pi-check"
                :loading="savingProduit"
                @click="sauvegarderProduit"
            />
        </template>
    </Dialog>

    <!-- ── Confirm suppression produit ───────────────────────── -->
    <Dialog
        v-model:visible="confirmProduitVisible"
        :header="produitASupprimer?.nb_lots > 0 ? 'Désactiver le produit ?' : 'Supprimer le produit ?'"
        :style="{ width: '400px' }"
        modal
    >
        <p style="margin:0">
            <template v-if="produitASupprimer?.nb_lots > 0">
                Le produit <strong>{{ produitASupprimer?.nom }}</strong> est référencé dans
                {{ produitASupprimer?.nb_lots }} lot(s). Il sera <strong>désactivé</strong> (pas supprimé).
            </template>
            <template v-else>
                Supprimer définitivement <strong>{{ produitASupprimer?.nom }}</strong> ?
            </template>
        </p>
        <template #footer>
            <Button label="Annuler" text :disabled="suppressionProduit" @click="confirmProduitVisible = false" />
            <Button
                :label="produitASupprimer?.nb_lots > 0 ? 'Désactiver' : 'Supprimer'"
                :severity="produitASupprimer?.nb_lots > 0 ? 'warning' : 'danger'"
                icon="pi pi-trash"
                :loading="suppressionProduit"
                @click="supprimerProduit"
            />
        </template>
    </Dialog>

</div>
</template>

<style scoped>
/* ── Fil d'Ariane ─────────────────────────────────────────── */
.fg-breadcrumb {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 1rem;
    font-size: 13px;
    color: var(--text-color-secondary);
}

.fg-bc-sep { color: #ccc; }
.fg-bc-current { color: var(--text-color); font-weight: 600; }

/* ── Bandeau gamme ────────────────────────────────────────── */
.fg-banner {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.875rem 1.125rem;
    background: var(--surface-card);
    border: 1px solid var(--surface-border);
    border-left: 4px solid var(--surface-border);
    border-radius: 10px;
    margin-bottom: 1rem;
}

.fg-banner-icone {
    flex-shrink: 0;
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
}

.fg-banner-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.fg-banner-nom {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-color);
}

.fg-banner-slug {
    font-size: 11px;
    font-family: monospace;
    color: var(--text-color-secondary);
}

/* ── Formulaire gamme (inline) ────────────────────────────── */
.fg-form-inline {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 1rem;
}

.fg-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 160px;
}

.fg-field label {
    font-size: 13px;
    font-weight: 600;
    color: #444;
}

.fg-field-slug { max-width: 220px; }

.fg-field-actif {
    flex: 0 0 auto;
    min-width: auto;
    flex-direction: row;
    align-items: center;
    gap: 0.5rem;
}

.fg-field-save {
    flex: 0 0 auto;
    min-width: auto;
}

/* ── Formulaire dialog produit ────────────────────────────── */
.fg-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding-top: 0.25rem;
}

.fg-field-row {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
}

/* ── Tableau produits ─────────────────────────────────────── */
.fg-slug {
    font-family: monospace;
    font-size: 11px;
    color: var(--text-color-secondary);
}

.fg-inactif { color: #bbb; text-decoration: line-through; }
.fg-vide    { color: #ccc; }

.fg-actions-cell {
    display: flex;
    gap: 2px;
    justify-content: flex-end;
}

/* ── Utilitaires ──────────────────────────────────────────── */
.fg-required { color: #e53935; margin-left: 2px; }
.fg-hint     { font-size: 12px; color: #999; font-style: italic; }
.fg-empty    { color: #aaa; font-size: 13px; font-style: italic; margin: 0; }

.fg-not-found {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
    padding: 3rem;
    color: var(--text-color-secondary);
}
</style>
