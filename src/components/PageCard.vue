<script setup>
defineProps({
    titre: { type: String, default: '' },
});
</script>

<template>
    <div class="surface-card border-round shadow-1 p-4 h-full">
        <div v-if="titre || $slots.actions" class="flex align-items-center justify-content-between mb-3">
            <span class="font-semibold text-lg">{{ titre }}</span>
            <div v-if="$slots.actions">
                <slot name="actions" />
            </div>
        </div>
        <slot />
    </div>
</template>
