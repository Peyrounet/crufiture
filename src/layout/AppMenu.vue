<script setup>
import { computed } from 'vue';
import AppMenuItem from './AppMenuItem.vue';

const menuModel = computed(() => [
    {
        label: 'Crufiture',
        items: [
            {
                label: 'Tableau de bord',
                icon: 'pi pi-fw pi-home',
                to: '/dashboard',
            },
        ],
    },
    {
        label: 'Production',
        items: [
            {
                label: 'Lots',
                icon: 'pi pi-fw pi-list',
                to: '/dashboard/lots',
            },
            {
                label: 'Saveurs',
                icon: 'pi pi-fw pi-tag',
                to: '/dashboard/saveurs',
            },
            {
                label: 'Recettes',
                icon: 'pi pi-fw pi-book',
                to: '/dashboard/recettes',
            },
        ],
    },
    {
        label: 'Portail',
        items: [
            {
                label: '← Retour ferme',
                icon: 'pi pi-fw pi-arrow-left',
                url: '/ferme/dashboard',
            },
        ],
    },
]);
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
