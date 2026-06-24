<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';
import axiosStock from '@/plugins/axiosStock';
import { useGammeStore } from '@/stores/gammeStore';

const route      = useRoute();
const router     = useRouter();
const toast      = useToast();
const gammeStore = useGammeStore();

const COULEUR = '#7F77DD';
const FOND    = '#EEEDFE';

const lotId = computed(() => Number(route.params.id));

const lot     = ref(null);
const loading = ref(true);

// ── Contrôle à ajouter ────────────────────────────────────────
const controleForm     = ref({ type: 'abv', valeur: null, notes: '' });
const ajoutControle    = ref(false);
const savingControle   = ref(false);
const TYPE_CONTROLES   = [
    { value: 'abv',    label: 'ABV (%vol)' },
    { value: 'brix',   label: 'Brix (°Bx)' },
    { value: 'aspect', label: 'Aspect' },
    { value: 'ph',     label: 'pH' },
    { value: 'autre',  label: 'Autre' },
];

// ── Abandon ───────────────────────────────────────────────────
const abandonVisible = ref(false);
const abandonNote    = ref('');
const savingAbandon  = ref(false);

// ── Transitions ───────────────────────────────────────────────
const savingTransition  = ref(false);
const dateMaceration    = ref(new Date());
const dateFiltration    = ref(new Date());
const dateAssemblage    = ref(new Date());
const dateMaturation    = ref(new Date());

function toIso(d) {
    if (!d) return null;
    const dt = new Date(d);
    return dt.getFullYear() + '-' +
        String(dt.getMonth() + 1).padStart(2, '0') + '-' +
        String(dt.getDate()).padStart(2, '0') + ' ' +
        String(dt.getHours()).padStart(2, '0') + ':' +
        String(dt.getMinutes()).padStart(2, '0') + ':00';
}

// ── Édition ingrédients ───────────────────────────────────────
const editingIngr = ref(false);
const ingrEdit    = ref([]);
const savingIngr  = ref(false);
let _iKey = 0;
function newIngrEdit(prefill = {}) {
    return {
        _key:         ++_iKey,
        article_id:   prefill.article_id  ?? null,
        article_lib:  prefill.article_lib ?? '',
        quantite:     prefill.quantite    ?? null,
        unite:        prefill.unite       ?? 'kg',
        note:         prefill.note        ?? '',
        _suggestions: [],
        _searching:   false,
    };
}
function initEditionIngredients() {
    ingrEdit.value = (lot.value.ingredients ?? []).map(i => newIngrEdit({
        article_id:  i.article_id,
        article_lib: i.article_libelle ?? '',
        quantite:    i.quantite,
        unite:       i.unite,
        note:        i.note ?? '',
    }));
    if (ingrEdit.value.length === 0) ingrEdit.value.push(newIngrEdit());
    editingIngr.value = true;
}

let _debounces = {};
async function rechercherArticle(ing, q) {
    if (!q || q.length < 2) { ing._suggestions = []; return; }
    clearTimeout(_debounces[ing._key]);
    _debounces[ing._key] = setTimeout(async () => {
        ing._searching = true;
        try {
            const res = await axiosStock.get('/articles', { params: { q, limit: 15 } });
            const items = Array.isArray(res.data?.details) ? res.data.details : [];
            ing._suggestions = items.map(a => ({ id: Number(a.id), libelle: a.libelle, unite: a.unite ?? '' }));
        } catch { ing._suggestions = []; }
        finally { ing._searching = false; }
    }, 300);
}
function selectionnerArticle(ing, art) {
    ing.article_id  = Number(art.id);
    ing.article_lib = art.libelle;
    ing.unite       = ing.unite || art.unite || 'kg';
    ing._suggestions = [];
}

async function sauvegarderIngredients() {
    savingIngr.value = true;
    try {
        await axiosCrufiture.put('/mace-alcool/lots/' + lotId.value + '/ingredients', {
            ingredients: ingrEdit.value
                .filter(i => i.article_id && i.quantite)
                .map(i => ({ article_id: Number(i.article_id), quantite: i.quantite, unite: i.unite, note: i.note })),
        });
        toast.add({ severity: 'success', summary: 'Ingrédients mis à jour', life: 3000 });
        editingIngr.value = false;
        await chargerLot();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: e.response?.data?.message ?? 'Impossible.', life: 4000 });
    } finally { savingIngr.value = false; }
}

// ── Stocker ───────────────────────────────────────────────────
const stockerVisible  = ref(false);
const bouteilles      = ref([]);
const declarerEnStock = ref(true);
const savingStockage  = ref(false);
const UNITES_STOCK = ['kg', 'L', 'piece', 'm', 'kWh'];
let _bKey = 0;
let _bDebounces = {};
function newBouteille() {
    return { _key: ++_bKey, stock_article_id: null, libelle: '', quantite: null, unite: 'piece', dlc: null, _suggestions: [], _searching: false };
}
async function rechercherBouteille(b, q) {
    if (!q || q.length < 2) { b._suggestions = []; return; }
    clearTimeout(_bDebounces[b._key]);
    _bDebounces[b._key] = setTimeout(async () => {
        b._searching = true;
        try {
            const res = await axiosStock.get('/articles', { params: { q, limit: 15, type: 'produit_fini' } });
            const items = Array.isArray(res.data?.details) ? res.data.details : [];
            b._suggestions = items.map(a => ({ id: Number(a.id), libelle: a.libelle, unite: a.unite ?? 'piece' }));
        } catch { b._suggestions = []; }
        finally { b._searching = false; }
    }, 300);
}
function selectionnerBouteille(b, art) {
    b.stock_article_id = Number(art.id);
    b.libelle          = art.libelle;
    b.unite            = art.unite || 'piece';
    b._suggestions     = [];
}

// ── Chargement ────────────────────────────────────────────────
onMounted(async () => {
    loading.value = true;
    await gammeStore.charger();
    await chargerLot();
    loading.value = false;
});

async function chargerLot() {
    try {
        const res = await axiosCrufiture.get('/mace-alcool/lots/' + lotId.value);
        if (res.data?.status === 'success') lot.value = res.data.details;
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger le lot.', life: 4000 });
    }
}

// ── Statut ────────────────────────────────────────────────────
const STATUT_CONFIG = {
    preparation:   { label: 'Préparation',  severity: 'secondary', icon: 'pi-clock' },
    en_maceration: { label: 'Macération',   severity: 'info',      icon: 'pi-bolt' },
    filtration:    { label: 'Filtration',   severity: 'warning',   icon: 'pi-filter' },
    assemblage:    { label: 'Assemblage',   severity: 'warning',   icon: 'pi-th-large' },
    maturation:    { label: 'Maturation',   severity: 'info',      icon: 'pi-clock' },
    stock:         { label: 'En stock',     severity: 'success',   icon: 'pi-check-circle' },
    abandonne:     { label: 'Abandonné',    severity: 'danger',    icon: 'pi-times-circle' },
};
function infosStatut(s) { return STATUT_CONFIG[s] ?? { label: s, severity: 'secondary', icon: 'pi-circle' }; }

function formatDt(d) {
    if (!d) return '—';
    const dt = new Date(d.replace(' ', 'T'));
    return dt.toLocaleDateString('fr-FR') + ' ' + dt.toLocaleTimeString('fr-FR', { hour:'2-digit', minute:'2-digit' });
}
function formatDate(d) {
    if (!d) return '—';
    const [y, m, j] = d.slice(0, 10).split('-');
    return `${j}/${m}/${y}`;
}

// ── Jours depuis un horodatage ────────────────────────────────
function joursSince(dt) {
    if (!dt) return null;
    const diff = Date.now() - new Date(dt.replace(' ', 'T')).getTime();
    return Math.floor(diff / 86400000);
}

// ── Transitions workflow ──────────────────────────────────────
async function transition(action, payload = {}) {
    savingTransition.value = true;
    try {
        const res = await axiosCrufiture.put(
            '/mace-alcool/lots/' + lotId.value + '/' + action,
            payload
        );
        if (res.data?.status === 'success') {
            await chargerLot();
            toast.add({ severity: 'success', summary: 'Statut mis à jour', life: 3000 });
        }
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: e.response?.data?.message ?? 'Impossible.', life: 4000 });
    } finally { savingTransition.value = false; }
}

// ── Contrôle qualité ─────────────────────────────────────────
async function sauvegarderControle() {
    savingControle.value = true;
    try {
        await axiosCrufiture.post('/mace-alcool/lots/' + lotId.value + '/controles', {
            ...controleForm.value,
        });
        toast.add({ severity: 'success', summary: 'Mesure enregistrée', life: 3000 });
        ajoutControle.value = false;
        controleForm.value  = { type: 'abv', valeur: null, notes: '' };
        await chargerLot();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: e.response?.data?.message ?? 'Impossible.', life: 4000 });
    } finally { savingControle.value = false; }
}

// ── Abandonner ────────────────────────────────────────────────
async function confirmerAbandon() {
    savingAbandon.value = true;
    try {
        await axiosCrufiture.put('/mace-alcool/lots/' + lotId.value + '/abandonner', { note: abandonNote.value });
        toast.add({ severity: 'warn', summary: 'Lot abandonné', life: 3000 });
        abandonVisible.value = false;
        await chargerLot();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: e.response?.data?.message ?? 'Impossible.', life: 4000 });
    } finally { savingAbandon.value = false; }
}

// ── Ouvrir dialog Stocker ─────────────────────────────────────
function ouvrirStocker() {
    bouteilles.value      = [newBouteille()];
    declarerEnStock.value = true;
    stockerVisible.value  = true;
}

// ── Stocker ───────────────────────────────────────────────────
async function confirmerStockage() {
    const lines = bouteilles.value.filter(b => b.libelle && b.quantite);
    if (lines.length === 0) {
        toast.add({ severity: 'warn', summary: 'Aucune bouteille renseignée', life: 3000 });
        return;
    }
    const sansUnite = lines.find(b => !b.stock_article_id && !b.unite);
    if (sansUnite) {
        toast.add({ severity: 'warn', summary: 'Unité manquante', detail: `"${sansUnite.libelle}" est un nouvel article — l'unité est obligatoire.`, life: 4000 });
        return;
    }
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const invalide = lines.find(b => !b.dlc || new Date(b.dlc) <= today);
    if (invalide) {
        toast.add({ severity: 'warn', summary: 'DLC invalide', detail: 'La DLC est obligatoire et doit être supérieure à aujourd\'hui.', life: 4000 });
        return;
    }
    savingStockage.value = true;
    try {
        await axiosCrufiture.put('/mace-alcool/lots/' + lotId.value + '/stocker', {
            bouteilles: lines.map(b => ({
                stock_article_id: b.stock_article_id ?? null,
                libelle:          b.libelle,
                quantite:         b.quantite,
                unite:            b.unite || 'piece',
                dlc:              b.dlc ? new Date(b.dlc).toISOString().slice(0, 10) : null,
            })),
            declarer_en_stock: declarerEnStock.value ? 1 : 0,
        });
        toast.add({ severity: 'success', summary: 'Lot mis en stock !', life: 4000 });
        stockerVisible.value = false;
        await chargerLot();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: e.response?.data?.message ?? 'Impossible.', life: 4000 });
    } finally { savingStockage.value = false; }
}

// ── Helpers ───────────────────────────────────────────────────
const canAbandon = computed(() => lot.value && !['stock', 'abandonne'].includes(lot.value.statut));

const ORDRE_WORKFLOW = ['preparation','en_maceration','filtration','maturation','stock'];
function etapeActive(idx, statut) { return ORDRE_WORKFLOW.indexOf(statut) === idx; }
function etapeDone(idx, statut)   { return ORDRE_WORKFLOW.indexOf(statut) > idx; }
</script>

<template>
<div class="col-12" v-if="loading">
    <div class="flex justify-content-center p-6"><ProgressSpinner /></div>
</div>

<div class="col-12" v-else-if="lot">
    <!-- ── En-tête ───────────────────────────────────────────── -->
    <div class="fl-header">
        <div class="fl-header-left">
            <Button icon="pi pi-arrow-left" text rounded size="small"
                    @click="router.push('/dashboard/maceration_alcool/lots')" />
            <div>
                <div class="fl-numero">{{ lot.numero_lot }}</div>
                <div class="fl-recette">{{ lot.recette_nom }} · v{{ lot.recette_version_numero }}</div>
            </div>
        </div>
        <div class="fl-header-right">
            <Tag :value="infosStatut(lot.statut).label"
                 :severity="infosStatut(lot.statut).severity"
                 style="font-size:12px" />
            <div v-if="lot.alerte_maceration || lot.alerte_maturation" class="fl-alerte-badge">
                <i class="pi pi-exclamation-triangle" />
                {{ lot.alerte_maceration ? 'Macération terminée — à filtrer' : 'Maturation terminée — à stocker' }}
            </div>
        </div>
    </div>

    <!-- ── Bandeau lot de test ─────────────────────────────────── -->
    <div v-if="lot.lot_test" class="fl-test-banner">
        <i class="pi pi-exclamation-triangle" style="font-size:14px" />
        <span><strong>Lot de test</strong> — basé sur une recette en version <em>en_test</em>.
        Les mesures sont tracées au registre avec le préfixe [TEST].</span>
    </div>

    <!-- ── Workflow ──────────────────────────────────────────── -->
    <div class="fl-workflow" v-if="lot.statut !== 'abandonne'">
        <div class="fl-workflow-etapes">
            <div v-for="(etape, i) in ['Préparation','Macération','Filtration','Maturation','Stock']"
                 :key="i"
                 class="fl-etape"
                 :class="{ 'fl-etape-active': etapeActive(i, lot.statut), 'fl-etape-done': etapeDone(i, lot.statut) }">
                <div class="fl-etape-dot" />
                <span class="fl-etape-label">{{ etape }}</span>
            </div>
        </div>
    </div>

    <!-- ── Actions transitions ───────────────────────────────── -->
    <div class="fl-actions" v-if="!['stock','abandonne'].includes(lot.statut)">
        <div v-if="lot.statut === 'preparation'" class="fl-transition-group">
            <Calendar v-model="dateMaceration" showTime hourFormat="24"
                      dateFormat="dd/mm/yy" class="fl-transition-date" />
            <Button label="Démarrer la macération" icon="pi pi-bolt"
                    :style="{ background: COULEUR, borderColor: COULEUR }"
                    :loading="savingTransition"
                    @click="transition('demarrer-maceration', { date: toIso(dateMaceration) })" />
        </div>
        <div v-if="lot.statut === 'en_maceration'" class="fl-transition-group">
            <Calendar v-model="dateFiltration" showTime hourFormat="24"
                      dateFormat="dd/mm/yy" class="fl-transition-date" />
            <Button label="Passer en filtration" icon="pi pi-filter"
                    :style="{ background: COULEUR, borderColor: COULEUR }"
                    :loading="savingTransition"
                    @click="transition('filtrer', { date: toIso(dateFiltration) })" />
        </div>
        <div v-if="lot.statut === 'filtration' && lot.avec_assemblage" class="fl-transition-group">
            <Calendar v-model="dateAssemblage" showTime hourFormat="24"
                      dateFormat="dd/mm/yy" class="fl-transition-date" />
            <Button label="Assembler (ajout sirop)" icon="pi pi-th-large"
                    outlined :loading="savingTransition"
                    @click="transition('assembler', { date: toIso(dateAssemblage) })" />
        </div>
        <div v-if="lot.statut === 'filtration' || lot.statut === 'assemblage'" class="fl-transition-group">
            <Calendar v-model="dateMaturation" showTime hourFormat="24"
                      dateFormat="dd/mm/yy" class="fl-transition-date" />
            <Button label="Démarrer la maturation" icon="pi pi-clock"
                    :style="lot.statut === 'assemblage' ? { background: COULEUR, borderColor: COULEUR } : {}"
                    :outlined="lot.statut !== 'assemblage'"
                    :loading="savingTransition"
                    @click="transition('demarrer-maturation', { date: toIso(dateMaturation) })" />
        </div>
        <Button
            v-if="lot.statut === 'maturation'"
            label="Mettre en stock"
            icon="pi pi-check-circle"
            :style="{ background: COULEUR, borderColor: COULEUR }"
            :loading="savingTransition"
            @click="ouvrirStocker"
        />
        <Button
            v-if="canAbandon"
            label="Abandonner"
            icon="pi pi-times"
            severity="danger"
            outlined
            @click="abandonVisible = true"
        />
    </div>

    <!-- ── Chronologie ────────────────────────────────────────── -->
    <div class="fl-section">
        <h3 class="fl-section-titre">Chronologie</h3>
        <div class="fl-chrono">
            <div class="fl-chrono-ligne">
                <span class="fl-chrono-label">Lot créé</span>
                <span class="fl-chrono-val">{{ formatDate(lot.date_production) }}</span>
            </div>
            <div v-if="lot.date_debut_maceration" class="fl-chrono-ligne">
                <span class="fl-chrono-label">Début macération</span>
                <span class="fl-chrono-val">
                    {{ formatDt(lot.date_debut_maceration) }}
                    <span v-if="lot.duree_maceration_cible_j" class="fl-chrono-cible">
                        — cible {{ lot.duree_maceration_cible_j }}j
                        <span v-if="lot.statut === 'en_maceration'" class="fl-chrono-compteur">
                            ({{ joursSince(lot.date_debut_maceration) }}j écoulés)
                        </span>
                    </span>
                </span>
            </div>
            <div v-if="lot.date_filtration" class="fl-chrono-ligne">
                <span class="fl-chrono-label">Filtration</span>
                <span class="fl-chrono-val">{{ formatDt(lot.date_filtration) }}</span>
            </div>
            <div v-if="lot.date_assemblage" class="fl-chrono-ligne">
                <span class="fl-chrono-label">Assemblage</span>
                <span class="fl-chrono-val">{{ formatDt(lot.date_assemblage) }}</span>
            </div>
            <div v-if="lot.date_debut_maturation" class="fl-chrono-ligne">
                <span class="fl-chrono-label">Début maturation</span>
                <span class="fl-chrono-val">
                    {{ formatDt(lot.date_debut_maturation) }}
                    <span v-if="lot.duree_maturation_cible_j" class="fl-chrono-cible">
                        — cible {{ lot.duree_maturation_cible_j }}j
                        <span v-if="lot.statut === 'maturation'" class="fl-chrono-compteur">
                            ({{ joursSince(lot.date_debut_maturation) }}j écoulés)
                        </span>
                    </span>
                </span>
            </div>
            <div v-if="lot.date_mise_en_stock" class="fl-chrono-ligne">
                <span class="fl-chrono-label">Mise en stock</span>
                <span class="fl-chrono-val">{{ formatDt(lot.date_mise_en_stock) }}</span>
            </div>
        </div>
    </div>

    <!-- ── Ingrédients ────────────────────────────────────────── -->
    <div class="fl-section" v-if="lot.ingredients?.length || lot.statut === 'preparation'">
        <div class="fl-section-header">
            <h3 class="fl-section-titre">Ingrédients</h3>
            <div v-if="lot.statut === 'preparation'" style="display:flex;gap:6px;align-items:center">
                <template v-if="!editingIngr">
                    <Button label="Modifier" icon="pi pi-pencil" text size="small"
                            @click="initEditionIngredients" />
                </template>
                <template v-else>
                    <Button icon="pi pi-plus" label="Ajouter" text size="small"
                            @click="ingrEdit.push(newIngrEdit())" />
                    <Button label="Sauvegarder" icon="pi pi-check" size="small"
                            :style="{ background: COULEUR, borderColor: COULEUR }"
                            :loading="savingIngr"
                            @click="sauvegarderIngredients" />
                    <Button label="Annuler" text size="small"
                            :disabled="savingIngr"
                            @click="editingIngr = false" />
                </template>
            </div>
        </div>

        <!-- Mode lecture -->
        <div v-if="!editingIngr">
            <p v-if="!lot.ingredients?.length" class="fl-empty">Aucun ingrédient. Cliquez "Modifier" pour en ajouter.</p>
            <div v-else class="fl-ingr-list">
                <div v-for="ing in lot.ingredients" :key="ing.id" class="fl-ingr-row">
                    <span class="fl-ingr-lib">{{ ing.article_libelle ?? '—' }}</span>
                    <span class="fl-ingr-qty">{{ ing.quantite }} {{ ing.unite }}</span>
                    <span v-if="ing.note" class="fl-ingr-note">{{ ing.note }}</span>
                </div>
            </div>
        </div>

        <!-- Mode édition -->
        <div v-else class="fl-ingr-edit-list">
            <div v-for="(ing, idx) in ingrEdit" :key="ing._key" class="fl-ingr-edit-row">
                <span class="fl-ingr-num">{{ idx + 1 }}</span>

                <div class="fl-ingr-art">
                    <span v-if="ingrEdit[idx].article_id" class="fl-art-badge">
                        {{ ingrEdit[idx].article_lib }}
                        <i class="pi pi-times" style="font-size:9px;margin-left:4px;cursor:pointer"
                           @click="ingrEdit[idx].article_id = null; ingrEdit[idx].article_lib = ''" />
                    </span>
                    <AutoComplete v-else
                        :suggestions="ingrEdit[idx]._suggestions"
                        optionLabel="libelle"
                        placeholder="Article stock *"
                        size="small"
                        :delay="300"
                        @complete="(e) => rechercherArticle(ingrEdit[idx], e.query)"
                        @item-select="(e) => selectionnerArticle(ingrEdit[idx], e.value)"
                        class="w-full"
                    />
                </div>

                <div class="fl-ingr-qty-edit">
                    <InputNumber v-model="ingrEdit[idx].quantite"
                                 :min="0" :maxFractionDigits="3"
                                 placeholder="Qté *" size="small" class="w-full" />
                </div>

                <div class="fl-ingr-unite">
                    <InputText v-model="ingrEdit[idx].unite"
                               placeholder="kg" size="small" class="w-full" />
                </div>

                <Button icon="pi pi-trash" text rounded severity="danger" size="small"
                        @click="ingrEdit.splice(idx, 1)" />
            </div>
        </div>
    </div>

    <!-- ── Contrôles qualité ─────────────────────────────────── -->
    <div class="fl-section">
        <div class="fl-section-header">
            <h3 class="fl-section-titre">Contrôles qualité</h3>
            <Button v-if="lot.statut !== 'abandonne'"
                    label="Ajouter une mesure" icon="pi pi-plus" text size="small"
                    @click="ajoutControle = !ajoutControle" />
        </div>

        <!-- Formulaire ajout contrôle -->
        <div v-if="ajoutControle" class="fl-controle-form">
            <Dropdown v-model="controleForm.type"
                      :options="TYPE_CONTROLES"
                      optionLabel="label" optionValue="value"
                      class="fl-controle-type" />
            <InputNumber v-model="controleForm.valeur"
                         placeholder="Valeur" :maxFractionDigits="3"
                         class="fl-controle-val" />
            <InputText v-model="controleForm.notes" placeholder="Notes" class="fl-controle-notes" />
            <Button icon="pi pi-check" :loading="savingControle" @click="sauvegarderControle" />
            <Button icon="pi pi-times" text @click="ajoutControle = false" />
        </div>

        <p v-if="!lot.controles?.length" class="fl-empty">Aucun contrôle enregistré.</p>
        <div v-else class="fl-controles-list">
            <div v-for="c in lot.controles" :key="c.id" class="fl-controle-row">
                <span class="fl-ctrl-type">{{ TYPE_CONTROLES.find(t => t.value === c.type)?.label ?? c.type }}</span>
                <span class="fl-ctrl-val">{{ c.valeur ?? '—' }}</span>
                <span class="fl-ctrl-date">{{ formatDt(c.date_mesure) }}</span>
                <span v-if="c.notes" class="fl-ctrl-notes">{{ c.notes }}</span>
            </div>
        </div>
    </div>

    <!-- ── Produits mis en stock ─────────────────────────────── -->
    <div class="fl-section" v-if="lot.produits?.length">
        <h3 class="fl-section-titre">Bouteilles produites</h3>
        <div class="fl-produits-list">
            <div v-for="p in lot.produits" :key="p.id" class="fl-produit-row">
                <span class="fl-prod-nom">{{ p.produit_nom ?? '—' }}</span>
                <span class="fl-prod-qty">{{ p.quantite_produite }}</span>
                <span v-if="p.dlc" class="fl-prod-dlc">DLC {{ formatDate(p.dlc) }}</span>
            </div>
        </div>
    </div>

    <!-- ── Note ──────────────────────────────────────────────── -->
    <div class="fl-section" v-if="lot.note">
        <h3 class="fl-section-titre">Note</h3>
        <p style="font-size:13px;color:var(--text-color-secondary);margin:0">{{ lot.note }}</p>
    </div>

    <!-- ── Dialog Stocker ────────────────────────────────────── -->
    <Dialog v-model:visible="stockerVisible" header="Mise en stock — bouteilles produites"
            :style="{ width: '560px' }" modal>
        <div class="fl-stocker">
            <p style="font-size:13px;color:var(--text-color-secondary);margin:0 0 1rem">
                Saisir les bouteilles produites. Chaque format est un produit distinct.
            </p>

            <div v-for="(b, bi) in bouteilles" :key="b._key" class="fl-bott-row">
                <!-- Produit — autocomplete /stock, libellé libre si absent -->
                <div style="flex:1;min-width:160px">
                    <span v-if="bouteilles[bi].stock_article_id" class="fl-art-badge">
                        {{ bouteilles[bi].libelle }}
                        <i class="pi pi-times" style="font-size:9px;margin-left:4px;cursor:pointer"
                           @click="bouteilles[bi].stock_article_id = null; bouteilles[bi].libelle = ''; bouteilles[bi].unite = 'piece'" />
                    </span>
                    <AutoComplete v-else
                        v-model="bouteilles[bi].libelle"
                        :suggestions="bouteilles[bi]._suggestions"
                        optionLabel="libelle"
                        placeholder="Ex: Limoncello 1L *"
                        :delay="300"
                        class="w-full"
                        @complete="(e) => rechercherBouteille(bouteilles[bi], e.query)"
                        @item-select="(e) => selectionnerBouteille(bouteilles[bi], e.value)"
                    />
                </div>
                <!-- Unité — read-only si article existant, éditable si création à la volée -->
                <div style="width:80px">
                    <span v-if="bouteilles[bi].stock_article_id"
                          style="font-size:12px;color:var(--text-color-secondary);padding:0 4px">
                        {{ bouteilles[bi].unite }}
                    </span>
                    <Dropdown v-else
                        v-model="bouteilles[bi].unite"
                        :options="UNITES_STOCK"
                        placeholder="Unité *"
                        size="small"
                        class="w-full"
                    />
                </div>
                <InputNumber v-model="bouteilles[bi].quantite"
                             :min="0" placeholder="Qté *"
                             style="width:90px" />
                <Calendar v-model="bouteilles[bi].dlc"
                          placeholder="DLC" dateFormat="dd/mm/yy"
                          style="width:130px" />
                <Button icon="pi pi-trash" text rounded severity="danger" size="small"
                        @click="bouteilles.splice(bi, 1)" />
            </div>

            <Button label="+ Format" text size="small"
                    style="color:#7F77DD;margin-top:8px"
                    @click="bouteilles.push(newBouteille())" />

            <!-- Déclaration stock — optionnelle pour les lots de test -->
            <div v-if="lot.lot_test" class="fl-stocker-test">
                <InputSwitch v-model="declarerEnStock" inputId="decl-stock" />
                <label for="decl-stock" style="font-size:13px;cursor:pointer;user-select:none">
                    Déclarer les bouteilles en stock
                    <span style="color:#92400e;font-size:11px">(lot de test — décocher pour tracer sans entrée stock)</span>
                </label>
            </div>
        </div>
        <template #footer>
            <Button label="Annuler" text :disabled="savingStockage" @click="stockerVisible = false" />
            <Button label="Confirmer la mise en stock"
                    icon="pi pi-check-circle"
                    :style="{ background: COULEUR, borderColor: COULEUR }"
                    :loading="savingStockage"
                    @click="confirmerStockage" />
        </template>
    </Dialog>

    <!-- ── Dialog Abandonner ─────────────────────────────────── -->
    <Dialog v-model:visible="abandonVisible" header="Abandonner ce lot ?" :style="{ width: '420px' }" modal>
        <p style="font-size:13px;color:var(--text-color-secondary);margin:0 0 12px">
            Le lot {{ lot.numero_lot }} sera marqué abandonné. Cette action est irréversible.
        </p>
        <Textarea v-model="abandonNote" rows="3" class="w-full" placeholder="Raison de l'abandon (optionnel)" autoResize />
        <template #footer>
            <Button label="Annuler" text :disabled="savingAbandon" @click="abandonVisible = false" />
            <Button label="Abandonner" severity="danger" icon="pi pi-times"
                    :loading="savingAbandon" @click="confirmerAbandon" />
        </template>
    </Dialog>
</div>
</template>


<style scoped>
.fl-header { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.25rem;gap:1rem; }
.fl-header-left { display:flex;align-items:center;gap:12px; }
.fl-numero { font-family:monospace;font-size:22px;font-weight:900;color:var(--text-color);line-height:1.1; }
.fl-recette { font-size:13px;color:var(--text-color-secondary); }
.fl-header-right { display:flex;flex-direction:column;align-items:flex-end;gap:6px; }
.fl-alerte-badge { display:inline-flex;align-items:center;gap:6px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:6px;padding:4px 10px;font-size:12px;font-weight:600; }

/* Workflow */
.fl-workflow { background:var(--surface-card);border:1px solid var(--surface-border);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem; }
.fl-workflow-etapes { display:flex;align-items:center;gap:0;position:relative; }
.fl-etape { flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;position:relative; }
.fl-etape:not(:last-child)::after { content:'';position:absolute;top:9px;left:50%;width:100%;height:2px;background:var(--surface-border); }
.fl-etape-done .fl-etape-dot  { background:#7F77DD; }
.fl-etape-done::after { background:#7F77DD !important; }
.fl-etape-active .fl-etape-dot { background:#7F77DD;box-shadow:0 0 0 3px #EEEDFE; }
.fl-etape-dot { width:18px;height:18px;border-radius:50%;background:var(--surface-border);position:relative;z-index:1;transition:background 0.2s; }
.fl-etape-label { font-size:11px;color:var(--text-color-secondary);text-align:center;white-space:nowrap; }
.fl-etape-active .fl-etape-label { color:#7F77DD;font-weight:700; }

/* Actions */
.fl-actions { display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1.25rem;align-items:center; }
.fl-transition-group { display:flex;align-items:center;gap:6px; }
.fl-transition-date { width:180px; }

/* Sections */
.fl-section { background:var(--surface-card);border:1px solid var(--surface-border);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem; }
.fl-section-titre { font-size:14px;font-weight:700;color:var(--text-color);margin:0 0 0.875rem; }
.fl-section-header { display:flex;align-items:center;justify-content:space-between;margin-bottom:0.875rem; }
.fl-section-header .fl-section-titre { margin:0; }
.fl-empty { font-size:13px;color:#bbb;font-style:italic;margin:0;padding:4px 0; }

/* Chronologie */
.fl-chrono { display:flex;flex-direction:column;gap:6px; }
.fl-chrono-ligne { display:flex;gap:12px;align-items:baseline; }
.fl-chrono-label { font-size:12px;font-weight:600;color:var(--text-color-secondary);width:160px;flex-shrink:0; }
.fl-chrono-val { font-size:13px;color:var(--text-color); }
.fl-chrono-cible { font-size:11px;color:var(--text-color-secondary); }
.fl-chrono-compteur { color:#7F77DD;font-weight:600; }

/* Ingrédients */
.fl-ingr-list { display:flex;flex-direction:column;gap:4px; }
.fl-ingr-row { display:flex;align-items:center;gap:10px;padding:4px 0;border-bottom:1px solid var(--surface-border); }
.fl-ingr-row:last-child { border-bottom:none; }
.fl-ingr-lib { flex:1;font-size:13px;font-weight:500;color:var(--text-color); }
.fl-ingr-qty { font-size:13px;color:var(--text-color-secondary);flex-shrink:0; }
.fl-ingr-note { font-size:11px;color:#bbb;font-style:italic; }

/* Ingrédients édition */
.fl-ingr-edit-list { display:flex;flex-direction:column;gap:0; }
.fl-ingr-edit-row { display:flex;align-items:center;gap:6px;padding:5px 0;border-bottom:1px solid var(--surface-border); }
.fl-ingr-edit-row:last-child { border-bottom:none; }
.fl-ingr-num { flex-shrink:0;width:18px;text-align:right;font-size:11px;color:#bbb; }
.fl-ingr-art { flex:1;min-width:160px; }
.fl-ingr-qty-edit { width:100px; }
.fl-ingr-unite { width:65px; }
.fl-art-badge { display:inline-flex;align-items:center;background:#EEEDFE;color:#7F77DD;border-radius:5px;padding:3px 8px;font-size:11px;font-weight:600;white-space:nowrap; }

/* Contrôles */
.fl-controle-form { display:flex;align-items:center;gap:6px;margin-bottom:0.875rem;padding:8px;background:var(--surface-ground,#f9f9f9);border-radius:7px; }
.fl-controle-type { width:160px; }
.fl-controle-val  { width:100px; }
.fl-controle-notes { flex:1; }

.fl-controles-list { display:flex;flex-direction:column;gap:4px; }
.fl-controle-row { display:flex;align-items:center;gap:10px;padding:4px 0;border-bottom:1px solid var(--surface-border); }
.fl-controle-row:last-child { border-bottom:none; }
.fl-ctrl-type  { font-size:12px;font-weight:700;color:var(--text-color);width:120px;flex-shrink:0; }
.fl-ctrl-val   { font-size:14px;font-weight:600;color:#7F77DD;width:70px;flex-shrink:0; }
.fl-ctrl-date  { font-size:11px;color:var(--text-color-secondary);flex-shrink:0; }
.fl-ctrl-notes { font-size:11px;color:#aaa;font-style:italic;flex:1; }

/* Produits */
.fl-produits-list { display:flex;flex-direction:column;gap:4px; }
.fl-produit-row { display:flex;align-items:center;gap:10px;padding:4px 0;border-bottom:1px solid var(--surface-border); }
.fl-produit-row:last-child { border-bottom:none; }
.fl-prod-nom { flex:1;font-size:13px;font-weight:500;color:var(--text-color); }
.fl-prod-qty { font-size:13px;color:var(--text-color-secondary);flex-shrink:0; }
.fl-prod-dlc { font-size:11px;color:#7F77DD;flex-shrink:0; }

/* Stocker */
.fl-stocker .fl-bott-row { display:flex;align-items:center;gap:6px;margin-bottom:6px; }
.fl-stocker-test { display:flex;align-items:center;gap:10px;margin-top:14px;padding:10px 12px;background:#FEF3C7;border:1px solid #FDE68A;border-radius:7px; }

/* Bandeau test */
.fl-test-banner { display:flex;align-items:flex-start;gap:10px;margin-bottom:1rem;padding:10px 14px;background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;font-size:13px;color:#92400E;line-height:1.5; }
</style>
