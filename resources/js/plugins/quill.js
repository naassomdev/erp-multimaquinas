/**
 * Quill 2 — editor de texto rico.
 *
 * Uso:
 *   <textarea name="conteudo" data-quill style="display:none;"></textarea>
 *   <div data-quill-target="conteudo"></div>
 *
 * O conteúdo HTML é sincronizado pra textarea[name=conteudo] no submit.
 */
import Quill from 'quill';
import 'quill/dist/quill.snow.css';

const TOOLBAR_DEFAULT = [
    [{ header: [1, 2, 3, false] }],
    ['bold', 'italic', 'underline'],
    [{ list: 'ordered' }, { list: 'bullet' }],
    [{ align: [] }],
    ['link', 'clean'],
];

export function init() {
    document.querySelectorAll('[data-quill-target]').forEach((el) => {
        if (el._quill) return;

        const targetName = el.dataset.quillTarget;
        const target = document.querySelector(`[name="${targetName}"]`);
        if (!target) {
            console.warn('[Quill] target não encontrado:', targetName);
            return;
        }

        const quill = new Quill(el, {
            theme: 'snow',
            modules: { toolbar: TOOLBAR_DEFAULT },
            placeholder: el.dataset.placeholder ?? 'Digite aqui…',
        });

        if (target.value) quill.root.innerHTML = target.value;

        // Sync no submit do form
        const form = target.closest('form');
        form?.addEventListener('submit', () => {
            target.value = quill.root.innerHTML;
        });

        el._quill = quill;
    });
}
