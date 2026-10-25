import { reactive } from 'vue';
import api from './api';

/** Contador de alertas de consumo sin ver (insignia de la barra lateral). */
export const alertsState = reactive({ unseen: 0 });

export async function refreshAlerts() {
    try {
        const { data } = await api.get('/api/admin/usage-alerts/summary');
        alertsState.unseen = data.unseen ?? 0;
    } catch {
        /* la insignia es opcional */
    }
}
