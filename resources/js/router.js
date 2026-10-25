import { createRouter, createWebHistory } from 'vue-router';
import { auth, loadUser } from './lib/auth';
import { setUnauthorizedHandler } from './lib/api';

const routes = [
    { path: '/login', name: 'login', component: () => import('./pages/LoginPage.vue'), meta: { public: true, title: 'Iniciar sesión' } },
    { path: '/', name: 'dashboard', component: () => import('./pages/DashboardPage.vue'), meta: { title: 'Resumen' } },
    { path: '/devices', name: 'devices', component: () => import('./pages/DevicesPage.vue'), meta: { title: 'Equipos' } },
    // El parametro es el hash del equipo, nunca el ID numerico.
    { path: '/devices/:id', name: 'device', component: () => import('./pages/DeviceDetailPage.vue'), meta: { title: 'Equipo' } },
    { path: '/app-versions', name: 'app-versions', component: () => import('./pages/AppVersionsPage.vue'), meta: { title: 'Versión de la app de cobro' } },
    { path: '/alerts', name: 'alerts', component: () => import('./pages/AlertsPage.vue'), meta: { title: 'Alertas de consumo' } },
    { path: '/bumps', name: 'bumps', component: () => import('./pages/BumpsPage.vue'), meta: { title: 'Topes' } },
    { path: '/usage', name: 'usage', component: () => import('./pages/UsagePage.vue'), meta: { title: 'Consumo de datos' } },
    { path: '/logs', name: 'logs', component: () => import('./pages/LogsPage.vue'), meta: { title: 'Registro de eventos' } },
    { path: '/passwords', name: 'passwords', component: () => import('./pages/PasswordsPage.vue'), meta: { title: 'Contraseñas' } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    if (!auth.ready) await loadUser();

    if (!to.meta.public && !auth.user) {
        return { name: 'login', query: to.fullPath !== '/' ? { next: to.fullPath } : {} };
    }
    if (to.name === 'login' && auth.user) return { name: 'dashboard' };
    return true;
});

router.afterEach((to) => {
    document.title = `${to.meta.title ?? 'Telpo'} · Telpo Admin`;
});

// Sesion vencida: volver al login desde cualquier pantalla.
setUnauthorizedHandler(() => {
    auth.user = null;
    if (router.currentRoute.value.name !== 'login') {
        router.push({ name: 'login', query: { next: router.currentRoute.value.fullPath } });
    }
});

export default router;
