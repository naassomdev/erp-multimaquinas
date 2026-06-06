/**
 * ERP Multimáquinas — entry JavaScript principal
 *
 * Estratégia: o core (Bootstrap + layout + api) carrega em todas as páginas.
 * Plugins pesados (DataTables, Quill, FullCalendar, FilePond, Uppy) são
 * carregados sob demanda via dynamic import — só na página que precisar.
 */

// 1. Estilos principais (Vite injeta o CSS automaticamente)
import '@scss/app.scss';

// 1b. CSS de ícones (importados aqui porque o Sass não resolve node_modules)
import 'bootstrap-icons/font/bootstrap-icons.css';
import '@phosphor-icons/web/regular';
import '@phosphor-icons/web/bold';
import '@phosphor-icons/web/duotone';

// 2. Bootstrap JS (modais, dropdowns, tooltips, offcanvas etc.)
import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

// 3. Núcleo do app
import { initLayout } from './layout.js';
import { api }        from './api.js';
window.api = api;

// 4. Plugins opt-in — disparam só se houver elementos com data-attribute
//    correspondente na página atual.
const lazyPlugins = [
    { selector: '.js-datatable',     loader: () => import('./plugins/datatables.js')   },
    { selector: '[data-flatpickr]',  loader: () => import('./plugins/flatpickr.js')    },
    { selector: '.js-choices',       loader: () => import('./plugins/choices.js')      },
    { selector: '[data-quill]',      loader: () => import('./plugins/quill.js')        },
    { selector: '[data-filepond]',   loader: () => import('./plugins/filepond.js')     },
    { selector: '[data-uppy]',       loader: () => import('./plugins/uppy.js')         },
    { selector: '[data-glightbox]',  loader: () => import('./plugins/glightbox.js')    },
    { selector: '[data-fullcalendar]', loader: () => import('./plugins/fullcalendar.js') },
    { selector: '[data-chart]',      loader: () => import('./plugins/chart.js')        },
];

function bootstrapApp() {
    initLayout();

    for (const { selector, loader } of lazyPlugins) {
        if (document.querySelector(selector)) {
            loader().then((mod) => mod.init?.()).catch((err) => {
                console.error('[ERP] Falha ao carregar plugin', selector, err);
            });
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapApp);
} else {
    bootstrapApp();
}
