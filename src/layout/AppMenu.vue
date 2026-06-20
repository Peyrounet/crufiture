<script setup>
import { computed, onMounted } from 'vue';
import AppMenuItem from './AppMenuItem.vue';
import { useGammeStore } from '@/stores/gammeStore';

const gammeStore = useGammeStore();
const gammes     = computed(() => gammeStore.gammes);

onMounted(gammeStore.charger);

// Items spécifiques par slug de gamme
const ITEMS_SPECIFIQUES = {
    crufiture: [
        { label: 'Dashboard',   icon: 'pi pi-fw pi-chart-pie',  to: '/dashboard/crufiture' },
        { label: 'Saveurs',     icon: 'pi pi-fw pi-tag',        to: '/dashboard/crufiture/saveurs' },
        { label: 'Recettes',    icon: 'pi pi-fw pi-book',       to: '/dashboard/crufiture/recettes' },
        { label: 'Lots',        icon: 'pi pi-fw pi-list',       to: '/dashboard/crufiture/lots' },
        { label: 'Simulateur',  icon: 'pi pi-fw pi-calculator', to: '/dashboard/crufiture/simulateur' },
    ],
};

function itemsGeneriques(slug) {
    return [
        { label: 'Dashboard', icon: 'pi pi-fw pi-chart-pie', to: `/dashboard/${slug}` },
        { label: 'Produits',  icon: 'pi pi-fw pi-box',       to: `/dashboard/${slug}/produits` },
        { label: 'Lots',      icon: 'pi pi-fw pi-list',      to: `/dashboard/${slug}/lots` },
    ];
}

const menuModel = computed(() => {
    const sections = [
        {
            label: 'Transformations',
            items: [
                { label: 'Tableau de bord',   icon: 'pi pi-fw pi-home', to: '/dashboard' },
                { label: 'Gammes & Produits', icon: 'pi pi-fw pi-th-large', to: '/dashboard/gammes' },
            ],
        },
    ];

    for (const gamme of gammes.value) {
        if (!gamme.actif) continue;
        sections.push({
            label: gamme.libelle,
            items: ITEMS_SPECIFIQUES[gamme.slug] ?? itemsGeneriques(gamme.slug),
        });
    }

    sections.push({
        label: 'Portail',
        items: [
            { label: 'Retour ferme', icon: 'pi pi-fw pi-arrow-left', url: '/ferme/dashboard' },
        ],
    });

    return sections;
});
</script>

<template>
    <ul class="layout-menu">
        <template v-for="(item, i) in menuModel" :key="i">
            <app-menu-item
                v-if="!item.separator"
                :item="item"
                :index="i"
            />
            <li v-if="item.separator" class="menu-separator"></li>
        </template>
    </ul>
</template>
