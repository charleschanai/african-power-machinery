import api from './api';

export async function downloadCsv(url, params) {
    const response = await api.get(url, { params, responseType: 'blob' });

    const disposition = response.headers['content-disposition'];
    const match = disposition && disposition.match(/filename="?([^"]+)"?/);
    const filename = match ? match[1] : 'export.csv';

    const blobUrl = window.URL.createObjectURL(new Blob([response.data]));
    const link = document.createElement('a');
    link.href = blobUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(blobUrl);
}
