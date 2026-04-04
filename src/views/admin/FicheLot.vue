<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';

const route  = useRoute();
const router = useRouter();
const toast  = useToast();

// ── Données ───────────────────────────────────────────────────
const lot      = ref(null);
const saveurs  = ref([]);
const recettes = ref([]);
const loading  = ref(true);
const saving   = ref(false);

const lotId = computed(() => Number(route.params.id));

// ── Calcul Krencker (même logique que SimulateurFormulation) ──
const round = (val, dec) => Math.round(val * Math.pow(10, dec)) / Math.pow(10, dec);

const calc = computed(() => {
    if (!lot.value) return null;
    const base         = totauxTempsReel.value.base;
    const brix_fruit   = parseFloat(lot.value.brix_fruit);
    const brix_cible   = parseFloat(lot.value.brix_cible);
    const pa_cible     = parseFloat(lot.value.pa_cible);
    const pct_fructose = parseFloat(lot.value.pct_fructose);

    if (!base || base <= 0) return null;
    if (lot.value.brix_fruit === null || lot.value.brix_fruit === undefined || lot.value.brix_fruit === '') return null;
    if (!brix_cible || brix_cible <= 0) return null;
    if (!pa_cible || pa_cible <= 0) return null;
    if (!pct_fructose) return null;
    if (brix_fruit >= brix_cible) return null;

    const cible_kg        = base * 100 / pa_cible;
    const total_sucre_kg  = cible_kg * brix_cible / 100;
    const sucre_fruit_kg  = brix_fruit * base / 100;
    const sa_kg           = total_sucre_kg - sucre_fruit_kg;
    const fructose_kg     = sa_kg * (pct_fructose / 100);
    const saccharose_kg   = sa_kg * (1 - pct_fructose / 100);
    const masse_totale_kg = base + sa_kg;
    const evaporation_kg  = masse_totale_kg - cible_kg;
    const pulpe           = parseFloat(lot.value.poids_pulpe_kg);
    const pa_etiquette    = (pulpe > 0) ? pulpe * 100 / cible_kg : base * 100 / cible_kg;

    return {
        cible_kg:        round(cible_kg, 3),
        sucre_fruit_kg:  round(sucre_fruit_kg, 4),
        sa_kg:           round(sa_kg, 4),
        fructose_kg:     round(fructose_kg, 4),
        saccharose_kg:   round(saccharose_kg, 4),
        masse_totale_kg: round(masse_totale_kg, 4),
        evaporation_kg:  round(evaporation_kg, 3),
        pa_etiquette:    round(pa_etiquette, 1),
    };
});

// ── Statut helpers ────────────────────────────────────────────
const estModifiable = computed(() =>
    lot.value && ['preparation', 'en_repos'].includes(lot.value.statut)
);
const estEnProduction = computed(() => lot.value?.statut === 'production');
const estEnStock      = computed(() => lot.value?.statut === 'stock');
const estAbandonne    = computed(() => lot.value?.statut === 'abandonné');

const statutConfig = {
    preparation: { label: 'Préparation', severity: 'secondary' },
    en_repos:    { label: 'En repos',    severity: 'info'      },
    production:  { label: 'Production',  severity: 'warning'   },
    stock:       { label: 'Stock',       severity: 'success'   },
    'abandonné': { label: 'Abandonné',   severity: 'danger'    },
};

// ── Blocs progressifs — déverrouillage ───────────────────────
const pivot = computed(() =>
    lot.value?.fruits?.find(f => f.type === 'pivot') ?? null
);
const fruits = computed(() =>
    lot.value?.fruits?.filter(f => f.type === 'fruit') ?? []
);
const additifs = computed(() =>
    lot.value?.fruits?.filter(f => f.type === 'additif') ?? []
);

// Quand poids_base_kg du pivot change, recalculer poids_pulpe_kg des fruits non-pivot
// (pré-rempli = poids_base calculé, modifiable si jus retiré)
watch(() => pivot.value?.poids_base_kg, (newBase) => {
    if (!newBase || !lot.value?.fruits) return;
    lot.value.fruits.forEach(f => {
        if (f.type === 'fruit' && f.pct_base) {
            const calcule = Math.round(newBase * f.pct_base / 100 * 1000) / 1000;
            f.poids_base_kg  = calcule;
            f.poids_pulpe_kg = calcule; // pré-rempli, modifiable
        }
    });
});

// Base totale fruits (pivot + non-pivot) — sert au calcul des additifs
const baseTotaleFruits = computed(() => {
    if (!lot.value?.fruits) return 0;
    return lot.value.fruits
        .filter(f => f.type !== 'additif')
        .reduce((s, f) => s + (parseFloat(f.poids_base_kg) || 0), 0);
});

// Totaux temps réel pour le bloc 4 (pivot + fruits uniquement, sans additifs)
const totauxTempsReel = computed(() => {
    if (!lot.value?.fruits) return { brut: 0, pulpe: 0, base: 0 };
    const nonAdditifs = lot.value.fruits.filter(f => f.type !== 'additif');
    return {
        brut:  Math.round(nonAdditifs.reduce((s, f) => s + (parseFloat(f.poids_brut_kg)  || 0), 0) * 1000) / 1000,
        pulpe: Math.round(nonAdditifs.reduce((s, f) => s + (parseFloat(f.poids_pulpe_kg) || 0), 0) * 1000) / 1000,
        base:  Math.round(nonAdditifs.reduce((s, f) => s + (parseFloat(f.poids_base_kg)  || 0), 0) * 1000) / 1000,
        brutComplet:  nonAdditifs.every(f => parseFloat(f.poids_brut_kg)  > 0),
        pulpeComplet: nonAdditifs.every(f => parseFloat(f.poids_pulpe_kg) > 0),
    };
});

// Conditions de grisage bloc 3
// Fruits non-pivot : grisés tant que pivot.poids_base_kg non renseigné
const pivotBaseRenseigne = computed(() =>
    pivot.value !== null && parseFloat(pivot.value.poids_base_kg) > 0
);
// Additifs : grisés tant que tous les poids_base_kg des fruits non-pivot ne sont pas connus
const fruitsBaseComplets = computed(() => {
    if (!lot.value?.fruits) return false;
    const fs = lot.value.fruits.filter(f => f.type === 'fruit');
    if (fs.length === 0) return pivotBaseRenseigne.value;
    return pivotBaseRenseigne.value && fs.every(f => parseFloat(f.poids_base_kg) > 0);
});
const erreursSaisie = computed(() => {
    if (!lot.value?.fruits) return [];
    const errs = [];
    lot.value.fruits.forEach(f => {
        if (f.type === 'additif') return;
        const nom = f.libelle_canonique ?? f.type;
        if (f.poids_brut_kg > 0 && f.poids_pulpe_kg > 0 && f.poids_brut_kg < f.poids_pulpe_kg)
            errs.push(nom + ' : brut inférieur à la pulpe');
        if (f.type === 'pivot' && f.poids_base_kg > 0 && f.poids_pulpe_kg > 0 && f.poids_base_kg > f.poids_pulpe_kg)
            errs.push(nom + ' : base supérieure à la pulpe');
        if (f.type === 'fruit' && f.poids_pulpe_kg > 0 && f.poids_pulpe_kg < f.poids_base_kg)
            errs.push(nom + ' : pulpe inférieure à la base');
    });
    return errs;
});

// Quand la base totale fruits change, recalculer les additifs
watch(baseTotaleFruits, (newBase) => {
    if (!newBase || !lot.value?.fruits) return;
    lot.value.fruits.forEach(f => {
        if (f.type === 'additif' && f.pct_base) {
            f.poids_base_kg = Math.round(newBase * f.pct_base / 100 * 1000) / 1000;
        }
    });
});

// Bloc 1 complet : saveur + recette + date
const bloc1Complet = computed(() =>
    lot.value?.saveur_id && lot.value?.recette_id && lot.value?.date_production
);
// Bloc 2 complet : poids_base_kg du pivot renseigné
const bloc2Complet = computed(() =>
    pivot.value?.poids_base_kg > 0
);
// Bloc 3 complet : tous les poids_brut_kg des fruits non-pivot saisis
const bloc3Complet = computed(() => {
    if (fruits.value.length === 0) return bloc2Complet.value;
    return fruits.value.every(f => f.poids_brut_kg > 0);
});
// Bloc 4 complet : brix_fruit renseigné
const bloc4Complet = computed(() =>
    lot.value?.brix_fruit > 0
);

// ── Chargement ────────────────────────────────────────────────
const charger = async () => {
    loading.value = true;
    try {
        const res = await axiosCrufiture.get('/lots/' + lotId.value);
        lot.value = res.data.details;
        // Charger les recettes de la saveur si modifiable
        if (estModifiable.value && lot.value.saveur_id) {
            await chargerRecettes(lot.value.saveur_id);
        }
        // Si le lot n'a pas encore de fruits, initialiser depuis la recette
        if (estModifiable.value && lot.value.recette_id && (!lot.value.fruits || lot.value.fruits.length === 0)) {
            await initialiserFruitsDepuisRecette(lot.value.recette_id);
        }
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger le lot.', life: 4000 });
    } finally {
        loading.value = false;
    }
};

const chargerSaveurs = async () => {
    try {
        const res = await axiosCrufiture.get('/saveurs');
        saveurs.value = res.data.details || [];
    } catch {}
};

const chargerRecettes = async (saveur_id) => {
    if (!saveur_id) { recettes.value = []; return; }
    try {
        const res = await axiosCrufiture.get('/recettes');
        recettes.value = (res.data.details || [])
            .filter(r => r.saveur_id === saveur_id && r.actif);
    } catch {}
};

// ── Initialisation des fruits depuis la recette ───────────────
// Appelé quand le lot n'a pas encore de fruits (création bloc 1).
// Charge la recette complète et initialise lot.fruits avec les
// ingrédients de la recette, prêts à être saisis.
const initialiserFruitsDepuisRecette = async (recette_id) => {
    if (!recette_id) return;
    try {
        const res = await axiosCrufiture.get('/recettes/' + recette_id);
        const recette = res.data.details;
        if (!recette?.ingredients?.length) return;

        lot.value.fruits = recette.ingredients.map((ing, idx) => ({
            produit_id:      ing.produit_id,
            libelle_canonique: ing.libelle_canonique,
            categorie:       ing.categorie,
            type:            ing.type,
            pct_base:        ing.pct_base,
            poids_brut_kg:   null,
            poids_pulpe_kg:  null,
            poids_base_kg:   null,
            fournisseur:     '',
            origine:         '',
            note:            '',
            ordre:           idx,
        }));
    } catch {}
};

onMounted(() => {
    chargerSaveurs();
    charger();
});

// ── Sauvegarde de la fiche ────────────────────────────────────
const sauvegarder = async () => {
    if (erreursSaisie.value.length > 0) {
        toast.add({ severity: 'error', summary: 'Erreurs de saisie', detail: erreursSaisie.value.join(' | '), life: 5000 });
        return;
    }
    saving.value = true;
    try {

        const payload = {
            saveur_id:       lot.value.saveur_id,
            recette_id:      lot.value.recette_id,
            date_production: lot.value.date_production,
            installation:    lot.value.installation,
            brix_fruit:      lot.value.brix_fruit,
            brix_cible:      lot.value.brix_cible,
            pct_fructose:    lot.value.pct_fructose,
            pa_cible:        lot.value.pa_cible,
            note_production: lot.value.note_production,
            poids_brut_kg:   totauxTempsReel.value.brut,
            poids_pulpe_kg:  totauxTempsReel.value.pulpe,
            poids_base_kg:   totauxTempsReel.value.base,
            fruits:          lot.value.fruits,
        };

        await axiosCrufiture.put('/lots/' + lotId.value, payload);
        toast.add({ severity: 'success', summary: 'Enregistré', detail: 'Fiche lot mise à jour.', life: 3000 });
        await charger();
    } catch (err) {
        const detail = err.response?.data?.message ?? 'Erreur lors de la sauvegarde.';
        toast.add({ severity: 'error', summary: 'Erreur', detail, life: 4000 });
    } finally {
        saving.value = false;
    }
};

// ── Transition mettre en repos ────────────────────────────────
const mettreEnRepos = async () => {
    // Sauvegarder d'abord
    await sauvegarder();
    saving.value = true;
    try {
        await axiosCrufiture.put('/lots/' + lotId.value + '/mettre-en-repos');
        toast.add({ severity: 'success', summary: 'En repos', detail: 'Lot mis en chambre froide.', life: 3000 });
        await charger();
    } catch (err) {
        const detail = err.response?.data?.message ?? 'Impossible de mettre le lot en repos.';
        toast.add({ severity: 'error', summary: 'Erreur', detail, life: 4000 });
    } finally {
        saving.value = false;
    }
};

// ── Transition démarrer production ───────────────────────────
const demarrerDialogVisible = ref(false);
const demarrer = async () => {
    saving.value = true;
    try {
        await axiosCrufiture.put('/lots/' + lotId.value + '/demarrer');
        toast.add({ severity: 'success', summary: 'Démarré', detail: 'Production démarrée — fiche verrouillée.', life: 3000 });
        demarrerDialogVisible.value = false;
        await charger();
    } catch (err) {
        const detail = err.response?.data?.message ?? 'Impossible de démarrer la production.';
        toast.add({ severity: 'error', summary: 'Erreur', detail, life: 4000 });
    } finally {
        saving.value = false;
    }
};

// ── Relevé de pesée ───────────────────────────────────────────
const releveForm = reactive({
    heure:      '',
    poids_brut: null, // poids lu sur la balance (brut)
    tare:       null, // tare plaque (non stockée)
    meteo:      '',
    remarque:   '',
});
const savingReleve = ref(false);

const poidsNet = computed(() => {
    const b = parseFloat(releveForm.poids_brut);
    const t = parseFloat(releveForm.tare) || 0;
    if (!b) return null;
    return round(b - t, 3);
});

const resteEvap = computed(() => {
    if (poidsNet.value === null || !lot.value?.cible_kg) return null;
    return round(poidsNet.value - lot.value.cible_kg, 3);
});

const cibleAtteinte = computed(() =>
    poidsNet.value !== null && lot.value?.cible_kg && poidsNet.value <= lot.value.cible_kg
);

const ajouterReleve = async () => {
    if (poidsNet.value === null) {
        toast.add({ severity: 'warn', summary: 'Manquant', detail: 'Renseignez le poids brut.', life: 3000 });
        return;
    }
    savingReleve.value = true;
    try {
        await axiosCrufiture.post('/lots/' + lotId.value + '/releves', {
            heure:       releveForm.heure || new Date().toTimeString().slice(0, 8),
            poids_brut_kg: poidsNet.value, // on envoie le poids net
            meteo:       releveForm.meteo || null,
            remarque:    releveForm.remarque || null,
        });
        toast.add({ severity: 'success', summary: 'Relevé ajouté', detail: 'Poids net : ' + poidsNet.value + ' kg', life: 3000 });
        releveForm.poids_brut = null;
        releveForm.tare       = null;
        releveForm.meteo      = '';
        releveForm.remarque   = '';
        releveForm.heure      = '';
        await charger();
    } catch (err) {
        const detail = err.response?.data?.message ?? 'Erreur lors de l\'enregistrement du relevé.';
        toast.add({ severity: 'error', summary: 'Erreur', detail, life: 4000 });
    } finally {
        savingReleve.value = false;
    }
};

// ── Progression évaporation ───────────────────────────────────
const progression = computed(() => {
    if (!lot.value || !lot.value.releves?.length || !lot.value.masse_totale_kg || !lot.value.evaporation_kg) return null;
    const dernierReleve = lot.value.releves[lot.value.releves.length - 1];
    const evaporee = lot.value.masse_totale_kg - dernierReleve.poids_brut_kg;
    const pct = round(evaporee / lot.value.evaporation_kg * 100, 1);
    return Math.min(100, Math.max(0, pct));
});

// ── Abandon ───────────────────────────────────────────────────
const abandonVisible = ref(false);
const noteAbandon    = ref('');
const savingAbandon  = ref(false);

const abandonner = async () => {
    if (!noteAbandon.value.trim()) {
        toast.add({ severity: 'warn', summary: 'Note requise', detail: 'La note est obligatoire.', life: 3000 });
        return;
    }
    savingAbandon.value = true;
    try {
        await axiosCrufiture.put('/lots/' + lotId.value + '/abandonner', { note: noteAbandon.value });
        toast.add({ severity: 'info', summary: 'Lot abandonné', detail: 'Le lot a été abandonné.', life: 3000 });
        abandonVisible.value = false;
        await charger();
    } catch (err) {
        const detail = err.response?.data?.message ?? 'Erreur.';
        toast.add({ severity: 'error', summary: 'Erreur', detail, life: 4000 });
    } finally {
        savingAbandon.value = false;
    }
};

// ── Passage en stock ──────────────────────────────────────────
const stockDialogVisible = ref(false);
const stockForm = reactive({
    poids_reel_kg: null,
    jarres: [{ poids_initial_kg: null, note: '' }],
    controle: {
        date_controle:  new Date().toISOString().slice(0, 10),
        type_controle:  'mise_en_pot',
        brix_mesure:    null,
        aw_mesure:      null,
        ph_mesure:      null,
        aspect:         '',
        remarque:       '',
    },
});
const savingStock = ref(false);

const addJarre = () => {
    if (stockForm.jarres.length < 3) {
        stockForm.jarres.push({ poids_initial_kg: null, note: '' });
    }
};
const removeJarre = (i) => {
    if (stockForm.jarres.length > 1) stockForm.jarres.splice(i, 1);
};

const passerEnStock = async () => {
    if (!stockForm.poids_reel_kg) {
        toast.add({ severity: 'warn', summary: 'Manquant', detail: 'Le poids réel est obligatoire.', life: 3000 });
        return;
    }
    savingStock.value = true;
    try {
        await axiosCrufiture.put('/lots/' + lotId.value + '/stocker', {
            poids_reel_kg: stockForm.poids_reel_kg,
            jarres:        stockForm.jarres,
            controle:      stockForm.controle,
        });
        toast.add({ severity: 'success', summary: 'En stock', detail: 'Lot passé en stock avec succès.', life: 3000 });
        stockDialogVisible.value = false;
        await charger();
    } catch (err) {
        const detail = err.response?.data?.message ?? 'Erreur lors du passage en stock.';
        toast.add({ severity: 'error', summary: 'Erreur', detail, life: 4000 });
    } finally {
        savingStock.value = false;
    }
};

// ── Ajout contrôle qualité (en stock) ────────────────────────
const controleDialogVisible = ref(false);
const controleForm = reactive({
    date_controle: new Date().toISOString().slice(0, 10),
    type_controle: 'suivi',
    brix_mesure:   null,
    aw_mesure:     null,
    ph_mesure:     null,
    aspect:        '',
    remarque:      '',
});
const savingControle = ref(false);

const ajouterControle = async () => {
    savingControle.value = true;
    try {
        await axiosCrufiture.post('/lots/' + lotId.value + '/controles', controleForm);
        toast.add({ severity: 'success', summary: 'Contrôle ajouté', detail: '', life: 3000 });
        controleDialogVisible.value = false;
        await charger();
    } catch (err) {
        const detail = err.response?.data?.message ?? 'Erreur.';
        toast.add({ severity: 'error', summary: 'Erreur', detail, life: 4000 });
    } finally {
        savingControle.value = false;
    }
};

// ── Helpers affichage ─────────────────────────────────────────
const formatPoids = (val, decimales = 3) => {
    if (val === null || val === undefined) return '—';
    return Number(val).toFixed(decimales) + ' kg';
};

const formatPoidsG = (val) => {
    if (val === null || val === undefined) return '—';
    return Math.round(val * 1000) + ' g';
};

const naviguerVersLots = () => router.push('/dashboard/lots');
</script>

<template>
<div class="col-12">

    <!-- Chargement -->
    <div v-if="loading" class="flex justify-content-center p-6">
        <ProgressSpinner />
    </div>

    <template v-else-if="lot">

    <PageCard :titre="'Lot ' + lot.numero_lot">
        <template #actions>
            <Tag
                :value="statutConfig[lot.statut]?.label ?? lot.statut"
                :severity="statutConfig[lot.statut]?.severity ?? 'secondary'"
                class="mr-2"
                style="font-size:13px"
            />
            <!-- Abandon — possible en preparation, en_repos, production -->
            <Button
                v-if="!estEnStock && !estAbandonne"
                label="Abandonner"
                icon="pi pi-times"
                severity="danger"
                text
                size="small"
                class="mr-2"
                @click="abandonVisible = true"
            />
            <Button
                icon="pi pi-arrow-left"
                label="Retour"
                text
                size="small"
                @click="naviguerVersLots"
            />
        </template>

        <!-- ══════════════════════════════════════════════════════
             STATUTS : preparation et en_repos — fiche éditable
             ══════════════════════════════════════════════════════ -->
        <template v-if="estModifiable">

            <!-- ── BLOC 1 : Identité ──────────────────────────── -->
            <div class="fiche-bloc" :class="{ 'fiche-bloc-locked': lot.statut === 'en_repos' }">
                <div class="fiche-bloc-titre">
                    <span class="fiche-bloc-num">1</span>
                    Identité du lot
                    <span v-if="lot.statut === 'en_repos'" class="fiche-bloc-hint">— verrouillé en repos</span>
                </div>
                <div class="fiche-grid-3">
                    <div class="fiche-field">
                        <label>Date de production</label>
                        <input
                            type="date"
                            v-model="lot.date_production"
                            class="p-inputtext p-component w-full"
                        />
                    </div>
                    <div class="fiche-field">
                        <label>Saveur <span class="fiche-required">*</span></label>
                        <Dropdown
                            v-model="lot.saveur_id"
                            :options="saveurs.map(s => ({ label: s.nom, value: s.id }))"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            @change="chargerRecettes(lot.saveur_id); lot.recette_id = null"
                        />
                    </div>
                    <div class="fiche-field">
                        <label>Recette <span class="fiche-required">*</span></label>
                        <Dropdown
                            v-model="lot.recette_id"
                            :options="recettes.map(r => ({ label: 'v' + r.version + ' — ' + r.titre, value: r.id }))"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :disabled="!lot.saveur_id"
                        />
                    </div>
                </div>
                <div class="fiche-field mt-2" style="max-width:300px">
                    <label>Installation</label>
                    <InputText v-model="lot.installation" placeholder="ex: Inox, Plastique" class="w-full" />
                </div>
            </div>

            <!-- ── BLOC 2 : Pivot ─────────────────────────────── -->
            <div class="fiche-bloc" :class="{ 'fiche-bloc-locked': lot.statut === 'en_repos' }">
                <div class="fiche-bloc-titre">
                    <span class="fiche-bloc-num">2</span>
                    Fruit pivot
                    <span v-if="lot.statut === 'en_repos'" class="fiche-bloc-hint">— verrouillé en repos</span>
                </div>
                <template v-if="pivot">
                    <div class="fiche-ingredient-nom">{{ pivot.libelle_canonique }}</div>
                    <div class="fiche-grid-4">
                        <div class="fiche-field">
                            <label>Poids brut <span class="fiche-unit">kg</span></label>
                            <InputNumber
                                v-model="pivot.poids_brut_kg"
                                :min="0" :maxFractionDigits="3"
                                inputClass="w-full"
                                placeholder="avant préparation"
                            />
                            <small v-if="pivot.poids_brut_kg > 0 && pivot.poids_pulpe_kg > 0 && pivot.poids_brut_kg < pivot.poids_pulpe_kg" class="fiche-hint-rouge">
                                Brut inférieur à la pulpe — vérifier la saisie
                            </small>
                        </div>
                        <div class="fiche-field">
                            <label>Pulpe obtenue <span class="fiche-unit">kg</span></label>
                            <InputNumber
                                v-model="pivot.poids_pulpe_kg"
                                :min="0" :maxFractionDigits="3"
                                inputClass="w-full"
                                placeholder="après préparation"
                                @update:modelValue="(v) => { if (!pivot.poids_base_kg) pivot.poids_base_kg = v; }"
                            />
                        </div>
                        <div class="fiche-field">
                            <label>Base utilisée <span class="fiche-unit">kg</span></label>
                            <InputNumber
                                v-model="pivot.poids_base_kg"
                                :min="0" :maxFractionDigits="3"
                                inputClass="w-full"
                                placeholder="≤ pulpe"
                            />
                            <small v-if="pivot.poids_base_kg > 0 && pivot.poids_pulpe_kg > 0 && pivot.poids_base_kg > pivot.poids_pulpe_kg" class="fiche-hint-rouge">
                                Base supérieure à la pulpe — vérifier la saisie
                            </small>
                            <small v-else-if="pivot.poids_pulpe_kg > pivot.poids_base_kg" class="fiche-hint-orange">
                                {{ Math.round((pivot.poids_pulpe_kg - pivot.poids_base_kg) * 1000) }} g de jus retiré
                            </small>
                        </div>
                        <div class="fiche-field">
                            <label>Fournisseur</label>
                            <InputText v-model="pivot.fournisseur" class="w-full" />
                        </div>
                    </div>
                    <div class="fiche-field mt-2" style="max-width:300px">
                        <label>Origine</label>
                        <InputText v-model="pivot.origine" class="w-full" />
                    </div>
                </template>
                <p v-else class="fiche-empty">Aucun fruit pivot défini dans la recette sélectionnée.</p>
            </div>

            <!-- ── BLOC 3 : Autres ingrédients ───────────────── -->
            <div class="fiche-bloc" :class="{ 'fiche-bloc-locked': lot.statut === 'en_repos' }">
                <div class="fiche-bloc-titre">
                    <span class="fiche-bloc-num">3</span>
                    Autres ingrédients
                    <span v-if="lot.statut === 'en_repos'" class="fiche-bloc-hint">— verrouillé en repos</span>
                </div>

                <!-- Fruits non-pivot -->
                <template v-if="fruits.length > 0">
                    <div
                        v-for="(fruit, idx) in fruits"
                        :key="fruit.id ?? idx"
                        class="fiche-ingredient-bloc"
                        :class="{ 'fiche-ingredient-grise': !pivotBaseRenseigne }"
                    >
                        <div class="fiche-ingredient-nom">
                            {{ fruit.libelle_canonique }}
                            <span class="fiche-ingredient-pct">{{ fruit.pct_base }} % du pivot</span>
                        </div>
                        <div class="fiche-grid-4">
                            <div class="fiche-field">
                                <label>Poids base <span class="fiche-unit">kg</span></label>
                                <div class="fiche-calculated">
                                    {{ pivot && pivot.poids_base_kg
                                        ? formatPoids(pivot.poids_base_kg * fruit.pct_base / 100)
                                        : '~ ' + fruit.pct_base + ' % du pivot' }}
                                </div>
                                <small class="fiche-hint">Calculé depuis le pivot</small>
                            </div>
                            <div class="fiche-field">
                                <label>Pulpe obtenue <span class="fiche-unit">kg</span></label>
                                <InputNumber
                                    v-model="lot.fruits[lot.fruits.indexOf(fruit)].poids_pulpe_kg"
                                    :min="0" :maxFractionDigits="3"
                                    inputClass="w-full"
                                    :disabled="!pivotBaseRenseigne"
                                />
                                <small v-if="fruit.poids_pulpe_kg > 0 && fruit.poids_pulpe_kg < fruit.poids_base_kg" class="fiche-hint-rouge">
                                    Pulpe inférieure à la base — vérifier la saisie
                                </small>
                                <small v-else-if="fruit.poids_pulpe_kg > 0 && fruit.poids_base_kg < fruit.poids_pulpe_kg" class="fiche-hint-orange">
                                    {{ Math.round((fruit.poids_pulpe_kg - fruit.poids_base_kg) * 1000) }} g de jus retiré
                                </small>
                            </div>
                            <div class="fiche-field">
                                <label>Poids brut <span class="fiche-unit">kg</span></label>
                                <InputNumber
                                    v-model="lot.fruits[lot.fruits.indexOf(fruit)].poids_brut_kg"
                                    :min="0" :maxFractionDigits="3"
                                    inputClass="w-full"
                                    :disabled="!pivotBaseRenseigne"
                                />
                                <small v-if="fruit.poids_brut_kg > 0 && fruit.poids_pulpe_kg > 0 && fruit.poids_brut_kg < fruit.poids_pulpe_kg" class="fiche-hint-rouge">
                                    Brut inférieur à la pulpe — vérifier la saisie
                                </small>
                            </div>
                            <div class="fiche-field">
                                <label>Fournisseur</label>
                                <InputText v-model="lot.fruits[lot.fruits.indexOf(fruit)].fournisseur" class="w-full" :disabled="!pivotBaseRenseigne" />
                            </div>
                        </div>
                    </div>
                </template>
                <p v-else class="fiche-empty text-sm">Aucun fruit secondaire dans cette recette.</p>

                <!-- Additifs -->
                <template v-if="additifs.length > 0">
                    <Divider />
                    <div class="fiche-bloc-sous-titre">Additifs</div>
                    <div
                        v-for="(additif, idx) in additifs"
                        :key="'additif-' + idx"
                        class="fiche-ingredient-bloc"
                        :class="{ 'fiche-ingredient-grise': !fruitsBaseComplets }"
                    >
                        <div class="fiche-ingredient-nom">
                            {{ additif.libelle_canonique }}
                            <span class="fiche-ingredient-pct">{{ additif.pct_base }} % de la base fruits</span>
                        </div>
                        <div class="fiche-field" style="max-width:180px">
                            <label>Poids à ajouter <span class="fiche-unit">kg</span></label>
                            <div class="fiche-calculated">
                                {{ additif.poids_base_kg
                                    ? formatPoids(additif.poids_base_kg)
                                    : '~ ' + additif.pct_base + ' % de la base fruits' }}
                            </div>
                            <small class="fiche-hint">Calculé depuis la base totale fruits</small>
                        </div>
                    </div>
                </template>
            </div>

            <!-- ── BLOC 4 : Krencker ──────────────────────────── -->
            <div class="fiche-bloc">
                <div class="fiche-bloc-titre">
                    <span class="fiche-bloc-num">4</span>
                    Formulation Krencker
                </div>

                <!-- Totaux en lecture seule -->
                <div class="fiche-totaux">
                    <div class="fiche-total-item">
                        <div class="fiche-total-val">{{ totauxTempsReel.brutComplet ? formatPoids(totauxTempsReel.brut) : '—' }}</div>
                        <div class="fiche-total-lbl">Brut total</div>
                    </div>
                    <div class="fiche-total-item">
                        <div class="fiche-total-val">{{ totauxTempsReel.pulpeComplet ? formatPoids(totauxTempsReel.pulpe) : '—' }}</div>
                        <div class="fiche-total-lbl">Pulpe totale</div>
                    </div>
                    <div class="fiche-total-item fiche-total-highlight">
                        <div class="fiche-total-val">{{ formatPoids(totauxTempsReel.base) }}</div>
                        <div class="fiche-total-lbl">Base (= base_kg Krencker)</div>
                    </div>
                </div>

                <!-- Paramètres à saisir -->
                <div class="fiche-grid-4 mt-3">
                    <div class="fiche-field">
                        <label>Brix fruit <span class="fiche-unit">°Bx mesuré</span></label>
                        <InputNumber
                            v-model="lot.brix_fruit"
                            :min="1" :max="30" :maxFractionDigits="1"
                            inputClass="w-full"
                            placeholder="ex: 12"
                        />
                    </div>
                    <div class="fiche-field">
                        <label>Brix cible <span class="fiche-unit">°Bx</span></label>
                        <InputNumber
                            v-model="lot.brix_cible"
                            :min="60" :max="80" :maxFractionDigits="1"
                            inputClass="w-full"
                        />
                    </div>
                    <div class="fiche-field">
                        <label>% Fructose</label>
                        <InputNumber
                            v-model="lot.pct_fructose"
                            :min="0" :max="100" :maxFractionDigits="1"
                            inputClass="w-full"
                        />
                    </div>
                    <div class="fiche-field">
                        <label>PA formulation <span class="fiche-unit">g/100g</span></label>
                        <InputNumber
                            v-model="lot.pa_cible"
                            :min="50" :max="90" :maxFractionDigits="1"
                            inputClass="w-full"
                        />
                    </div>
                </div>

                <!-- Résultats Krencker -->
                <template v-if="calc">
                    <div class="fiche-evap">
                        <div class="fiche-evap-label">Eau à évaporer</div>
                        <div class="fiche-evap-val">{{ calc.evaporation_kg }} kg</div>
                        <div class="fiche-evap-sub">
                            Pesée cible plateau : <strong>{{ calc.cible_kg }} kg</strong>
                        </div>
                    </div>
                    <div class="fiche-krencker-grid">
                        <div class="fiche-krencker-item">
                            <div class="fiche-krencker-val">{{ calc.fructose_kg }}</div>
                            <div class="fiche-krencker-lbl">Fructose kg</div>
                        </div>
                        <div class="fiche-krencker-item">
                            <div class="fiche-krencker-val">{{ calc.saccharose_kg }}</div>
                            <div class="fiche-krencker-lbl">Saccharose kg</div>
                        </div>
                        <div class="fiche-krencker-item">
                            <div class="fiche-krencker-val">{{ calc.sa_kg }}</div>
                            <div class="fiche-krencker-lbl">SA total kg</div>
                        </div>
                        <div class="fiche-krencker-item">
                            <div class="fiche-krencker-val">{{ calc.masse_totale_kg }}</div>
                            <div class="fiche-krencker-lbl">Masse plateau kg</div>
                        </div>
                        <div class="fiche-krencker-item">
                            <div class="fiche-krencker-val">{{ calc.pa_etiquette }}</div>
                            <div class="fiche-krencker-lbl">PA étiquette g/100g</div>
                        </div>
                    </div>
                </template>
                <Message v-else severity="info" class="mt-2">
                    Renseignez le Brix fruit pour obtenir les calculs Krencker.
                </Message>

                <!-- Note de production -->
                <div class="fiche-field mt-3">
                    <label>Note de production</label>
                    <Textarea v-model="lot.note_production" class="w-full" :autoResize="true" rows="2" />
                </div>
            </div>

            <!-- ── Actions de la fiche ────────────────────────── -->
            <div class="fiche-actions">
                <Button
                    label="Enregistrer"
                    icon="pi pi-save"
                    :loading="saving"
                    :disabled="erreursSaisie.length > 0"
                    @click="sauvegarder"
                />
                <Button
                    v-if="lot.statut === 'preparation'"
                    label="Mettre en repos"
                    icon="pi pi-moon"
                    severity="secondary"
                    :disabled="!calc || saving"
                    :loading="saving"
                    class="ml-2"
                    @click="mettreEnRepos"
                />
                <Button
                    v-if="lot.statut === 'en_repos'"
                    label="Démarrer la production"
                    icon="pi pi-play"
                    severity="warning"
                    :loading="saving"
                    class="ml-2"
                    @click="demarrerDialogVisible = true"
                />
            </div>

        </template>

        <!-- ══════════════════════════════════════════════════════
             STATUT : production — fiche verrouillée + pesées
             ══════════════════════════════════════════════════════ -->
        <template v-else-if="estEnProduction">

            <!-- Valeurs clés -->
            <div class="fiche-valeurs-cles">
                <div class="fiche-vc-item fiche-vc-principal">
                    <div class="fiche-vc-lbl">Pesée cible</div>
                    <div class="fiche-vc-val">{{ formatPoids(lot.cible_kg) }}</div>
                </div>
                <div class="fiche-vc-item">
                    <div class="fiche-vc-lbl">À évaporer</div>
                    <div class="fiche-vc-val">{{ formatPoids(lot.evaporation_kg) }}</div>
                </div>
                <div class="fiche-vc-item">
                    <div class="fiche-vc-lbl">Masse plateau initiale</div>
                    <div class="fiche-vc-val">{{ formatPoids(lot.masse_totale_kg) }}</div>
                </div>
                <div class="fiche-vc-item">
                    <div class="fiche-vc-lbl">Fructose</div>
                    <div class="fiche-vc-val">{{ formatPoidsG(lot.fructose_kg) }}</div>
                </div>
                <div class="fiche-vc-item">
                    <div class="fiche-vc-lbl">Saccharose</div>
                    <div class="fiche-vc-val">{{ formatPoidsG(lot.saccharose_kg) }}</div>
                </div>
                <div class="fiche-vc-item">
                    <div class="fiche-vc-lbl">Saveur</div>
                    <div class="fiche-vc-val">{{ lot.saveur_nom }}</div>
                </div>
            </div>

            <!-- Progression -->
            <div v-if="progression !== null" class="fiche-progression">
                <div class="fiche-prog-header">
                    <span>Évaporation</span>
                    <span>{{ progression }}%</span>
                </div>
                <ProgressBar :value="progression" style="height:12px" />
                <div v-if="lot.releves?.length" class="fiche-prog-sub">
                    Dernier relevé : {{ lot.releves[lot.releves.length - 1].poids_brut_kg }} kg
                    (reste {{ lot.releves[lot.releves.length - 1].reste_evap_kg }} kg)
                    à {{ lot.releves[lot.releves.length - 1].heure }}
                </div>
            </div>

            <!-- ── Formulaire relevé de pesée ─────────────────── -->
            <div class="fiche-bloc mt-3">
                <div class="fiche-bloc-titre">
                    <span class="fiche-bloc-num"><i class="pi pi-chart-line"></i></span>
                    Nouveau relevé de pesée
                </div>

                <div class="fiche-grid-4">
                    <div class="fiche-field">
                        <label>Heure</label>
                        <input type="time" v-model="releveForm.heure" class="p-inputtext p-component w-full" />
                    </div>
                    <div class="fiche-field">
                        <label>Poids brut plateau <span class="fiche-unit">kg</span></label>
                        <InputNumber v-model="releveForm.poids_brut" :min="0" :maxFractionDigits="3" inputClass="w-full" placeholder="lu sur la balance" />
                    </div>
                    <div class="fiche-field">
                        <label>Tare plaque <span class="fiche-unit">kg</span></label>
                        <InputNumber v-model="releveForm.tare" :min="0" :maxFractionDigits="3" inputClass="w-full" placeholder="poids plaque vide" />
                    </div>
                    <div class="fiche-field">
                        <label>Météo</label>
                        <InputText v-model="releveForm.meteo" class="w-full" placeholder="ex: Ensoleillé 28°C" />
                    </div>
                </div>

                <!-- Résultat immédiat -->
                <div v-if="poidsNet !== null" class="fiche-releve-resultat" :class="{ 'fiche-releve-ok': cibleAtteinte }">
                    <div>
                        <strong>Poids net : {{ poidsNet }} kg</strong>
                        <span class="ml-2 text-sm">
                            (reste à évaporer : {{ resteEvap }} kg)
                        </span>
                    </div>
                    <div v-if="cibleAtteinte" class="fiche-releve-cible">
                        ✓ Poids cible atteint ! Vous pouvez passer en stock.
                    </div>
                </div>

                <div class="flex gap-2 mt-2">
                    <Button
                        label="Enregistrer le relevé"
                        icon="pi pi-plus"
                        :loading="savingReleve"
                        :disabled="poidsNet === null"
                        @click="ajouterReleve"
                    />
                    <Button
                        v-if="cibleAtteinte"
                        label="Passer en stock"
                        icon="pi pi-inbox"
                        severity="success"
                        class="ml-2"
                        @click="stockDialogVisible = true"
                    />
                </div>
            </div>

            <!-- Historique des relevés -->
            <div v-if="lot.releves?.length" class="fiche-bloc mt-3">
                <div class="fiche-bloc-titre">
                    <span class="fiche-bloc-num"><i class="pi pi-history"></i></span>
                    Historique des relevés
                </div>
                <DataTable :value="lot.releves" size="small">
                    <Column field="heure" header="Heure" style="width:80px" />
                    <Column header="Poids net" style="width:120px">
                        <template #body="{ data }">
                            <span :class="{ 'text-green-600 font-bold': data.poids_brut_kg <= lot.cible_kg }">
                                {{ data.poids_brut_kg }} kg
                            </span>
                        </template>
                    </Column>
                    <Column field="reste_evap_kg" header="Reste évap." style="width:120px">
                        <template #body="{ data }">
                            {{ data.reste_evap_kg }} kg
                        </template>
                    </Column>
                    <Column field="meteo" header="Météo" />
                    <Column field="remarque" header="Remarque" />
                </DataTable>
            </div>

            <!-- Bouton passer en stock si cible déjà atteinte lors d'un relevé précédent -->
            <div class="fiche-actions mt-3">
                <Button
                    label="Passer en stock"
                    icon="pi pi-inbox"
                    severity="success"
                    @click="stockDialogVisible = true"
                />
            </div>

        </template>

        <!-- ══════════════════════════════════════════════════════
             STATUT : stock — résumé + jarres + contrôles
             ══════════════════════════════════════════════════════ -->
        <template v-else-if="estEnStock">

            <!-- Résumé de production -->
            <div class="fiche-valeurs-cles mb-3">
                <div class="fiche-vc-item fiche-vc-principal">
                    <div class="fiche-vc-lbl">Poids réel mis en pot</div>
                    <div class="fiche-vc-val">{{ formatPoids(lot.poids_reel_kg) }}</div>
                </div>
                <div class="fiche-vc-item">
                    <div class="fiche-vc-lbl">Poids cible initial</div>
                    <div class="fiche-vc-val">{{ formatPoids(lot.cible_kg) }}</div>
                </div>
                <div class="fiche-vc-item">
                    <div class="fiche-vc-lbl">Saveur</div>
                    <div class="fiche-vc-val">{{ lot.saveur_nom }}</div>
                </div>
                <div class="fiche-vc-item">
                    <div class="fiche-vc-lbl">Recette</div>
                    <div class="fiche-vc-val">{{ lot.recette_titre ?? '—' }}</div>
                </div>
            </div>

            <!-- Jarres -->
            <div class="fiche-bloc">
                <div class="fiche-bloc-titre">
                    <span class="fiche-bloc-num"><i class="pi pi-inbox"></i></span>
                    Jarres
                </div>
                <div class="flex gap-3 flex-wrap">
                    <div v-for="jarre in lot.jarres" :key="jarre.id" class="fiche-jarre-card">
                        <div class="fiche-jarre-num">Jarre {{ jarre.numero }}</div>
                        <div class="fiche-jarre-val">{{ formatPoids(jarre.poids_initial_kg) }}</div>
                        <div class="fiche-jarre-lbl">initial</div>
                    </div>
                </div>
            </div>

            <!-- Contrôles qualité -->
            <div class="fiche-bloc">
                <div class="fiche-bloc-titre flex justify-content-between align-items-center">
                    <span>
                        <span class="fiche-bloc-num"><i class="pi pi-check-circle"></i></span>
                        Contrôles qualité
                    </span>
                    <Button
                        label="Ajouter un contrôle"
                        icon="pi pi-plus"
                        size="small"
                        severity="secondary"
                        @click="controleDialogVisible = true"
                    />
                </div>
                <DataTable :value="lot.controles" size="small">
                    <Column field="date_controle" header="Date" style="width:110px" />
                    <Column field="type_controle" header="Type" style="width:120px" />
                    <Column field="brix_mesure" header="Brix" style="width:80px">
                        <template #body="{ data }">{{ data.brix_mesure ?? '—' }}</template>
                    </Column>
                    <Column field="aw_mesure" header="Aw" style="width:80px">
                        <template #body="{ data }">{{ data.aw_mesure ?? '—' }}</template>
                    </Column>
                    <Column field="ph_mesure" header="pH" style="width:80px">
                        <template #body="{ data }">{{ data.ph_mesure ?? '—' }}</template>
                    </Column>
                    <Column field="aspect" header="Aspect" />
                    <Column field="remarque" header="Remarque" />
                    <template #empty>
                        <div class="text-center text-color-secondary py-3">Aucun contrôle enregistré.</div>
                    </template>
                </DataTable>
            </div>

        </template>

        <!-- ══════════════════════════════════════════════════════
             STATUT : abandonné — lecture seule
             ══════════════════════════════════════════════════════ -->
        <template v-else-if="estAbandonne">
            <Message severity="error" :closable="false">
                Ce lot a été abandonné.
            </Message>
            <div v-if="lot.note_production" class="mt-3 p-3" style="background:var(--surface-50);border-radius:8px">
                <strong>Note :</strong> {{ lot.note_production }}
            </div>
        </template>

    </PageCard>

    <!-- ── Dialog confirmation démarrage production ────────── -->
    <Dialog
        v-model:visible="demarrerDialogVisible"
        header="Démarrer la production ?"
        :style="{ width: '420px' }"
        modal
    >
        <p>Une fois démarrée, la fiche sera <strong>verrouillée définitivement</strong>. Aucune modification des paramètres ne sera possible.</p>
        <p>Assurez-vous que tous les ingrédients sont pesés et prêts.</p>
        <template #footer>
            <Button label="Annuler" text @click="demarrerDialogVisible = false" />
            <Button
                label="Démarrer"
                icon="pi pi-play"
                severity="warning"
                :loading="saving"
                @click="demarrer"
            />
        </template>
    </Dialog>

    <!-- ── Dialog abandon ─────────────────────────────────── -->
    <Dialog
        v-model:visible="abandonVisible"
        header="Abandonner le lot ?"
        :style="{ width: '420px' }"
        modal
    >
        <p>Le lot sera marqué comme abandonné. Cette action est irréversible.</p>
        <div class="fiche-field mt-3">
            <label>Raison de l'abandon <span class="fiche-required">*</span></label>
            <Textarea v-model="noteAbandon" class="w-full" :autoResize="true" rows="3" placeholder="Expliquez pourquoi ce lot est abandonné…" />
        </div>
        <template #footer>
            <Button label="Annuler" text :disabled="savingAbandon" @click="abandonVisible = false" />
            <Button
                label="Abandonner"
                icon="pi pi-times"
                severity="danger"
                :loading="savingAbandon"
                @click="abandonner"
            />
        </template>
    </Dialog>

    <!-- ── Dialog passage en stock ────────────────────────── -->
    <Dialog
        v-model:visible="stockDialogVisible"
        header="Passer en stock"
        :style="{ width: '560px' }"
        modal
    >
        <div class="flex flex-column gap-3">
            <div class="fiche-field">
                <label>Poids réel mis en pot <span class="fiche-required">*</span></label>
                <InputNumber v-model="stockForm.poids_reel_kg" :min="0" :maxFractionDigits="3" inputClass="w-full" suffix=" kg" />
            </div>

            <div>
                <div class="flex justify-content-between align-items-center mb-2">
                    <label class="font-semibold">Jarres</label>
                    <Button
                        v-if="stockForm.jarres.length < 3"
                        label="+ Jarre"
                        text size="small"
                        @click="addJarre"
                    />
                </div>
                <div
                    v-for="(jarre, i) in stockForm.jarres"
                    :key="i"
                    class="flex gap-2 align-items-center mb-2"
                >
                    <span class="fiche-jarre-num-small">{{ i + 1 }}</span>
                    <InputNumber v-model="stockForm.jarres[i].poids_initial_kg" :min="0" :maxFractionDigits="3" inputClass="w-full" suffix=" kg" placeholder="Poids" />
                    <InputText v-model="stockForm.jarres[i].note" placeholder="Note" style="flex:1" />
                    <Button v-if="i > 0" icon="pi pi-times" text rounded size="small" severity="danger" @click="removeJarre(i)" />
                </div>
            </div>

            <Divider />

            <div class="fiche-bloc-sous-titre">Contrôle qualité (mise en pot)</div>
            <div class="fiche-grid-3">
                <div class="fiche-field">
                    <label>Brix mesuré</label>
                    <InputNumber v-model="stockForm.controle.brix_mesure" :maxFractionDigits="2" inputClass="w-full" />
                </div>
                <div class="fiche-field">
                    <label>Aw</label>
                    <InputNumber v-model="stockForm.controle.aw_mesure" :maxFractionDigits="4" inputClass="w-full" />
                </div>
                <div class="fiche-field">
                    <label>pH</label>
                    <InputNumber v-model="stockForm.controle.ph_mesure" :maxFractionDigits="2" inputClass="w-full" />
                </div>
            </div>
            <div class="fiche-field">
                <label>Aspect</label>
                <InputText v-model="stockForm.controle.aspect" class="w-full" />
            </div>
            <div class="fiche-field">
                <label>Remarque</label>
                <Textarea v-model="stockForm.controle.remarque" class="w-full" :autoResize="true" rows="2" />
            </div>
        </div>

        <template #footer>
            <Button label="Annuler" text :disabled="savingStock" @click="stockDialogVisible = false" />
            <Button
                label="Valider la mise en stock"
                icon="pi pi-check"
                severity="success"
                :loading="savingStock"
                @click="passerEnStock"
            />
        </template>
    </Dialog>

    <!-- ── Dialog ajout contrôle qualité (en stock) ───────── -->
    <Dialog
        v-model:visible="controleDialogVisible"
        header="Nouveau contrôle qualité"
        :style="{ width: '480px' }"
        modal
    >
        <div class="flex flex-column gap-3">
            <div class="fiche-grid-2">
                <div class="fiche-field">
                    <label>Date</label>
                    <input type="date" v-model="controleForm.date_controle" class="p-inputtext p-component w-full" />
                </div>
                <div class="fiche-field">
                    <label>Type</label>
                    <Dropdown
                        v-model="controleForm.type_controle"
                        :options="[{ label: 'Suivi', value: 'suivi' }, { label: 'Autre', value: 'autre' }]"
                        optionLabel="label"
                        optionValue="value"
                        class="w-full"
                    />
                </div>
            </div>
            <div class="fiche-grid-3">
                <div class="fiche-field">
                    <label>Brix</label>
                    <InputNumber v-model="controleForm.brix_mesure" :maxFractionDigits="2" inputClass="w-full" />
                </div>
                <div class="fiche-field">
                    <label>Aw</label>
                    <InputNumber v-model="controleForm.aw_mesure" :maxFractionDigits="4" inputClass="w-full" />
                </div>
                <div class="fiche-field">
                    <label>pH</label>
                    <InputNumber v-model="controleForm.ph_mesure" :maxFractionDigits="2" inputClass="w-full" />
                </div>
            </div>
            <div class="fiche-field">
                <label>Aspect</label>
                <InputText v-model="controleForm.aspect" class="w-full" />
            </div>
            <div class="fiche-field">
                <label>Remarque</label>
                <Textarea v-model="controleForm.remarque" class="w-full" :autoResize="true" rows="2" />
            </div>
        </div>
        <template #footer>
            <Button label="Annuler" text :disabled="savingControle" @click="controleDialogVisible = false" />
            <Button label="Enregistrer" icon="pi pi-check" :loading="savingControle" @click="ajouterControle" />
        </template>
    </Dialog>

    </template>
</div>
</template>

<style scoped>
/* ── Blocs progressifs ───────────────────────────────────── */
.fiche-bloc {
    background: var(--surface-50);
    border: 1px solid var(--surface-border);
    border-radius: 10px;
    padding: 1.25rem;
    margin-bottom: 1rem;
}

.fiche-bloc-locked {
    opacity: 0.45;
    pointer-events: none;
}

.fiche-bloc-titre {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #888;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.fiche-bloc-hint {
    font-weight: 400;
    color: #bbb;
    font-size: 11px;
    text-transform: none;
    letter-spacing: 0;
}

.fiche-bloc-sous-titre {
    font-size: 12px;
    font-weight: 600;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 0.75rem;
}

.fiche-bloc-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--primary-color);
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    flex-shrink: 0;
}

/* ── Grilles ─────────────────────────────────────────────── */
.fiche-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
.fiche-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }
.fiche-grid-4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; }

/* ── Champs ──────────────────────────────────────────────── */
.fiche-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.fiche-field label {
    font-size: 12px;
    font-weight: 600;
    color: #555;
}
.fiche-unit {
    font-weight: 400;
    color: #bbb;
    font-size: 10px;
    margin-left: 2px;
}
.fiche-required { color: #e53935; }
.fiche-hint { font-size: 11px; color: #aaa; font-style: italic; }
.fiche-hint-orange { font-size: 11px; color: #b45309; }
.fiche-hint-rouge { font-size: 11px; color: #dc2626; font-weight: 600; }
.fiche-empty { color: #bbb; font-size: 13px; font-style: italic; margin: 0; }
.fiche-calculated {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-color);
    padding: 6px 10px;
    background: var(--surface-100);
    border-radius: 6px;
    border: 1px solid var(--surface-200);
}

/* ── Ingrédients ─────────────────────────────────────────── */
.fiche-ingredient-bloc { margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px dashed var(--surface-200); }
.fiche-ingredient-bloc:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.fiche-ingredient-nom { font-weight: 600; font-size: 14px; margin-bottom: 0.5rem; }
.fiche-ingredient-pct { font-size: 12px; color: #aaa; font-weight: 400; margin-left: 8px; }
.fiche-ingredient-grise { opacity: 0.5; pointer-events: none; }

/* ── Totaux ──────────────────────────────────────────────── */
.fiche-totaux { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.5rem; }
.fiche-total-item {
    background: var(--surface-card);
    border: 1px solid var(--surface-border);
    border-radius: 8px;
    padding: 0.75rem 1rem;
    text-align: center;
    min-width: 130px;
}
.fiche-total-highlight { border-color: var(--primary-color); }
.fiche-total-val { font-size: 18px; font-weight: 800; color: var(--primary-color); }
.fiche-total-lbl { font-size: 11px; color: #aaa; text-transform: uppercase; letter-spacing: 0.3px; margin-top: 2px; }

/* ── Bloc évaporation Krencker ───────────────────────────── */
.fiche-evap {
    background: linear-gradient(135deg, #78350f, #b45309);
    color: #fff;
    border-radius: 12px;
    padding: 16px 20px;
    margin: 1rem 0;
}
.fiche-evap-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; opacity: 0.8; margin-bottom: 2px; }
.fiche-evap-val   { font-size: 38px; font-weight: 900; line-height: 1; margin-bottom: 4px; }
.fiche-evap-sub   { font-size: 13px; opacity: 0.85; }

/* ── Résultats Krencker ──────────────────────────────────── */
.fiche-krencker-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 8px;
    margin-top: 0.5rem;
}
.fiche-krencker-item {
    background: var(--surface-card);
    border: 1px solid var(--surface-200);
    border-radius: 8px;
    padding: 10px 6px;
    text-align: center;
}
.fiche-krencker-val { font-size: 16px; font-weight: 800; color: var(--primary-color); }
.fiche-krencker-lbl { font-size: 10px; color: #aaa; text-transform: uppercase; letter-spacing: 0.2px; margin-top: 3px; }

/* ── Actions de la fiche ─────────────────────────────────── */
.fiche-actions { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--surface-200); }

/* ── Valeurs clés (production/stock) ─────────────────────── */
.fiche-valeurs-cles { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
.fiche-vc-item {
    background: var(--surface-50);
    border: 1px solid var(--surface-border);
    border-radius: 8px;
    padding: 0.75rem 1rem;
    min-width: 140px;
}
.fiche-vc-principal { border-color: var(--primary-color); background: var(--surface-card); }
.fiche-vc-lbl { font-size: 11px; color: #aaa; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
.fiche-vc-val { font-size: 20px; font-weight: 800; color: var(--text-color); }
.fiche-vc-principal .fiche-vc-val { color: var(--primary-color); }

/* ── Progression ─────────────────────────────────────────── */
.fiche-progression { margin-bottom: 1rem; }
.fiche-prog-header { display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
.fiche-prog-sub { font-size: 12px; color: #888; margin-top: 4px; }

/* ── Relevé résultat immédiat ────────────────────────────── */
.fiche-releve-resultat {
    background: var(--surface-100);
    border-radius: 8px;
    padding: 10px 14px;
    margin-top: 0.5rem;
    font-size: 14px;
}
.fiche-releve-ok { background: #f0fdf4; border: 1px solid #86efac; }
.fiche-releve-cible { color: #15803d; font-weight: 600; font-size: 13px; margin-top: 4px; }

/* ── Jarres ──────────────────────────────────────────────── */
.fiche-jarre-card {
    background: var(--surface-50);
    border: 1px solid var(--surface-border);
    border-radius: 10px;
    padding: 1rem 1.25rem;
    text-align: center;
    min-width: 120px;
}
.fiche-jarre-num { font-size: 11px; font-weight: 600; color: #aaa; text-transform: uppercase; margin-bottom: 4px; }
.fiche-jarre-val { font-size: 22px; font-weight: 800; color: var(--primary-color); }
.fiche-jarre-lbl { font-size: 11px; color: #aaa; }
.fiche-jarre-num-small {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--surface-200);
    font-size: 12px;
    font-weight: 700;
    flex-shrink: 0;
}
</style>
