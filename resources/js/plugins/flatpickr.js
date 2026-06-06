/**
 * Flatpickr — inputs com [data-flatpickr]
 *
 * Atributos:
 *   data-flatpickr           ativa o picker
 *   data-mode="range"        seleção de período
 *   data-enable-time="true"  inclui hora/minuto
 *   data-min-date="today"    permite "today" ou data ISO
 *   data-default-date="..."  data padrão (ISO)
 */
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
import { Portuguese } from 'flatpickr/dist/l10n/pt.js';

flatpickr.localize(Portuguese);

export function init() {
    document.querySelectorAll('[data-flatpickr]').forEach((el) => {
        if (el._flatpickr) return;

        flatpickr(el, {
            mode:        el.dataset.mode ?? 'single',
            enableTime:  el.dataset.enableTime === 'true',
            time_24hr:   true,
            altInput:    true,
            altFormat:   el.dataset.enableTime === 'true' ? 'd/m/Y H:i' : 'd/m/Y',
            dateFormat:  el.dataset.enableTime === 'true' ? 'Y-m-d H:i' : 'Y-m-d',
            minDate:     el.dataset.minDate || undefined,
            maxDate:     el.dataset.maxDate || undefined,
            defaultDate: el.dataset.defaultDate || undefined,
        });
    });
}
