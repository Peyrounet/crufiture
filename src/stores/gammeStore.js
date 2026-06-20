import { defineStore } from 'pinia';
import { ref } from 'vue';
import axiosCrufiture from '@/plugins/axiosCrufiture';

export const useGammeStore = defineStore('gamme', () => {
    const gammes = ref([]);

    async function charger() {
        try {
            const res = await axiosCrufiture.get('/gammes');
            if (res.data?.status === 'success') {
                gammes.value = res.data.details || [];
            }
        } catch {
            // silencieux
        }
    }

    return { gammes, charger };
});
