/**
 * Uppy — dashboard avançado de upload (XML de NF-e, lotes de fotos, etc.)
 *
 * Uso:
 *   <div data-uppy
 *        data-target-input="#xml_nfe"
 *        data-endpoint="/estoque/importar/preview"
 *        data-accepted=".xml,application/xml,text/xml"
 *        data-max-files="1"></div>
 */
import Uppy from '@uppy/core';
import Dashboard from '@uppy/dashboard';
import XHRUpload from '@uppy/xhr-upload';
import ptBR from '@uppy/locales/lib/pt_BR.js';

import '@uppy/core/dist/style.min.css';
import '@uppy/dashboard/dist/style.min.css';

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export function init() {
    document.querySelectorAll('[data-uppy]').forEach((el) => {
        if (el._uppy) return;

        const accepted = (el.dataset.accepted ?? '')
            .split(',').map(s => s.trim()).filter(Boolean);

        const uppy = new Uppy({
            id: el.id || 'uppy-' + Math.random().toString(36).slice(2, 8),
            autoProceed: false,
            allowMultipleUploadBatches: true,
            locale: ptBR,
            restrictions: {
                maxNumberOfFiles: parseInt(el.dataset.maxFiles ?? '1', 10),
                allowedFileTypes: accepted.length ? accepted : null,
            },
        });

        uppy.use(Dashboard, {
            target: el,
            inline: true,
            height: parseInt(el.dataset.height ?? '320', 10),
            proudlyDisplayPoweredByUppy: false,
            note: el.dataset.note ?? '',
            theme: 'light',
        });

        if (el.dataset.endpoint) {
            uppy.use(XHRUpload, {
                endpoint:    el.dataset.endpoint,
                fieldName:   el.dataset.fieldName ?? 'file',
                formData:    true,
                headers:     { 'X-CSRF-Token': csrfToken() },
            });
        }

        el._uppy = uppy;
    });
}
