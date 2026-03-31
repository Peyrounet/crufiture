<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';
import axios from '@/plugins/axios';

const route  = useRoute();
const router = useRouter();
const toast  = useToast();

// ── Mode : nouvelle recette ou édition ────────────────────────────
const isNouvelle = computed(() => route.params.id === 'nouvelle');
const recetteId  = computed(() => isNouvelle.value ? null : parseInt(route.params.id));

// ── Chargement initial ────────────────────────────────────────────
const loading = ref(true);
const saving  = ref(false);
const saveurs = ref([]);

// ── Formulaire principal ──────────────────────────────────────────
const form = reactive({
    saveur_id: null,
    titre:     '',
    note:      '',
});

// ── Ingrédients ───────────────────────────────────────────────────
// Deux listes séparées — fruits (pivot + fruit) et additifs
const fruits  = ref([]); // [{ _key, produit_id, produit_lib, type:'pivot'|'fruit', pct_base:null|number, note }]
const additifs = ref([]); // [{ _key, produit_id, produit_lib, pct_base:number, note }]

let _keyCounter = 0;
function newKey() { return ++_keyCounter; }

function ingredientVide(type) {
    return {
        _key:         newKey(),
        produit_id:   null,
        produit_lib:  '',
        type:         type,
        pct_base:     null,
        note:         '',
        // autocomplétion
        _suggestions: [],
        _searching:   false,
    };
}

// ── Étapes ────────────────────────────────────────────────────────
const etapes = ref([]); // [{ _key, contenu }]

function etapeVide() {
    return { _key: newKey(), contenu: '' };
}

// ── Drag & drop fruits ────────────────────────────────────────────
const dragIndex = ref(null);

function onDragStart(idx) {
    dragIndex.value = idx;
}

function onDragOver(e) {
    e.preventDefault();
}

function onDrop(targetIdx) {
    if (dragIndex.value === null || dragIndex.value === targetIdx) return;
    const arr   = [...fruits.value];
    const [item] = arr.splice(dragIndex.value, 1);
    arr.splice(targetIdx, 0, item);
    // Le premier de la liste devient toujours le pivot
    arr.forEach((f, i) => { f.type = i === 0 ? 'pivot' : 'fruit'; });
    fruits.value  = arr;
    dragIndex.value = null;
}

function onDragEnd() {
    dragIndex.value = null;
}

// Drag & drop étapes
const dragEtapeIndex = ref(null);

function onDragStartEtape(idx) { dragEtapeIndex.value = idx; }

function onDropEtape(targetIdx) {
    if (dragEtapeIndex.value === null || dragEtapeIndex.value === targetIdx) return;
    const arr    = [...etapes.value];
    const [item] = arr.splice(dragEtapeIndex.value, 1);
    arr.splice(targetIdx, 0, item);
    etapes.value       = arr;
    dragEtapeIndex.value = null;
}

function onDragEndEtape() { dragEtapeIndex.value = null; }

// ── Nom du pivot pour le label dynamique ──────────────────────────
const nomPivot = computed(() => {
    const p = fruits.value.find(f => f.type === 'pivot');
    return p?.produit_lib || 'fruit principal';
});

// ── Calculette mixture temps réel ─────────────────────────────────
// Base = 1 kg de pivot → quantités des autres fruits
const calcMixture = computed(() => {
    if (fruits.value.length < 2) return [];
    return fruits.value.filter(f => f.type === 'fruit' && f.pct_base !== null).map(f => {
        const kg = f.pct_base / 100;
        return {
            libelle:  f.produit_lib || '—',
            affichage: kg >= 0.1 ? kg.toFixed(3) + ' kg' : (kg * 1000).toFixed(0) + ' g',
        };
    });
});

const baseTotale = computed(() => {
    let total = 1; // 1 kg pivot
    for (const f of fruits.value) {
        if (f.type === 'fruit' && f.pct_base !== null) {
            total += f.pct_base / 100;
        }
    }
    return total.toFixed(3);
});

// Calculette additifs temps réel (pour 1 kg de base)
const calcAdditifs = computed(() => {
    return additifs.value
        .filter(a => a.pct_base !== null && a.pct_base > 0)
        .map(a => {
            const kg = a.pct_base / 100;
            return {
                libelle:   a.produit_lib || '—',
                affichage: kg >= 0.1 ? kg.toFixed(3) + ' kg' : (kg * 1000).toFixed(1) + ' g',
            };
        });
});

// ── Autocomplétion produits peyrounet ────────────────────────────
let _debounce = {};

async function rechercherProduit(ing, q) {
    if (!q || q.length < 2) {
        ing._suggestions = [];
        return;
    }
    // Debounce par ingrédient
    clearTimeout(_debounce[ing._key]);
    _debounce[ing._key] = setTimeout(async () => {
        ing._searching = true;
        try {
            const res = await axios.get('/inter/produits', { params: { q } });
            ing._suggestions = res.data?.details ?? [];
        } catch {
            ing._suggestions = [];
        } finally {
            ing._searching = false;
        }
    }, 300);
}

function selectionnerProduit(ing, produit) {
    ing.produit_id  = produit.id;
    ing.produit_lib = produit.libelle_canonique;
    ing._suggestions = [];
}

function effacerProduit(ing) {
    ing.produit_id   = null;
    ing.produit_lib  = '';
    ing._suggestions = [];
}

// ── Ajout / suppression ingrédients ──────────────────────────────
function ajouterFruit() {
    const type = fruits.value.length === 0 ? 'pivot' : 'fruit';
    fruits.value.push(ingredientVide(type));
}

function supprimerFruit(idx) {
    fruits.value.splice(idx, 1);
    // Réassigner le pivot si nécessaire
    if (fruits.value.length > 0) {
        fruits.value.forEach((f, i) => { f.type = i === 0 ? 'pivot' : 'fruit'; });
    }
}

function ajouterAdditif() {
    additifs.value.push(ingredientVide('additif'));
}

function supprimerAdditif(idx) {
    additifs.value.splice(idx, 1);
}

// ── Ajout / suppression étapes ────────────────────────────────────
function ajouterEtape() {
    etapes.value.push(etapeVide());
    // Focus auto sur la nouvelle étape
    setTimeout(() => {
        const textareas = document.querySelectorAll('.etape-textarea');
        if (textareas.length) textareas[textareas.length - 1].focus();
    }, 50);
}

function supprimerEtape(idx) {
    etapes.value.splice(idx, 1);
}

// ── Chargement données ────────────────────────────────────────────
onMounted(async () => {
    // Charger la liste des saveurs
    try {
        const res = await axiosCrufiture.get('/saveurs');
        if (res.data?.status === 'success') {
            saveurs.value = res.data.details.filter(s => s.actif === 1);
        }
    } catch { /* silencieux */ }

    if (!isNouvelle.value) {
        await chargerRecette();
    } else {
        loading.value = false;
    }
});

async function chargerRecette() {
    loading.value = true;
    try {
        const res = await axiosCrufiture.get('/recettes/' + recetteId.value);
        if (res.data?.status === 'success') {
            const r = res.data.details;
            form.saveur_id = r.saveur_id;
            form.titre     = r.titre;
            form.note      = r.note ?? '';

            // Reconstruire les deux listes depuis les ingrédients
            fruits.value  = [];
            additifs.value = [];
            for (const ing of r.ingredients) {
                const item = {
                    _key:         newKey(),
                    produit_id:   ing.produit_id,
                    produit_lib:  ing.libelle_canonique,
                    type:         ing.type,
                    pct_base:     ing.pct_base,
                    note:         ing.note ?? '',
                    _suggestions: [],
                    _searching:   false,
                };
                if (ing.type === 'pivot' || ing.type === 'fruit') {
                    fruits.value.push(item);
                } else {
                    additifs.value.push(item);
                }
            }

            etapes.value = r.etapes.map(e => ({
                _key:    newKey(),
                contenu: e.contenu,
            }));
        }
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger la recette.', life: 4000 });
    } finally {
        loading.value = false;
    }
}

// ── Validation ────────────────────────────────────────────────────
function valider() {
    if (!form.saveur_id) {
        toast.add({ severity: 'warn', summary: 'Champ manquant', detail: 'Choisissez une saveur.', life: 3000 });
        return false;
    }
    if (!form.titre.trim()) {
        toast.add({ severity: 'warn', summary: 'Champ manquant', detail: 'Le titre est obligatoire.', life: 3000 });
        return false;
    }
    if (fruits.value.length === 0) {
        toast.add({ severity: 'warn', summary: 'Mixture vide', detail: 'Ajoutez au moins un fruit principal.', life: 3000 });
        return false;
    }
    return true;
}

// ── Construction payload ──────────────────────────────────────────
function buildPayload() {
    const ingredients = [];
    fruits.value.forEach((f, idx) => {
        ingredients.push({
            produit_id: f.produit_id,
            type:       f.type,
            pct_base:   f.pct_base,
            note:       f.note || null,
        });
    });
    additifs.value.forEach((a) => {
        ingredients.push({
            produit_id: a.produit_id,
            type:       'additif',
            pct_base:   a.pct_base,
            note:       a.note || null,
        });
    });

    return {
        saveur_id:   form.saveur_id,
        titre:       form.titre.trim(),
        note:        form.note.trim() || null,
        ingredients,
        etapes: etapes.value.map(e => ({ contenu: e.contenu })),
    };
}

// ── Sauvegarder ───────────────────────────────────────────────────
async function sauvegarder() {
    if (!valider()) return;
    saving.value = true;
    try {
        const payload = buildPayload();
        if (isNouvelle.value) {
            const res = await axiosCrufiture.post('/recettes', payload);
            toast.add({ severity: 'success', summary: 'Créée', detail: 'Recette créée avec succès.', life: 3000 });
            router.replace('/dashboard/recettes/' + res.data.details.id);
        } else {
            await axiosCrufiture.put('/recettes/' + recetteId.value + '/complet', payload);
            toast.add({ severity: 'success', summary: 'Enregistré', detail: 'Recette mise à jour.', life: 3000 });
        }
    } catch (err) {
        const detail = err.response?.data?.message ?? 'Erreur lors de l\'enregistrement.';
        toast.add({ severity: 'error', summary: 'Erreur', detail, life: 4000 });
    } finally {
        saving.value = false;
    }
}

function retourListe() {
    router.push('/dashboard/recettes');
}
</script>

<template>
<div class="col-12">
    <PageCard>
        <template #titre>
            <div class="ed-header-titre">
                <Button icon="pi pi-arrow-left" text rounded @click="retourListe" v-tooltip.right="'Retour aux recettes'" />
                <span>{{ isNouvelle ? 'Nouvelle recette' : 'Éditer la recette' }}</span>
            </div>
        </template>
        <template #actions>
            <Button label="Enregistrer" icon="pi pi-check" :loading="saving" @click="sauvegarder" />
        </template>

        <div v-if="loading" class="flex justify-content-center p-5">
            <ProgressSpinner />
        </div>

        <div v-else class="ed-layout">

            <!-- ══════════════════════════════════════════════════
                 COLONNE GAUCHE — En-tête + Mixture + Additifs
            ══════════════════════════════════════════════════ -->
            <div class="ed-col-left">

                <!-- ── En-tête recette ───────────────────────── -->
                <div class="ed-section">
                    <div class="ed-section-title">Informations</div>

                    <div class="ed-field">
                        <label>Saveur <span class="cruf-required">*</span></label>
                        <Dropdown
                            v-model="form.saveur_id"
                            :options="saveurs"
                            optionLabel="nom"
                            optionValue="id"
                            placeholder="Choisir une saveur…"
                            class="w-full"
                            :disabled="!isNouvelle"
                        />
                        <small v-if="!isNouvelle" class="cruf-hint">La saveur ne peut pas être modifiée après création.</small>
                    </div>

                    <div class="ed-field">
                        <label>Titre <span class="cruf-required">*</span></label>
                        <InputText v-model="form.titre" class="w-full" placeholder="ex : Rhubarbe Fleur de Sureau v1" />
                    </div>

                    <div class="ed-field">
                        <label>Note</label>
                        <Textarea
                            v-model="form.note"
                            class="w-full"
                            :autoResize="true"
                            rows="2"
                            placeholder="Remarques, variantes, conseils…"
                        />
                    </div>
                </div>

                <!-- ── Zone 1 : Mixture de base ─────────────── -->
                <div class="ed-section">
                    <div class="ed-section-title">
                        Mixture de base
                        <span class="ed-section-hint">Le premier fruit est la référence</span>
                    </div>

                    <!-- Liste fruits -->
                    <div class="ed-fruits-list">
                        <div
                            v-for="(fruit, idx) in fruits"
                            :key="fruit._key"
                            class="ed-fruit-row"
                            :class="{ 'is-pivot': fruit.type === 'pivot', 'is-dragging': dragIndex === idx }"
                            draggable="true"
                            @dragstart="onDragStart(idx)"
                            @dragover="onDragOver"
                            @drop="onDrop(idx)"
                            @dragend="onDragEnd"
                        >
                            <!-- Poignée drag -->
                            <div class="ed-drag-handle" title="Réordonner">
                                <i class="pi pi-bars" />
                            </div>

                            <!-- Indicateur pivot -->
                            <div class="ed-pivot-indicator" v-tooltip.top="fruit.type === 'pivot' ? 'Fruit de référence' : ''">
                                <i v-if="fruit.type === 'pivot'" class="pi pi-star-fill" style="color: var(--primary-color, #f59e0b); font-size:12px" />
                                <i v-else class="pi pi-star" style="color:#ddd; font-size:12px" />
                            </div>

                            <!-- Champs -->
                            <div class="ed-fruit-fields">
                                <!-- Ligne 1 : produit + % -->
                                <div class="ed-fruit-row1">
                                    <!-- Lien produit peyrounet -->
                                    <div class="ed-field-inline ed-produit-field" style="flex:1">
                                        <div v-if="fruit.produit_id" class="ed-produit-tag">
                                            <i class="pi pi-link" style="font-size:11px" />
                                            {{ fruit.produit_lib }}
                                            <Button icon="pi pi-times" text rounded size="small" @click="effacerProduit(fruits[idx])" style="width:20px;height:20px;padding:0" />
                                        </div>
                                        <div v-else class="ed-produit-search">
                                            <InputText
                                                :placeholder="fruit.type === 'pivot' ? 'Chercher le fruit principal…' : 'Chercher un fruit…'"
                                                class="w-full"
                                                @input="rechercherProduit(fruits[idx], $event.target.value)"
                                            />
                                            <div v-if="fruit._suggestions.length" class="ed-suggestions">
                                                <div
                                                    v-for="s in fruit._suggestions"
                                                    :key="s.id"
                                                    class="ed-suggestion-item"
                                                    @click="selectionnerProduit(fruits[idx], s)"
                                                >
                                                    <span class="ed-sugg-libelle">{{ s.libelle_canonique }}</span>
                                                    <span v-if="s.categorie" class="ed-sugg-cat">{{ s.categorie }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- % du pivot (sauf pour le pivot lui-même) -->
                                    <div v-if="fruit.type !== 'pivot'" class="ed-field-inline ed-pct-field" style="flex:0 0 160px">
                                        <div class="ed-pct-wrapper">
                                            <InputNumber
                                                v-model="fruits[idx].pct_base"
                                                :min="0"
                                                :max="9999"
                                                :minFractionDigits="0"
                                                :maxFractionDigits="1"
                                                inputClass="w-full"
                                                class="w-full"
                                            />
                                            <span class="ed-pct-label">% de {{ nomPivot }}</span>
                                        </div>
                                    </div>
                                    <div v-else style="flex:0 0 160px" />

                                    <!-- Supprimer -->
                                    <Button
                                        icon="pi pi-trash"
                                        text rounded
                                        severity="danger"
                                        @click="supprimerFruit(idx)"
                                        :disabled="fruits.length === 1"
                                        style="flex-shrink:0"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <Button
                        label="Ajouter un fruit"
                        icon="pi pi-plus"
                        text
                        size="small"
                        @click="ajouterFruit"
                        class="mt-2"
                    />

                    <!-- Calculette mixture -->
                    <div v-if="fruits.length >= 2 && calcMixture.length" class="ed-calc-box">
                        <div class="ed-calc-title">Pour <strong>1 kg</strong> de {{ nomPivot }} →</div>
                        <div class="ed-calc-rows">
                            <span v-for="c in calcMixture" :key="c.libelle" class="ed-calc-chip">
                                {{ c.libelle }} : {{ c.affichage }}
                            </span>
                        </div>
                        <div class="ed-calc-total">Base totale : <strong>{{ baseTotale }} kg</strong></div>
                    </div>
                </div>

                <!-- ── Zone 2 : Ingrédients supplémentaires ─── -->
                <div class="ed-section">
                    <div class="ed-section-title">
                        Ingrédients supplémentaires
                        <span class="ed-section-hint">% de la base totale</span>
                    </div>

                    <div v-if="additifs.length === 0" class="cruf-empty" style="margin-bottom:0.5rem">
                        Aucun ingrédient supplémentaire.
                    </div>

                    <div v-else class="ed-additifs-list">
                        <div
                            v-for="(additif, idx) in additifs"
                            :key="additif._key"
                            class="ed-additif-row"
                        >
                            <!-- Libellé -->
                            <div class="ed-field-inline ed-produit-field" style="flex:1">
                                <div v-if="additif.produit_id" class="ed-produit-tag">
                                    <i class="pi pi-link" style="font-size:11px" />
                                    {{ additif.produit_lib }}
                                    <Button icon="pi pi-times" text rounded size="small" @click="effacerProduit(additifs[idx])" style="width:20px;height:20px;padding:0" />
                                </div>
                                <div v-else class="ed-produit-search">
                                    <InputText
                                        placeholder="Chercher dans peyrounet…"
                                        class="w-full"
                                        @input="rechercherProduit(additifs[idx], $event.target.value)"
                                    />
                                    <div v-if="additif._suggestions.length" class="ed-suggestions">
                                        <div
                                            v-for="s in additif._suggestions"
                                            :key="s.id"
                                            class="ed-suggestion-item"
                                            @click="selectionnerProduit(additifs[idx], s)"
                                        >
                                            <span class="ed-sugg-libelle">{{ s.libelle_canonique }}</span>
                                            <span v-if="s.categorie" class="ed-sugg-cat">{{ s.categorie }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- % de la base -->
                            <div class="ed-field-inline ed-pct-field" style="flex:0 0 160px">
                                <div class="ed-pct-wrapper">
                                    <InputNumber
                                        v-model="additifs[idx].pct_base"
                                        :min="0"
                                        :max="100"
                                        :minFractionDigits="1"
                                        :maxFractionDigits="3"
                                        inputClass="w-full"
                                        class="w-full"
                                    />
                                    <span class="ed-pct-label">% de la base</span>
                                </div>
                            </div>

                            <Button icon="pi pi-trash" text rounded severity="danger" @click="supprimerAdditif(idx)" style="flex-shrink:0" />
                        </div>
                    </div>

                    <Button
                        label="Ajouter un ingrédient"
                        icon="pi pi-plus"
                        text
                        size="small"
                        @click="ajouterAdditif"
                        class="mt-2"
                    />

                    <!-- Calculette additifs -->
                    <div v-if="calcAdditifs.length" class="ed-calc-box">
                        <div class="ed-calc-title">Pour <strong>1 kg</strong> de base →</div>
                        <div class="ed-calc-rows">
                            <span v-for="c in calcAdditifs" :key="c.libelle" class="ed-calc-chip">
                                {{ c.libelle }} : {{ c.affichage }}
                            </span>
                        </div>
                    </div>
                </div>

            </div><!-- fin col-left -->

            <!-- ══════════════════════════════════════════════════
                 COLONNE DROITE — Étapes
            ══════════════════════════════════════════════════ -->
            <div class="ed-col-right">
                <div class="ed-section ed-section-etapes">
                    <div class="ed-section-title">
                        Étapes de préparation
                        <span class="ed-section-hint">Glisser-déposer pour réordonner</span>
                    </div>

                    <div v-if="etapes.length === 0" class="cruf-empty" style="margin-bottom:0.5rem">
                        Aucune étape définie.
                    </div>

                    <div class="ed-etapes-list">
                        <div
                            v-for="(etape, idx) in etapes"
                            :key="etape._key"
                            class="ed-etape-row"
                            :class="{ 'is-dragging': dragEtapeIndex === idx }"
                            draggable="true"
                            @dragstart="onDragStartEtape(idx)"
                            @dragover="onDragOver"
                            @drop="onDropEtape(idx)"
                            @dragend="onDragEndEtape"
                        >
                            <!-- Numéro + poignée -->
                            <div class="ed-etape-num" title="Réordonner">
                                <i class="pi pi-bars ed-etape-drag-icon" />
                                <span>{{ idx + 1 }}</span>
                            </div>

                            <!-- Textarea -->
                            <Textarea
                                v-model="etapes[idx].contenu"
                                class="w-full etape-textarea"
                                :autoResize="true"
                                rows="2"
                                placeholder="Décrivez cette étape…"
                            />

                            <!-- Supprimer -->
                            <Button
                                icon="pi pi-trash"
                                text rounded
                                severity="danger"
                                size="small"
                                @click="supprimerEtape(idx)"
                            />
                        </div>
                    </div>

                    <Button
                        label="Ajouter une étape"
                        icon="pi pi-plus"
                        text
                        size="small"
                        @click="ajouterEtape"
                        class="mt-2"
                    />
                </div>
            </div>

        </div><!-- fin ed-layout -->
    </PageCard>
</div>
</template>

<style scoped>
/* ── Layout 2 colonnes ───────────────────────────────────────── */
.ed-layout {
    display: grid;
    grid-template-columns: 1fr 420px;
    gap: 1.5rem;
    align-items: start;
}

.ed-col-left,
.ed-col-right {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

/* ── En-tête titre avec bouton retour ────────────────────────── */
.ed-header-titre {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1.125rem;
    font-weight: 700;
}

/* ── Sections ────────────────────────────────────────────────── */
.ed-section {
    background: var(--surface-card);
    border: 1px solid var(--surface-border);
    border-radius: 10px;
    padding: 1rem 1.125rem;
}

.ed-section-etapes {
    position: sticky;
    top: 1rem;
}

.ed-section-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #aaa;
    margin-bottom: 0.875rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.ed-section-hint {
    font-weight: 400;
    text-transform: none;
    letter-spacing: 0;
    font-style: italic;
    color: #ccc;
    font-size: 11px;
}

/* ── Champs formulaire ───────────────────────────────────────── */
.ed-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-bottom: 0.75rem;
}

.ed-field:last-child { margin-bottom: 0; }

.ed-field label {
    font-size: 13px;
    font-weight: 600;
    color: #444;
}

/* ── Fruits — ligne ──────────────────────────────────────────── */
.ed-fruits-list,
.ed-additifs-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
}

.ed-fruit-row,
.ed-additif-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.625rem;
    border: 1px solid var(--surface-border);
    border-radius: 8px;
    background: var(--surface-50, #fafafa);
    transition: border-color 0.15s;
}

.ed-fruit-row.is-pivot {
    border-color: var(--primary-300, #fbbf24);
    background: #fffbeb;
}

.ed-fruit-row.is-dragging,
.ed-etape-row.is-dragging {
    opacity: 0.5;
}

.ed-drag-handle {
    cursor: grab;
    color: #ccc;
    flex-shrink: 0;
    padding: 0 2px;
    font-size: 14px;
}
.ed-drag-handle:hover { color: #999; }

.ed-pivot-indicator {
    flex-shrink: 0;
    width: 18px;
    text-align: center;
}

.ed-fruit-fields {
    flex: 1;
    min-width: 0;
}

.ed-fruit-row1 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.ed-field-inline {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

/* ── % champ ─────────────────────────────────────────────────── */
.ed-pct-wrapper {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.ed-pct-label {
    font-size: 10px;
    color: #999;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ── Produit peyrounet ───────────────────────────────────────── */
.ed-produit-field {
    position: relative;
}

.ed-produit-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #e8f5e9;
    border: 1px solid #a5d6a7;
    border-radius: 5px;
    padding: 3px 6px;
    font-size: 12px;
    color: #2e7d32;
    white-space: nowrap;
    overflow: hidden;
    max-width: 100%;
}

.ed-produit-search {
    position: relative;
}

.ed-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 100;
    background: var(--surface-overlay, #fff);
    border: 1px solid var(--surface-border);
    border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    max-height: 200px;
    overflow-y: auto;
}

.ed-suggestion-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 10px;
    cursor: pointer;
    font-size: 13px;
    gap: 0.5rem;
}

.ed-suggestion-item:hover {
    background: var(--surface-hover, #f5f5f5);
}

.ed-sugg-libelle {
    color: var(--text-color);
}

.ed-sugg-cat {
    font-size: 11px;
    color: #aaa;
    white-space: nowrap;
}

/* ── Calculette ──────────────────────────────────────────────── */
.ed-calc-box {
    margin-top: 0.75rem;
    background: var(--surface-100, #f5f5f5);
    border: 1px dashed var(--surface-border);
    border-radius: 6px;
    padding: 0.5rem 0.75rem;
    font-size: 12px;
}

.ed-calc-title {
    color: #666;
    margin-bottom: 4px;
}

.ed-calc-rows {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
    margin-bottom: 4px;
}

.ed-calc-chip {
    background: #fff;
    border: 1px solid var(--surface-border);
    border-radius: 4px;
    padding: 1px 7px;
    color: var(--text-color-secondary);
}

.ed-calc-total {
    color: #444;
    font-size: 12px;
}

/* ── Étapes ──────────────────────────────────────────────────── */
.ed-etapes-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.ed-etape-row {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.375rem 0;
}

.ed-etape-num {
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    width: 24px;
    padding-top: 6px;
    cursor: grab;
}

.ed-etape-drag-icon {
    font-size: 11px;
    color: #ccc;
}

.ed-etape-drag-icon:hover { color: #999; }

.ed-etape-num span {
    font-size: 12px;
    font-weight: 700;
    color: #bbb;
    line-height: 1;
}

/* ── Utilitaires ─────────────────────────────────────────────── */
.cruf-required {
    color: #e53935;
    margin-left: 2px;
}

.cruf-hint {
    font-size: 12px;
    color: #999;
    font-style: italic;
}

.cruf-empty {
    color: #aaa;
    font-size: 13px;
    font-style: italic;
    margin: 0;
}
</style>