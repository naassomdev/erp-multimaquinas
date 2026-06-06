/**
 * GLightbox — modais de imagem (galeria de fotos da OS, vista explodida).
 *
 * Uso:
 *   <a href="/storage/foto1.jpg" data-glightbox data-gallery="os-123">
 *     <img src="/storage/foto1-thumb.jpg">
 *   </a>
 */
import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.min.css';

export function init() {
    if (window._glightbox) {
        window._glightbox.reload();
        return;
    }
    window._glightbox = GLightbox({
        selector: '[data-glightbox]',
        touchNavigation: true,
        loop: true,
        zoomable: true,
        closeOnOutsideClick: true,
        moreText: 'Ver mais',
        descPosition: 'bottom',
    });
}
