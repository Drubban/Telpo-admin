<script setup>
import { useRoute, useRouter } from 'vue-router';
import { onBeforeUnmount, watch } from 'vue';
import { auth, logout } from './lib/auth';
import { alertsState, refreshAlerts } from './lib/alerts';

const route = useRoute();
const router = useRouter();
let alertsTimer = null;
watch(() => auth.user, (u) => {
    clearInterval(alertsTimer);
    if (u) {
        refreshAlerts();
        alertsTimer = setInterval(refreshAlerts, 60000);
    }
}, { immediate: true });
onBeforeUnmount(() => clearInterval(alertsTimer));

async function signOut() {
    await logout();
    router.push({ name: 'login' });
}
</script>

<template>
    <router-view v-if="route.meta.public || !auth.user" />
    <div v-else class="shell">
        <aside class="side" aria-label="Navegación principal">
            <div class="brand"><i aria-hidden="true"></i><span>Telpo Admin</span></div>
            <nav class="nav">
                <router-link to="/" exact>Resumen</router-link>
                <router-link to="/devices">Equipos</router-link>
                <router-link to="/usage">Consumo de datos</router-link>
                <router-link to="/alerts">Alertas de consumo<span v-if="alertsState.unseen" class="nav-count">{{ alertsState.unseen }}</span></router-link>
                <router-link to="/app-versions">App de cobro</router-link>
                <router-link to="/bumps">Topes</router-link>
                <router-link to="/logs">Registro de eventos</router-link>
                <router-link to="/passwords">Contraseñas</router-link>
            </nav>
            <div class="spacer"></div>
            <div class="userbox">
                <div><strong>{{ auth.user?.name }}</strong></div>
                <div class="muted">{{ auth.user?.email }}</div>
                <button class="btn sm signout" @click="signOut">Cerrar sesión</button>
            </div>
        </aside>
        <main class="main">
            <router-view />
        </main>
    </div>
</template>
