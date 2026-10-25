import { reactive } from 'vue';
import api from './api';

export const auth = reactive({ user: null, ready: false });

export async function loadUser() {
    try {
        const { data } = await api.get('/api/admin/me');
        auth.user = data;
    } catch {
        auth.user = null;
    } finally {
        auth.ready = true;
    }
}

export async function login(email, password) {
    const { data } = await api.post('/api/admin/login', { email, password });
    auth.user = data;
}

export async function logout() {
    try {
        await api.post('/api/admin/logout');
    } finally {
        auth.user = null;
    }
}
