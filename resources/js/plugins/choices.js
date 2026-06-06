/**
 * Choices.js — selects bonitos com busca embutida.
 *
 * Aplica em qualquer <select class="js-choices">.
 * Atributos:
 *   data-search="false"      desliga campo de busca
 *   data-placeholder="..."   placeholder do select
 *   multiple                 (atributo nativo) ativa modo multi-select
 */
import Choices from 'choices.js';
import 'choices.js/public/assets/styles/choices.min.css';

export function init() {
    document.querySelectorAll('select.js-choices').forEach((sel) => {
        if (sel.dataset.choicesInitialized === '1') return;

        new Choices(sel, {
            removeItemButton:        sel.multiple,
            searchEnabled:           sel.dataset.search !== 'false',
            placeholderValue:        sel.dataset.placeholder ?? null,
            shouldSort:              false,
            allowHTML:               false,
            itemSelectText:          '',
            noResultsText:           'Nenhum resultado encontrado',
            noChoicesText:           'Sem opções disponíveis',
            loadingText:             'Carregando…',
        });
        sel.dataset.choicesInitialized = '1';
    });
}
