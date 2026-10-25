import api from './api';

/**
 * Descarga un exporte (csv/xlsx/pdf). El servidor fija el nombre EX-T20-AAAA-MM-DD.ext en
 * Content-Disposition; aqui solo se guarda el blob. Los errores 422 llegan como blob JSON.
 */
export async function downloadExport(params) {
    try {
        const res = await api.get('/api/admin/export', { params, responseType: 'blob', timeout: 120000 });
        const disposition = res.headers['content-disposition'] || '';
        const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
        const name = match ? decodeURIComponent(match[1]) : `EX-T20.${params.format}`;

        const url = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2000);
        return name;
    } catch (error) {
        const blob = error?.response?.data;
        if (blob instanceof Blob) {
            try {
                const json = JSON.parse(await blob.text());
                error.response.data = json;
            } catch { /* se deja el error original */ }
        }
        throw error;
    }
}
