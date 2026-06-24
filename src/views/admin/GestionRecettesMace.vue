<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axiosCrufiture from '@/plugins/axiosCrufiture';
import { useGammeStore } from '@/stores/gammeStore';

const router     = useRouter();
const toast      = useToast();
const gammeStore = useGammeStore();

const COULEUR = '#7F77DD';

const recettes     = ref([]);
const loading      = ref(true);
const recherche    = ref('');
const filtreStatut = ref(null);
const deplies      = ref(new Set());

const statutOptions = [
    { label: 'Brouillon', value: 'brouillon' },
    { label: 'En test',   value: 'en_test'   },
    { label: 'Validées',  value: 'validee'   },
];
const statutSeverite = { brouillon: 'secondary', en_test: 'warning', validee: 'success' };
const statutLabels   = { brouillon: 'Brouillon', en_test: 'En test', validee: 'Validée' };

// ── Avatar ────────────────────────────────────────────────────
const COULEURS_AV = ['#e57373','#f06292','#ba68c8','#7986cb','#4fc3f7','#4db6ac','#81c784','#ffb74d','#a1887f','#90a4ae'];
function couleurAvatar(nom) {
    let h = 0;
    for (let i = 0; i < nom.length; i++) h = nom.charCodeAt(i) + ((h << 5) - h);
    return COULEURS_AV[Math.abs(h) % COULEURS_AV.length];
}
function initiales(nom) {
    const m = nom.trim().split(/\s+/);
    return m.length === 1 ? m[0].substring(0, 2).toUpperCase() : (m[0][0] + m[1][0]).toUpperCase();
}

// ── Chargement ────────────────────────────────────────────────
onMounted(charger);

async function charger() {
    loading.value = true;
    try {
        await gammeStore.charger();
        const gamme = gammeStore.gammes.find(g => g.slug === 'maceration_alcool');
        const params = { gamme_id: gamme?.id ?? '' };
        if (filtreStatut.value)     params.statut = filtreStatut.value;
        if (recherche.value.trim()) params.q      = recherche.value.trim();

        const res = await axiosCrufiture.get('/recettes-transfo', { params });
        if (res.data?.status === 'success') recettes.value = res.data.details;
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de charger les recettes.', life: 4000 });
    } finally {
        loading.value = false;
    }
}

// ── Filtre local (recherche) ──────────────────────────────────
const groupes = computed(() => {
    const q = recherche.value.trim().toLowerCase();
    if (!q) return recettes.value;
    return recettes.value.filter(r =>
        r.nom.toLowerCase().includes(q) ||
        (r.famille ?? '').toLowerCase().includes(q) ||
        r.versions.some(v => v.notes_version?.toLowerCase().includes(q))
    );
});

// ── Toggle dépliant ───────────────────────────────────────────
function toggleDeplier(id) {
    if (deplies.value.has(id)) deplies.value.delete(id);
    else deplies.value.add(id);
    deplies.value = new Set(deplies.value);
}

// ── Navigation ────────────────────────────────────────────────
function ouvrirVersion(id) { router.push('/dashboard/maceration_alcool/recettes/' + id); }
function nouvelleRecette() { router.push('/dashboard/maceration_alcool/recettes/nouvelle'); }

// ── Export PDF ────────────────────────────────────────────────
function exporterPdf(versionId, format) {
    const url = '/transformation/api/recettes-transfo/export-pdf?version_id=' + versionId + '&format=' + format;
    window.open(url, '_blank');
}

// ── Changement statut depuis la liste ────────────────────────
const changingStatut = ref(null);
const menuRefs = ref({});
function setMenuRef(el, versionId) {
    if (el) menuRefs.value[versionId] = el;
    else delete menuRefs.value[versionId];
}

function statutMenuItems(version) {
    return [
        { label: 'Brouillon', command: () => changerStatut(version, 'brouillon') },
        { label: 'En test',   command: () => changerStatut(version, 'en_test')   },
        { label: 'Validée',   command: () => changerStatut(version, 'validee')   },
    ];
}

async function changerStatut(version, statut) {
    changingStatut.value = version.id;
    try {
        const res = await axiosCrufiture.put('/recettes-transfo/statut', { version_id: version.id, statut });
        if (res.data?.status === 'success') {
            toast.add({ severity: 'success', summary: 'Statut mis à jour', detail: statutLabels[statut], life: 2000 });
            await charger();
        } else {
            toast.add({ severity: 'error', summary: 'Erreur', detail: res.data?.message, life: 4000 });
        }
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de changer le statut.', life: 4000 });
    } finally {
        changingStatut.value = null;
    }
}

// ── Duplication ───────────────────────────────────────────────
const duplicating = ref(null);

async function dupliquer(version) {
    duplicating.value = version.id;
    try {
        const res = await axiosCrufiture.post('/recettes-transfo/dupliquer', null, {
            params: { version_id: version.id }
        });
        if (res.data?.status === 'success') {
            toast.add({ severity: 'success', summary: 'Nouvelle version créée', detail: 'v' + res.data.details.numero, life: 3000 });
            await charger();
            router.push('/dashboard/maceration_alcool/recettes/' + res.data.details.version_id);
        }
    } catch {
        toast.add({ severity: 'error', summary: 'Erreur', detail: 'Impossible de dupliquer.', life: 4000 });
    } finally {
        duplicating.value = null;
    }
}

// ── Suppression ───────────────────────────────────────────────
const confirmVisible = ref(false);
const versionASupp   = ref(null);
const suppression    = ref(false);

function confirmerSuppression(recette, version) {
    versionASupp.value   = { recette, version };
    confirmVisible.value = true;
}

async function supprimer() {
    suppression.value = true;
    try {
        const res = await axiosCrufiture.delete('/recettes-transfo', { params: { version_id: versionASupp.value.version.id } });
        toast.add({ severity: 'success', summary: 'OK', detail: res.data?.message ?? 'Version supprimée.', life: 3000 });
        confirmVisible.value = false;
        await charger();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: e.response?.data?.message ?? 'Impossible de supprimer.', life: 4000 });
    } finally {
        suppression.value = false;
    }
}
</script>

<template>
<div class="col-12">
    <PageCard titre="Recettes — Macération alcoolique">
        <template #actions>
            <Button label="Nouvelle recette" icon="pi pi-plus"
                    :style="{ background: COULEUR, borderColor: COULEUR }"
                    @click="nouvelleRecette" />
        </template>

        <!-- Filtres -->
        <div class="rm-filtres">
            <div class="search-wrap">
                <svg viewBox="0 0 20 20" fill="none" class="search-icon">
                    <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M13.5 13.5L17 17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                <input v-model="recherche" class="search-input" placeholder="Rechercher…" @keyup.enter="charger" />
                <button v-if="recherche" class="search-clear" @click="recherche = ''; charger()">✕</button>
            </div>
            <Dropdown
                v-model="filtreStatut"
                :options="statutOptions"
                option-label="label"
                option-value="value"
                placeholder="Tous statuts"
                show-clear
                style="width:160px"
                @change="charger"
            />
        </div>

        <div v-if="loading" class="flex justify-content-center p-5"><ProgressSpinner /></div>

        <p v-else-if="groupes.length === 0" class="rm-empty">
            {{ recherche || filtreStatut ? 'Aucune recette ne correspond.' : 'Aucune recette enregistrée.' }}
        </p>

        <!-- Liste groupée par recette -->
        <div v-else class="rec-groupes">
            <div v-for="groupe in groupes" :key="groupe.id" class="rec-groupe">

                <!-- Ligne principale — dernière version -->
                <div class="rec-ligne-principale">

                    <div class="rec-avatar" :style="{ background: couleurAvatar(groupe.nom) }">
                        {{ initiales(groupe.nom) }}
                    </div>

                    <span class="rec-nom">{{ groupe.nom }}</span>
                    <span v-if="groupe.famille" class="rec-famille">{{ groupe.famille }}</span>
                    <span class="rec-separateur">·</span>

                    <div class="rec-version-badge">v{{ groupe.versions[0].numero }}</div>

                    <span class="rec-notes-version">{{ groupe.versions[0].notes_version || '—' }}</span>

                    <div class="rec-meta-inline">
                        <Tag
                            :value="statutLabels[groupe.versions[0].statut]"
                            :severity="statutSeverite[groupe.versions[0].statut]"
                            style="font-size:10px"
                        />
                        <span class="rec-meta-chip">
                            <i class="pi pi-list" style="font-size:10px" />
                            {{ groupe.versions[0].nb_ingredients }} ingr.
                        </span>
                        <span v-if="groupe.versions[0].mace_alcool?.duree_maceration_cible_j" class="rec-meta-chip">
                            <i class="pi pi-clock" style="font-size:10px" />
                            {{ groupe.versions[0].mace_alcool.duree_maceration_cible_j }}j mac.
                        </span>
                    </div>

                    <div class="rec-card-actions" @click.stop>
                        <!-- Changer statut -->
                        <Button icon="pi pi-sync" text rounded size="small"
                            v-tooltip.top="'Changer le statut'"
                            :loading="changingStatut === groupe.versions[0].id"
                            @click="(e) => menuRefs[groupe.versions[0].id]?.toggle(e)"
                        />
                        <Menu
                            :ref="el => setMenuRef(el, groupe.versions[0].id)"
                            :model="statutMenuItems(groupe.versions[0])"
                            popup
                        />
                        <!-- PDF chef -->
                        <Button icon="pi pi-file-pdf" text rounded size="small"
                            v-tooltip.top="'Export PDF chef'"
                            @click="exporterPdf(groupe.versions[0].id, 'chef')"
                        />
                        <!-- Nouvelle version -->
                        <Button icon="pi pi-copy" text rounded size="small"
                            v-tooltip.top="'Nouvelle version'"
                            :loading="duplicating === groupe.versions[0].id"
                            @click="dupliquer(groupe.versions[0])"
                        />
                        <!-- Éditer -->
                        <Button icon="pi pi-pencil" text rounded size="small"
                            v-tooltip.top="'Éditer'"
                            @click="ouvrirVersion(groupe.versions[0].id)"
                        />
                        <!-- Supprimer -->
                        <Button icon="pi pi-trash" text rounded size="small" severity="danger"
                            v-tooltip.top="'Supprimer cette version'"
                            @click="confirmerSuppression(groupe, groupe.versions[0])"
                        />
                    </div>

                    <button
                        v-if="groupe.versions.length > 1"
                        class="rec-toggle-versions"
                        @click="toggleDeplier(groupe.id)"
                    >
                        <span>{{ groupe.versions.length - 1 }} v. précédente{{ groupe.versions.length > 2 ? 's' : '' }}</span>
                        <i class="pi" :class="deplies.has(groupe.id) ? 'pi-chevron-up' : 'pi-chevron-down'" style="font-size:10px" />
                    </button>
                </div>

                <!-- Versions précédentes -->
                <div v-if="groupe.versions.length > 1 && deplies.has(groupe.id)" class="rec-versions-precedentes">
                    <div v-for="version in groupe.versions.slice(1)" :key="version.id" class="rec-ligne-ancienne">
                        <div class="rec-version-badge rec-version-ancienne">v{{ version.numero }}</div>
                        <span class="rec-titre-ancien" @click="ouvrirVersion(version.id)">
                            {{ version.notes_version || '—' }}
                        </span>
                        <div class="rec-meta-inline">
                            <Tag :value="statutLabels[version.statut]" :severity="statutSeverite[version.statut]" style="font-size:10px" />
                        </div>
                        <div class="rec-card-actions" @click.stop>
                            <Button icon="pi pi-file-pdf" text rounded size="small"
                                v-tooltip.top="'PDF'" @click="exporterPdf(version.id, 'chef')" />
                            <Button icon="pi pi-pencil" text rounded size="small"
                                v-tooltip.top="'Ouvrir'" @click="ouvrirVersion(version.id)" />
                            <Button icon="pi pi-trash" text rounded size="small" severity="danger"
                                v-tooltip.top="'Supprimer'"
                                @click="confirmerSuppression(groupe, version)" />
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div v-if="!loading && groupes.length > 0" class="text-400 text-sm mt-3">
            {{ groupes.length }} recette{{ groupes.length > 1 ? 's' : '' }}
        </div>
    </PageCard>

    <!-- Confirmation suppression -->
    <Dialog v-model:visible="confirmVisible" header="Supprimer cette version ?" :style="{ width: '420px' }" modal>
        <p style="margin:0">
            Supprimer <strong>{{ versionASupp?.recette?.nom }}</strong>
            <span style="color:#999"> v{{ versionASupp?.version?.numero }}</span> ?
        </p>
        <p style="margin-top:8px;font-size:12px;color:#999;font-style:italic">
            Impossible de supprimer une version utilisée par un lot actif.
        </p>
        <template #footer>
            <Button label="Annuler" text :disabled="suppression" @click="confirmVisible = false" />
            <Button label="Supprimer" severity="danger" icon="pi pi-trash" :loading="suppression" @click="supprimer" />
        </template>
    </Dialog>
</div>
</template>

<style scoped>
.rm-filtres { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.search-wrap { position: relative; width: 280px; flex-shrink: 0; display: flex; align-items: center; }
.search-icon { position: absolute; left: 10px; width: 16px; color: var(--text-color-secondary); pointer-events: none; }
.search-input { width: 100%; padding: 8px 30px 8px 32px; border: 1.5px solid var(--surface-border); border-radius: 10px; font-size: .9rem; background: var(--surface-card); color: var(--text-color); outline: none; }
.search-input:focus { border-color: #7F77DD; }
.search-clear { position: absolute; right: 8px; background: none; border: none; color: var(--text-color-secondary); cursor: pointer; font-size: .8rem; }

.rm-empty { color: #aaa; font-size: 13px; font-style: italic; padding: 8px 0; margin: 0; }

.rec-groupes { display: flex; flex-direction: column; gap: 0; }
.rec-groupe { border-bottom: 1px solid var(--surface-border); }
.rec-groupe:last-child { border-bottom: none; }

.rec-ligne-principale { display: flex; align-items: center; gap: 0.625rem; padding: 0.625rem 0.25rem; }
.rec-avatar { flex-shrink: 0; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #fff; }
.rec-nom { font-weight: 700; font-size: 14px; color: var(--text-color); white-space: nowrap; flex-shrink: 0; }
.rec-famille { font-size: 12px; color: var(--text-color-secondary); white-space: nowrap; flex-shrink: 0; }
.rec-separateur { color: var(--surface-border); font-size: 16px; flex-shrink: 0; line-height: 1; }
.rec-version-badge { flex-shrink: 0; background: var(--surface-100, #f5f5f5); border: 1px solid var(--surface-border); border-radius: 5px; padding: 2px 7px; font-size: 11px; font-weight: 700; color: var(--text-color-secondary); letter-spacing: 0.3px; white-space: nowrap; }
.rec-notes-version { font-size: 13px; font-weight: 500; color: var(--text-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex-shrink: 1; min-width: 0; }
.rec-meta-inline { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; margin-left: auto; }
.rec-meta-chip { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; color: #bbb; white-space: nowrap; }
.rec-card-actions { flex-shrink: 0; display: flex; gap: 0; }

.rec-toggle-versions { flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px; border: 1px solid var(--surface-border); border-radius: 5px; background: transparent; padding: 3px 8px; cursor: pointer; font-size: 11px; color: var(--text-color-secondary); white-space: nowrap; transition: background 0.15s, border-color 0.15s; line-height: 1.4; }
.rec-toggle-versions:hover { background: #EEEDFE; border-color: #7F77DD; color: #7F77DD; }

.rec-versions-precedentes { padding: 0 0 0.5rem 2.375rem; display: flex; flex-direction: column; gap: 0; }
.rec-ligne-ancienne { display: flex; align-items: center; gap: 0.625rem; padding: 0.375rem 0.25rem; border-radius: 6px; transition: background 0.12s; }
.rec-ligne-ancienne:hover { background: var(--surface-50, #fafafa); }
.rec-version-ancienne { opacity: 0.65; }
.rec-titre-ancien { font-size: 13px; color: var(--text-color-secondary); cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex-shrink: 1; min-width: 0; }
.rec-titre-ancien:hover { color: #7F77DD; text-decoration: underline; }
</style>
