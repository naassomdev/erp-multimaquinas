/**
 * Chart.js 4 — gráficos do dashboard.
 *
 * Uso:
 *   <canvas data-chart="line" data-source="/api/dashboard/receita-mensal"></canvas>
 *
 * Opcionalmente, dados embutidos em data-config (JSON):
 *   <canvas data-chart="bar" data-config='{"labels":[...],"datasets":[...]}'></canvas>
 */
import {
    Chart, registerables,
} from 'chart.js';

Chart.register(...registerables);

// Defaults globais — alinha com o brand
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#4B5563';
Chart.defaults.borderColor = '#E5E7EB';

const CSS_BRAND = getComputedStyle(document.documentElement).getPropertyValue('--color-brand').trim() || '#FFD32C';

export async function init() {
    const canvases = document.querySelectorAll('canvas[data-chart]');
    for (const canvas of canvases) {
        if (canvas._chart) continue;

        const type = canvas.dataset.chart;
        let cfg;

        if (canvas.dataset.config) {
            try {
                cfg = JSON.parse(canvas.dataset.config);
            } catch (e) {
                console.error('[Chart] config inválido:', e);
                continue;
            }
        } else if (canvas.dataset.source) {
            const res = await fetch(canvas.dataset.source, { credentials: 'same-origin' });
            cfg = await res.json();
        } else {
            console.warn('[Chart] canvas sem data-config nem data-source');
            continue;
        }

        // Aplica cor do brand se nenhum dataset definiu cor
        cfg.datasets?.forEach((ds, i) => {
            if (i === 0 && !ds.borderColor)     ds.borderColor     = CSS_BRAND;
            if (i === 0 && !ds.backgroundColor) ds.backgroundColor = CSS_BRAND + '33';
        });

        canvas._chart = new Chart(canvas, {
            type,
            data: { labels: cfg.labels ?? [], datasets: cfg.datasets ?? [] },
            options: cfg.options ?? {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
            },
        });
    }
}
