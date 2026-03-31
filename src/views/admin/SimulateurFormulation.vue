<script setup>
import { reactive, computed } from 'vue';

// ── Entrées utilisateur ───────────────────────────────────────────
const form = reactive({
    saveur:       '',
    poids_brut:   null,   // kg fruit brut avant nettoyage
    pulpe:        null,   // kg pulpe après préparation
    base:         null,   // kg base utilisée (≤ pulpe)
    brix_fruit:   null,   // mesuré au réfractomètre sur le mélange
    brix_cible:   70,     // objectif conservation/sucrosité (modifiable)
    pct_fructose: 50,     // % fructose dans le sucre ajouté (le "50/50")
    pa_cible:     68,     // g pulpe / 100g crufiture (mention légale étiquette)
});

// ── Calculs (ordre Krencker) ──────────────────────────────────────
// Formules extraites du fichier ODS de formulation et vérifiées
// sur le lot betterave 250099 (toutes les valeurs matchent à 4 décimales)
const calc = computed(() => {
    const base         = parseFloat(form.base);
    const brix_fruit   = parseFloat(form.brix_fruit);
    const brix_cible   = parseFloat(form.brix_cible);
    const pa_cible     = parseFloat(form.pa_cible);
    const pct_fructose = parseFloat(form.pct_fructose);

    if (!base || !brix_fruit || !brix_cible || !pa_cible || !pct_fructose) return null;
    if (base <= 0 || brix_cible <= 0 || pa_cible <= 0) return null;

    // 1. Crufiture cible (kg théoriques produits)
    const cible_kg        = base * 100 / pa_cible;
    // 2. Sucre total nécessaire dans la crufiture finale
    const total_sucre_kg  = cible_kg * brix_cible / 100;
    // 3. Sucre déjà apporté par le fruit
    const sucre_fruit_kg  = brix_fruit * base / 100;
    // 4. Sucre à ajouter (SA = total − fruit)
    const sa_kg           = total_sucre_kg - sucre_fruit_kg;
    // 5. Répartition fructose / saccharose selon le ratio choisi
    const fructose_kg     = sa_kg * (pct_fructose / 100);
    const saccharose_kg   = sa_kg * (1 - pct_fructose / 100);
    // 6. Masse totale à poser sur le plateau
    const masse_totale_kg = base + sa_kg;
    // 7. Évaporation = différence entre masse déposée et cible → VALEUR CLÉ
    const evaporation_kg  = masse_totale_kg - cible_kg;
    // 8. PA réel calculé sur la pulpe totale (pas sur la base)
    //    = pulpe_kg * 100 / cible_kg
    //    Peut être supérieur au PA cible si on a retiré du jus (base < pulpe)
    //    C'est cette valeur que la réglementation autorise sur l'étiquette
    const pulpe      = parseFloat(form.pulpe);
    const pa_reel    = (pulpe > 0) ? pulpe * 100 / cible_kg : base * 100 / cible_kg;

    // Rendements — calculés si fruit brut et pulpe sont renseignés
    const poids_brut = parseFloat(form.poids_brut);
    const rdt_brut_pulpe = (poids_brut > 0 && pulpe > 0) ? (pulpe / poids_brut * 100) : null;
    const rdt_pulpe_cruf = (pulpe > 0 && base > 0)       ? (base  / pulpe      * 100) : null;

    return {
        cible_kg:        round(cible_kg, 3),
        total_sucre_kg:  round(total_sucre_kg, 4),
        sucre_fruit_kg:  round(sucre_fruit_kg, 4),
        sa_kg:           round(sa_kg, 4),
        fructose_kg:     round(fructose_kg, 4),
        saccharose_kg:   round(saccharose_kg, 4),
        masse_totale_kg: round(masse_totale_kg, 4),
        evaporation_kg:  round(evaporation_kg, 3),
        pa_reel:         round(pa_reel, 1),
        rdt_brut_pulpe:  rdt_brut_pulpe !== null ? round(rdt_brut_pulpe, 1) : null,
        rdt_pulpe_cruf:  rdt_pulpe_cruf  !== null ? round(rdt_pulpe_cruf,  1) : null,
    };
});

// Tous les paramètres de calcul sont renseignés
const pret = computed(() =>
    form.base && form.brix_fruit && form.brix_cible && form.pa_cible && form.pct_fructose
);

// ── Alerte évaporation ────────────────────────────────────────────
// Si l'évaporation représente > 30% de la masse totale,
// la production sera longue — dépendante des conditions météo
const alerteEvap = computed(() => {
    if (!calc.value) return null;
    const pct = calc.value.evaporation_kg / calc.value.masse_totale_kg * 100;
    if (pct > 40) return { niveau: 'danger',  msg: `${round(pct,1)}% de la masse à évaporer — long par temps couvert` };
    if (pct > 30) return { niveau: 'warning', msg: `${round(pct,1)}% de la masse à évaporer — prévoir une bonne journée` };
    return null;
});

const round = (val, dec) => Math.round(val * Math.pow(10, dec)) / Math.pow(10, dec);

const reset = () => {
    form.saveur = ''; form.poids_brut = null; form.pulpe = null; form.base = null;
    form.brix_fruit = null; form.brix_cible = 70; form.pct_fructose = 50; form.pa_cible = 68;
};

// ── Exemple betterave — données réelles lot 250099 ────────────────
const chargerExemple = () => {
    form.saveur = 'Betterave'; form.poids_brut = 3; form.pulpe = 1.765; form.base = 1.765;
    form.brix_fruit = 12; form.brix_cible = 70; form.pct_fructose = 50; form.pa_cible = 68;
};
</script>

<template>
<div class="col-12">
<PageCard titre="Simulateur de formulation">
    <template #actions>
        <Button label="Exemple betterave" icon="pi pi-play"    severity="secondary" size="small" class="mr-2" @click="chargerExemple" />
        <Button label="Réinitialiser"     icon="pi pi-refresh" severity="secondary" size="small" outlined    @click="reset" />
    </template>

    <div class="sim-root">

        <!-- ── COLONNE GAUCHE : Entrées utilisateur ──────────── -->
        <div class="sim-left">

            <div class="sim-section-title"><i class="pi pi-pencil mr-2"></i>Paramètres du lot</div>

            <!-- Saveur — libre, optionnel, juste pour nommer la simulation -->
            <div class="sim-field">
                <label>Saveur <span class="sim-opt">(optionnel)</span></label>
                <InputText v-model="form.saveur" placeholder="ex: Betterave, Fraise-Rhubarbe..." class="w-full" />
            </div>

            <Divider align="left"><span class="sim-sep">Matière première</span></Divider>

            <!-- 3 mesures physiques réelles du lot -->
            <div class="sim-grid-3">
                <div class="sim-field">
                    <label>Fruit brut <span class="sim-unit">kg</span></label>
                    <InputNumber v-model="form.poids_brut" :min="0" :max="100" :maxFractionDigits="3"
                        placeholder="ex: 3" inputClass="sim-input" />
                </div>
                <div class="sim-field">
                    <label>Pulpe <span class="sim-unit">kg</span></label>
                    <InputNumber v-model="form.pulpe" :min="0" :max="100" :maxFractionDigits="3"
                        placeholder="ex: 1.8" inputClass="sim-input" />
                </div>
                <div class="sim-field">
                    <label>Base <span class="sim-unit">kg</span></label>
                    <InputNumber v-model="form.base" :min="0" :max="100" :maxFractionDigits="3"
                        placeholder="ex: 1.8" inputClass="sim-input" />
                </div>
            </div>

            <!-- Info si on a retiré du jus (pulpe > base) -->
            <div class="sim-info-base" v-if="form.pulpe && form.base && form.base < form.pulpe">
                <i class="pi pi-info-circle mr-1"></i>{{ round((form.pulpe - form.base) * 1000, 0) }} g de jus retiré pour densifier
            </div>

            <Divider align="left"><span class="sim-sep">Paramètres Brix</span></Divider>

            <!-- Brix mesuré au réfractomètre sur le mélange global -->
            <div class="sim-grid-2">
                <div class="sim-field">
                    <label>Brix fruit <span class="sim-unit">°Bx mesuré</span></label>
                    <InputNumber v-model="form.brix_fruit" :min="1" :max="30" :maxFractionDigits="1"
                        placeholder="ex: 12" inputClass="sim-input" />
                </div>
                <div class="sim-field">
                    <label>Brix cible <span class="sim-unit">°Bx objectif</span></label>
                    <InputNumber v-model="form.brix_cible" :min="60" :max="80" :maxFractionDigits="1"
                        inputClass="sim-input" />
                </div>
            </div>

            <Divider align="left"><span class="sim-sep">Paramètres sucre</span></Divider>

            <!-- Ratio fructose/saccharose et PA cible (mention légale) -->
            <div class="sim-grid-2">
                <div class="sim-field">
                    <label>% Fructose <span class="sim-unit">dans le sucre ajouté</span></label>
                    <div class="sim-slider-row">
                        <Slider v-model="form.pct_fructose" :min="0" :max="100" :step="5" class="sim-slider" />
                        <span class="sim-slider-val">{{ form.pct_fructose }}%</span>
                    </div>
                    <div class="sim-hint">{{ form.pct_fructose }}% fructose · {{ 100 - form.pct_fructose }}% saccharose</div>
                </div>
                <div class="sim-field">
                    <label>PA formulation <span class="sim-unit">g pulpe / 100g</span></label>
                    <InputNumber v-model="form.pa_cible" :min="50" :max="90" :maxFractionDigits="1"
                        inputClass="sim-input" />
                    <div class="sim-hint">Paramètre de calcul — pilote la quantité finale</div>
                </div>
            </div>

        </div>

        <!-- ── COLONNE DROITE : Résultats calculés ───────────── -->
        <div class="sim-right">

            <!-- État d'attente — paramètres manquants -->
            <div v-if="!pret" class="sim-waiting">
                <i class="pi pi-calculator" style="font-size:3rem;color:#d1a050;opacity:.35"></i>
                <p>Renseignez la base, le Brix fruit,<br>le Brix cible, le PA et le % fructose<br>pour calculer la formulation.</p>
            </div>

            <template v-else-if="calc">

                <div class="sim-section-title">
                    <i class="pi pi-calculator mr-2"></i>Résultats
                    <span v-if="form.saveur" class="sim-badge">{{ form.saveur }}</span>
                </div>

                <!-- Alerte si évaporation importante -->
                <Message v-if="alerteEvap" :severity="alerteEvap.niveau" class="mb-3">{{ alerteEvap.msg }}</Message>

                <!-- ── Bloc évaporation — valeur clé de production ── -->
                <div class="sim-evap">
                    <div class="sim-evap-label">Eau à évaporer</div>
                    <div class="sim-evap-val">{{ calc.evaporation_kg }} kg</div>
                    <div class="sim-evap-sub">{{ round(calc.evaporation_kg * 1000, 0) }} g · soit {{ round(calc.evaporation_kg / calc.masse_totale_kg * 100, 1) }}% de la masse totale</div>
                    <div class="sim-evap-hint">
                        Pesée cible plateau : <strong>{{ calc.cible_kg }} kg</strong>
                        = masse totale ({{ calc.masse_totale_kg }} kg) − évaporation
                    </div>
                </div>

                <!-- ── Tableau des quantités à peser ── -->
                <table class="sim-table">
                    <thead>
                        <tr><th>Ingrédient</th><th class="tr">kg</th><th class="tr">g</th></tr>
                    </thead>
                    <tbody>
                        <tr class="r-fruit">
                            <td><i class="pi pi-circle-fill dot" style="color:#e67e22"></i>Base (pulpe fruit)</td>
                            <td class="tr fb">{{ form.base }}</td>
                            <td class="tr sec">{{ round(form.base * 1000, 0) }}</td>
                        </tr>
                        <tr>
                            <td><i class="pi pi-circle-fill dot" style="color:#f1c40f"></i>Fructose</td>
                            <td class="tr fb">{{ calc.fructose_kg }}</td>
                            <td class="tr sec">{{ round(calc.fructose_kg * 1000, 0) }}</td>
                        </tr>
                        <tr>
                            <td><i class="pi pi-circle-fill dot" style="color:#f1c40f"></i>Saccharose</td>
                            <td class="tr fb">{{ calc.saccharose_kg }}</td>
                            <td class="tr sec">{{ round(calc.saccharose_kg * 1000, 0) }}</td>
                        </tr>
                        <tr class="r-total">
                            <td><strong>Sucre ajouté total (SA)</strong></td>
                            <td class="tr"><strong>{{ calc.sa_kg }}</strong></td>
                            <td class="tr sec">{{ round(calc.sa_kg * 1000, 0) }}</td>
                        </tr>
                        <tr class="r-masse">
                            <td><strong>Masse totale plateau</strong></td>
                            <td class="tr"><strong>{{ calc.masse_totale_kg }}</strong></td>
                            <td class="tr sec">{{ round(calc.masse_totale_kg * 1000, 0) }}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- ── KPIs de synthèse ── -->
                <div class="sim-kpis">
                    <div class="sim-kpi">
                        <div class="sim-kpi-val">{{ calc.cible_kg }}</div>
                        <div class="sim-kpi-lbl">kg théoriques</div>
                    </div>
                    <!-- PA calculé sur pulpe totale → valeur à mettre sur l'étiquette -->
                    <div class="sim-kpi">
                        <div class="sim-kpi-val">{{ calc.pa_reel }}</div>
                        <div class="sim-kpi-lbl">PA étiquette</div>
                    </div>
                    <!-- Rendements — disponibles si fruit brut et pulpe renseignés -->
                    <div class="sim-kpi" :class="{ 'sim-kpi-off': calc.rdt_brut_pulpe === null }">
                        <div class="sim-kpi-val">{{ calc.rdt_brut_pulpe !== null ? calc.rdt_brut_pulpe + '%' : '—' }}</div>
                        <div class="sim-kpi-lbl">Rdt brut→pulpe</div>
                    </div>
                    <div class="sim-kpi" :class="{ 'sim-kpi-off': calc.rdt_pulpe_cruf === null }">
                        <div class="sim-kpi-val">{{ calc.rdt_pulpe_cruf !== null ? calc.rdt_pulpe_cruf + '%' : '—' }}</div>
                        <div class="sim-kpi-lbl">Rdt pulpe→cruf</div>
                    </div>
                </div>

                <!-- ── Détails sucre ── -->
                <div class="sim-detail">
                    <span>Sucre apporté par le fruit</span>
                    <span>{{ calc.sucre_fruit_kg }} kg ({{ round(calc.sucre_fruit_kg * 1000, 0) }} g)</span>
                </div>
                <div class="sim-detail">
                    <span>Sucre total dans la crufiture</span>
                    <span>{{ calc.total_sucre_kg }} kg</span>
                </div>

            </template>
        </div>

    </div>
</PageCard>
</div>
</template>

<style scoped>
/* ── Layout principal : flexbox avec wrap naturel ────────────── */
/* Les deux colonnes se placent côte à côte si l'espace le permet,
   sinon elles s'empilent — sans jamais déborder */
.sim-root  { display: flex; flex-wrap: wrap; gap: 28px; align-items: flex-start; }
.sim-left  { flex: 1 1 300px; min-width: 0; }
.sim-right { flex: 1 1 320px; min-width: 0; }

/* ── Grilles internes : CSS grid natif ───────────────────────── */
/* minmax(0, 1fr) est essentiel : autorise les cellules à rétrécir
   en dessous de leur contenu minimal (évite le débordement) */
.sim-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.sim-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }

/* ── InputNumber full-width via inputClass prop ──────────────── */
:deep(.sim-input) { width: 100%; min-width: 0; box-sizing: border-box; }

/* ── Champs ──────────────────────────────────────────────────── */
.sim-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; min-width: 0; }
.sim-field label { font-size: 13px; font-weight: 600; color: #555; }
.sim-unit { font-weight: 400; color: #aaa; font-size: 11px; margin-left: 3px; }
.sim-opt  { font-weight: 400; color: #bbb; font-size: 11px; }
.sim-hint { font-size: 11px; color: #aaa; margin-top: 3px; }
.sim-sep  { font-size: 11px; color: #bbb; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
.sim-info-base { font-size: 12px; color: #b45309; background: #fef3c7; border-radius: 6px; padding: 6px 10px; margin: -6px 0 12px; }

/* ── Slider ──────────────────────────────────────────────────── */
.sim-slider-row { display: flex; align-items: center; gap: 8px; min-width: 0; }
.sim-slider     { flex: 1 1 0; min-width: 0; }
.sim-slider-val { font-weight: 700; color: var(--primary-color); width: 38px; text-align: right; font-size: 14px; flex-shrink: 0; }

/* ── Titres de section ───────────────────────────────────────── */
.sim-section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #999; margin: 0 0 14px; display: flex; align-items: center; flex-wrap: wrap; gap: 4px; }
.sim-badge { background: #fef3c7; color: #92400e; font-size: 11px; padding: 2px 8px; border-radius: 20px; font-weight: 600; text-transform: none; letter-spacing: 0; }

/* ── État d'attente ──────────────────────────────────────────── */
.sim-waiting { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; min-height: 260px; text-align: center; color: #bbb; font-size: 13px; line-height: 1.6; }

/* ── Bloc évaporation (valeur clé) ───────────────────────────── */
.sim-evap       { background: linear-gradient(135deg, #78350f, #b45309); color: #fff; border-radius: 14px; padding: 18px 22px; margin-bottom: 18px; }
.sim-evap-label { font-size: 11px; text-transform: uppercase; letter-spacing: .6px; opacity: .8; margin-bottom: 2px; }
.sim-evap-val   { font-size: 42px; font-weight: 900; line-height: 1; margin-bottom: 4px; }
.sim-evap-sub   { font-size: 13px; opacity: .85; margin-bottom: 8px; }
.sim-evap-hint  { font-size: 12px; opacity: .75; border-top: 1px solid rgba(255,255,255,.2); padding-top: 8px; }

/* ── Tableau des quantités ───────────────────────────────────── */
.sim-table    { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 4px; }
.sim-table th { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #aaa; padding: 4px 8px; border-bottom: 1px solid var(--surface-200); }
.sim-table td { padding: 7px 8px; border-bottom: 1px solid var(--surface-100); }
.tr           { text-align: right; }
.fb           { font-weight: 700; }
.sec          { color: var(--text-color-secondary); }
.dot          { font-size: 8px; margin-right: 6px; }
.r-fruit td   { background: #fff8f0; }
.r-total td   { background: var(--surface-50); }
.r-masse td   { background: var(--surface-100); font-size: 14px; }

/* ── KPIs de synthèse ────────────────────────────────────────── */
.sim-kpis    { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin: 14px 0 6px; }
.sim-kpi     { background: var(--surface-50); border: 1px solid var(--surface-200); border-radius: 10px; padding: 10px 6px; text-align: center; min-width: 0; }
.sim-kpi-off { opacity: .4; }
.sim-kpi-val { font-size: 18px; font-weight: 800; color: var(--primary-color); line-height: 1; }
.sim-kpi-lbl { font-size: 10px; color: #aaa; text-transform: uppercase; letter-spacing: .2px; margin-top: 4px; line-height: 1.3; }

/* ── Lignes de détail ────────────────────────────────────────── */
.sim-detail { display: flex; justify-content: space-between; font-size: 12px; color: #888; padding: 4px 0; border-bottom: 1px dashed var(--surface-200); }
</style>