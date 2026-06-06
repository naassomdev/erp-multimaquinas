/**
 * FullCalendar 6 — calendário de agendamentos / OSs.
 *
 * Uso:
 *   <div data-fullcalendar
 *        data-events-url="/api/calendario/eventos"
 *        data-initial-view="dayGridMonth"></div>
 */
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin   from '@fullcalendar/daygrid';
import timeGridPlugin  from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import ptBrLocale from '@fullcalendar/core/locales/pt-br';

export function init() {
    document.querySelectorAll('[data-fullcalendar]').forEach((el) => {
        if (el._calendar) return;

        const cal = new Calendar(el, {
            plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
            locale: ptBrLocale,
            initialView: el.dataset.initialView ?? 'dayGridMonth',
            firstDay:    1,                  // segunda
            headerToolbar: {
                left:   'prev,next today',
                center: 'title',
                right:  'dayGridMonth,timeGridWeek,timeGridDay',
            },
            buttonText: {
                today: 'Hoje', month: 'Mês', week: 'Semana', day: 'Dia',
            },
            events: el.dataset.eventsUrl || [],
            navLinks:    true,
            editable:    el.dataset.editable === 'true',
            selectable:  el.dataset.selectable === 'true',
            height: 'auto',
        });
        cal.render();
        el._calendar = cal;
    });
}
