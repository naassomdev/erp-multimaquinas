/**
 * FilePond — uploads simples (foto da OS, capa do produto, etc.).
 *
 * Uso:
 *   <input type="file" name="foto" data-filepond
 *          data-max-size="10MB"
 *          data-accepted-types="image/jpeg,image/png,image/webp">
 */
import * as FilePond from 'filepond';
import FilePondPluginImagePreview     from 'filepond-plugin-image-preview';
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type';
import FilePondPluginFileValidateSize from 'filepond-plugin-file-validate-size';

import 'filepond/dist/filepond.min.css';
import 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.css';

FilePond.registerPlugin(
    FilePondPluginImagePreview,
    FilePondPluginFileValidateType,
    FilePondPluginFileValidateSize,
);

FilePond.setOptions({
    labelIdle:               'Arraste arquivos ou <span class="filepond--label-action">clique aqui</span>',
    labelInvalidField:       'Campo contém arquivos inválidos',
    labelFileWaitingForSize: 'Aguardando tamanho',
    labelFileSizeNotAvailable: 'Tamanho indisponível',
    labelFileLoading:        'Carregando',
    labelFileProcessing:     'Enviando',
    labelFileProcessingComplete: 'Enviado',
    labelFileProcessingAborted:  'Envio cancelado',
    labelTapToCancel:        'toque para cancelar',
    labelTapToRetry:         'toque para tentar de novo',
    labelTapToUndo:          'toque para desfazer',
    labelButtonRemoveItem:   'Remover',
    labelMaxFileSizeExceeded:'Arquivo muito grande',
    labelMaxFileSize:        'Tamanho máximo: {filesize}',
    labelFileTypeNotAllowed: 'Tipo de arquivo não permitido',
    fileValidateTypeLabelExpectedTypes: 'Esperado: {allTypes}',
});

export function init() {
    document.querySelectorAll('input[type="file"][data-filepond]').forEach((input) => {
        if (input._pond) return;

        const types = (input.dataset.acceptedTypes ?? '')
            .split(',').map(s => s.trim()).filter(Boolean);

        input._pond = FilePond.create(input, {
            allowMultiple: input.multiple,
            acceptedFileTypes: types.length ? types : null,
            maxFileSize:       input.dataset.maxSize ?? null,
        });
    });
}
