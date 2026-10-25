<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { login } from '../lib/auth';
import { errorMessage, fieldErrors } from '../lib/api';

const route = useRoute();
const router = useRouter();
const email = ref('');
const password = ref('');
const busy = ref(false);
const error = ref('');
const errors = ref({});

async function submit() {
    busy.value = true;
    error.value = '';
    errors.value = {};
    try {
        await login(email.value.trim(), password.value);
        const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') && !route.query.next.startsWith('//')
            ? route.query.next
            : '/';
        router.push(next);
    } catch (e) {
        errors.value = fieldErrors(e);
        error.value = Object.keys(errors.value).length ? '' : errorMessage(e, 'No se pudo iniciar sesión.');
        password.value = '';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="login-wrap">
        <form class="card login-card" @submit.prevent="submit" novalidate>
            <h1>Telpo Admin</h1>
            <p class="muted" style="margin:4px 0 20px">Ingresa con tu cuenta de administrador.</p>
            <div v-if="error" class="alert error" role="alert">{{ error }}</div>
            <div class="field">
                <label for="email">Correo</label>
                <input id="email" type="email" v-model="email" autocomplete="username" required autofocus />
                <span v-if="errors.email" class="err" role="alert">{{ errors.email }}</span>
            </div>
            <div class="field">
                <label for="password">Contraseña</label>
                <input id="password" type="password" v-model="password" autocomplete="current-password" required />
                <span v-if="errors.password" class="err" role="alert">{{ errors.password }}</span>
            </div>
            <button class="btn primary" style="width:100%;justify-content:center" :disabled="busy || !email || !password">
                {{ busy ? 'Entrando…' : 'Entrar' }}
            </button>
        </form>
    </div>
</template>
