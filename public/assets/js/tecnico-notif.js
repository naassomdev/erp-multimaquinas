(function () {
    'use strict';

    var POLL_INTERVAL_MS = 30000;

    var bellBtn   = document.getElementById('notif-bell-btn');
    var bellIcon  = bellBtn ? bellBtn.querySelector('i') : null;
    var bellBadge = document.getElementById('notif-bell-badge');
    
    var drawer      = document.getElementById('notif-drawer');
    var backdrop    = document.getElementById('notif-drawer-backdrop');
    var closeBtn    = document.getElementById('notif-drawer-close');
    var list        = document.getElementById('notif-list');
    var actionsBox  = document.getElementById('notif-drawer-actions');
    var markAllBtn  = document.getElementById('notif-mark-all-read');

    if (!bellBtn || !bellBadge || !drawer || !list) return;

    // ── Utilitários ──────────────────────────────────────────────────────────

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatDate(dt) {
        if (!dt) return '';
        var d = new Date(dt.replace(' ', 'T'));
        var diffMin = Math.floor((Date.now() - d.getTime()) / 60000);
        if (diffMin < 1)  return 'agora';
        if (diffMin < 60) return diffMin + ' min atrás';
        var diffH = Math.floor(diffMin / 60);
        if (diffH < 24)   return diffH + 'h atrás';
        return d.toLocaleDateString('pt-BR');
    }

    var TIPO_META = {
        aprovado:  { icon: 'ph-check-circle', cls: 'notif-card--success' },
        cancelado: { icon: 'ph-x-circle',     cls: 'notif-card--danger'  },
        pronto:    { icon: 'ph-package',       cls: 'notif-card--info'    },
        info:      { icon: 'ph-info',          cls: 'notif-card--info'    },
        descarte:  { icon: 'ph-trash',         cls: 'notif-card--danger'  },
        diagnostico: { icon: 'ph-clipboard-text', cls: 'notif-card--info' }
    };

    // ── Badge e Sinalizador ───────────────────────────────────────────────────

    function updateBadge(count) {
        if (count > 0) {
            bellBadge.textContent = count > 99 ? '99+' : String(count);
            bellBadge.hidden = false;
            if (bellIcon) bellIcon.classList.add('notif-bell-ring');
            bellBtn.setAttribute('aria-label', 'Notificações (' + count + ' novas)');
        } else {
            bellBadge.hidden = true;
            if (bellIcon) bellIcon.classList.remove('notif-bell-ring');
            bellBtn.setAttribute('aria-label', 'Notificações');
        }
    }

    // ── Renderização da Lista ────────────────────────────────────────────────

    function renderList(notificacoes) {
        list.innerHTML = '';

        if (!notificacoes || notificacoes.length === 0) {
            if (actionsBox) actionsBox.style.display = 'none';
            list.innerHTML = 
                '<li class="notif-empty">' +
                    '<i class="ph ph-bell-slash"></i>' +
                    'Nenhuma notificação nova.' +
                '</li>';
            return;
        }

        if (actionsBox) actionsBox.style.display = 'flex';

        notificacoes.forEach(function (n) {
            // Se a mensagem contém alertas de falta de estoque, marcamos como warning
            var tipo = n.tipo;
            if (n.mensagem.indexOf('sem estoque') !== -1 || n.mensagem.indexOf('⚠️') !== -1) {
                tipo = 'warning';
            }

            var meta      = TIPO_META[tipo] || TIPO_META.info;
            var equipNum  = parseInt(n.equip_idx, 10) + 1;
            var url       = n.url || ('/tecnico/os/' + encodeURIComponent(n.os_id) + '/equipamento/' + n.equip_idx);
            var urlTitle  = n.url_title || 'Abrir equipamento';
            var clienteNome = String(n.cliente_nome || '').trim();
            var tipoEquip = String(n.equip_tipo_label || 'Equipamento').trim() || 'Equipamento';

            var li = document.createElement('li');
            li.className = 'notif-card ' + meta.cls;
            li.id = 'notif-card-' + n.id;
            
            // Trata quebras de linha e tags para exibição correta
            var formattedMsg = escapeHtml(n.mensagem).replace(/\n/g, '<br>');

            li.innerHTML =
                '<div class="notif-card__top">' +
                    '<span class="notif-card__icon"><i class="ph ' + escapeHtml(meta.icon) + '"></i></span>' +
                    '<div class="notif-card__body">' +
                        '<div class="notif-card__message">' + formattedMsg + '</div>' +
                        (clienteNome
                            ? '<div class="notif-card__client" title="' + escapeHtml(clienteNome) + '">' + escapeHtml(clienteNome) + '</div>'
                            : '') +
                        '<div class="notif-card__meta">' +
                            '<span><i class="ph ph-file-text"></i> OS ' + escapeHtml(n.os_id) + '</span>' +
                            '<span>&middot;</span>' +
                            '<span><i class="ph ph-wrench"></i> Equipamento #' + equipNum + '</span>' +
                            '<span>&middot;</span>' +
                            '<span>' + escapeHtml(tipoEquip) + '</span>' +
                            '<span>&middot;</span>' +
                            '<span><i class="ph ph-clock"></i> ' + escapeHtml(formatDate(n.created_at)) + '</span>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="notif-card__actions">' +
                    '<button class="btn-notif-action btn-notif-action--done" data-action="read" data-id="' + n.id + '" title="Marcar como lida">' +
                        '<i class="ph ph-check"></i> Lida' +
                    '</button>' +
                    '<a href="' + escapeHtml(url) + '" class="btn-notif-action" title="' + escapeHtml(urlTitle) + '">' +
                        '<i class="ph ph-arrow-square-out"></i> Abrir' +
                    '</a>' +
                '</div>';

            // Escuta o botão de marcar como lida individualmente
            var readBtn = li.querySelector('[data-action="read"]');
            if (readBtn) {
                readBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    marcarLida(n.id);
                });
            }

            list.appendChild(li);
        });
    }

    // ── Ações AJAX ───────────────────────────────────────────────────────────

    function marcarLida(id) {
        var card = document.getElementById('notif-card-' + id);
        if (card) {
            card.classList.add('notif-card-fadeout');
            setTimeout(function() {
                card.remove();
                if (list.querySelectorAll('.notif-card').length === 0) {
                    renderList([]);
                }
            }, 300);
        }

        // Requisição AJAX
        fetch('/api/tecnico/notificacoes/' + id + '/lida', {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken(),
            },
        })
        .then(function(res) {
            if (res.ok) {
                // Atualiza contadores e badge de forma otimista
                var currentCount = parseInt(bellBadge.textContent, 10) || 0;
                updateBadge(Math.max(0, currentCount - 1));
            }
        })
        .catch(function () { /* silencia */ });
    }

    function marcarTodasComoLidas() {
        var cards = list.querySelectorAll('.notif-card');
        cards.forEach(function(card) {
            card.classList.add('notif-card-fadeout');
        });

        setTimeout(function() {
            renderList([]);
        }, 300);

        // Chamada assíncrona para limpar todas
        fetch('/api/tecnico/notificacoes/limpar', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken(),
            },
        })
        .then(function(res) {
            if (res.ok) {
                updateBadge(0);
            }
        })
        .catch(function() { /* silencia */ });
    }

    // ── Fetch e Polling ──────────────────────────────────────────────────────

    function fetchNotificacoes(forceRender) {
        fetch('/api/tecnico/notificacoes', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        .then(function (res) {
            if (!res.ok) return null;
            return res.json();
        })
        .then(function (data) {
            if (!data || !data.ok) return;
            updateBadge(data.total_naolidas || 0);
            // Só renderiza novamente a gaveta se ela estiver aberta ou sob requisição forçada
            if (forceRender || (!drawer.hidden && drawer.classList.contains('is-open'))) {
                renderList(data.notificacoes || []);
            }
        })
        .catch(function () { /* silencia */ });
    }

    // ── Gaveta (Open/Close) ──────────────────────────────────────────────────

    function openDrawer() {
        drawer.hidden = false;
        // Permite o repaint antes de adicionar a classe para a animação do CSS
        setTimeout(function() {
            drawer.classList.add('is-open');
            bellBtn.setAttribute('aria-expanded', 'true');
            fetchNotificacoes(true);
        }, 10);
    }

    function closeDrawer() {
        drawer.classList.remove('is-open');
        bellBtn.setAttribute('aria-expanded', 'false');
        // Aguarda a transição terminar antes de ocultar o container fisicamente
        setTimeout(function() {
            if (!drawer.classList.contains('is-open')) {
                drawer.hidden = true;
            }
        }, 300);
    }

    // ── Ouvintes de Eventos ──────────────────────────────────────────────────

    bellBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (drawer.hidden || !drawer.classList.contains('is-open')) {
            openDrawer();
        } else {
            closeDrawer();
        }
    });

    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    if (backdrop) backdrop.addEventListener('click', closeDrawer);

    if (markAllBtn) {
        markAllBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            marcarTodasComoLidas();
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && (!drawer.hidden && drawer.classList.contains('is-open'))) {
            closeDrawer();
            bellBtn.focus();
        }
    });

    // ── Inicialização ────────────────────────────────────────────────────────

    bellBtn.setAttribute('aria-haspopup', 'true');
    bellBtn.setAttribute('aria-expanded', 'false');
    drawer.hidden = true;

    fetchNotificacoes();
    setInterval(fetchNotificacoes, POLL_INTERVAL_MS);

}());
