/**
 * API — wrapper fino sobre fetch que automatiza:
 *   - mesma origem (cookies de sessão)
 *   - header X-CSRF-Token a partir do <meta name="csrf-token">
 *   - parsing de JSON com tratamento de erro
 *
 * Uso:
 *   const data = await api.get('/api/clientes/busca?q=joao');
 *   const out  = await api.post('/api/orcamentos', { os_id, ... });
 */

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function request(method, url, body = null, extraHeaders = {}) {
    const init = {
        method,
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-Token': csrfToken(),
            ...extraHeaders,
        },
    };

    if (body instanceof FormData) {
        init.body = body;                       // browser seta multipart/form-data
    } else if (body !== null && body !== undefined) {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }

    const res = await fetch(url, init);
    const ct  = res.headers.get('content-type') ?? '';
    const isJson = ct.includes('application/json');
    const data = isJson ? await res.json() : await res.text();

    if (!res.ok) {
        const msg = (isJson && data?.error) || (isJson && data?.erro) || res.statusText;
        const err = new Error(`HTTP ${res.status}: ${msg}`);
        err.status   = res.status;
        err.response = data;
        throw err;
    }
    return data;
}

export const api = {
    get:    (url)             => request('GET',    url),
    post:   (url, body)       => request('POST',   url, body),
    put:    (url, body)       => request('PUT',    url, body),
    patch:  (url, body)       => request('PATCH',  url, body),
    delete: (url)             => request('DELETE', url),
    upload: (url, formData)   => request('POST',   url, formData),
};
