/**
 * simple-datatables — inicializa qualquer <table class="js-datatable">
 *
 * Atributos suportados:
 *   data-page-length="25"   (padrão 15)
 *   data-searchable="false" (desliga busca)
 */
import { DataTable } from 'simple-datatables';

const PT_BR = {
    placeholder: 'Buscar…',
    perPage:     '{select} por página',
    noRows:      'Nenhum registro encontrado',
    info:        'Mostrando {start} a {end} de {rows}',
};

export function init() {
    document.querySelectorAll('table.js-datatable').forEach((table) => {
        if (table.dataset.dtInitialized === '1') return;

        new DataTable(table, {
            perPage:    parseInt(table.dataset.pageLength ?? '15', 10),
            perPageSelect: [15, 25, 50, 100],
            searchable: table.dataset.searchable !== 'false',
            sortable:   table.dataset.sortable !== 'false',
            labels:     PT_BR,
        });
        table.dataset.dtInitialized = '1';
    });
}
