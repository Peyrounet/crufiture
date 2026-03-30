<script setup>
import { ref } from 'vue';
import { useAuthStore } from '@/stores/authStore';

const authStore  = useAuthStore();
const email      = ref('');
const password   = ref('');
const error      = ref('');
const loading    = ref(false);

const handleLogin = async () => {
    if (!email.value || !password.value) {
        error.value = 'Email et mot de passe requis.';
        return;
    }
    loading.value = true;
    error.value   = '';
    const result  = await authStore.login({ email: email.value, password: password.value });
    loading.value = false;
    if (result) error.value = result;
};
</script>

<template>
<div class="cruf-login-page">
    <div class="cruf-login-card">
        <div class="cruf-login-header">
            <div class="cruf-login-icon">🫙</div>
            <h1>Crufiture</h1>
            <p>Trésors du Peyrounet — Production</p>
        </div>
        <div class="cruf-login-body">
            <div class="cruf-field">
                <label>Adresse email</label>
                <InputText
                    v-model="email"
                    type="email"
                    placeholder="votre@email.fr"
                    class="w-full"
                    @keyup.enter="handleLogin"
                />
            </div>
            <div class="cruf-field">
                <label>Mot de passe</label>
                <Password
                    v-model="password"
                    placeholder="••••••••"
                    :feedback="false"
                    toggleMask
                    class="w-full"
                    inputClass="w-full"
                    @keyup.enter="handleLogin"
                />
            </div>
            <p v-if="error" class="cruf-login-error">{{ error }}</p>
            <Button
                label="Se connecter"
                icon="pi pi-sign-in"
                class="w-full mt-2"
                :loading="loading"
                @click="handleLogin"
            />
            <div class="cruf-login-footer">
                <a href="/ferme/dashboard">← Retour au portail</a>
            </div>
        </div>
    </div>
</div>
</template>

<style scoped>
.cruf-login-page {
    min-height: 100vh;
    background: linear-gradient(135deg, #78350f 0%, #b45309 60%, #d97706 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.cruf-login-card {
    background: #fff;
    border-radius: 20px;
    width: 100%;
    max-width: 420px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
}
.cruf-login-header {
    background: linear-gradient(135deg, #78350f, #b45309);
    padding: 36px 32px 28px;
    text-align: center;
    color: #fff;
}
.cruf-login-icon { font-size: 48px; margin-bottom: 10px; }
.cruf-login-header h1 { font-size: 22px; margin: 0 0 4px; font-family: Georgia, serif; font-style: italic; }
.cruf-login-header p  { margin: 0; font-size: 13px; opacity: .8; }
.cruf-login-body  { padding: 28px 32px 32px; }
.cruf-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.cruf-field label { font-size: 13px; font-weight: 600; color: #444; }
.cruf-login-error {
    background: #ffebee; color: #c62828; border-radius: 8px;
    padding: 10px 14px; font-size: 13px; margin-bottom: 12px;
}
.cruf-login-footer { text-align: center; margin-top: 20px; }
.cruf-login-footer a { font-size: 12px; color: #888; text-decoration: none; }
.cruf-login-footer a:hover { color: #78350f; }
</style>
