/**
 * UI Components for Multimáquinas Design System
 * Handles Toasts, Modals, and Drawers dynamically.
 */

const UI = {
    /**
     * Show a Toast notification
     * @param {string} message 
     * @param {string} type 'success' | 'error' | 'info'
     */
    toast(message, type = 'success') {
        const icons = {
            success: '✅',
            error: '❌',
            info: 'ℹ️'
        };
        const bgColors = {
            success: 'bg-emerald-50 dark:bg-emerald-500/10 border-emerald-500',
            error: 'bg-rose-50 dark:bg-rose-500/10 border-rose-500',
            info: 'bg-sky-50 dark:bg-sky-500/10 border-sky-500'
        };
        const textColors = {
            success: 'text-emerald-800 dark:text-emerald-400',
            error: 'text-rose-800 dark:text-rose-400',
            info: 'text-sky-800 dark:text-sky-400'
        };

        const toastHtml = `
            <div class="flash-alert ${bgColors[type]} border-l-4 p-4 rounded-r-lg shadow-lg flex justify-between items-start transition-all duration-300 transform translate-x-full opacity-0" style="margin-top: 10px; width: 350px; pointer-events: auto;">
                <div class="flex">
                    <span class="mr-3">${icons[type]}</span>
                    <p class="${textColors[type]} text-sm font-medium">${message}</p>
                </div>
                <button class="ml-4 text-slate-400 hover:text-slate-600 focus:outline-none" onclick="this.parentElement.style.opacity='0'; setTimeout(()=>this.parentElement.remove(), 300)">✕</button>
            </div>
        `;

        let container = document.getElementById('ui-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'ui-toast-container';
            container.className = 'fixed bottom-4 right-4 z-50 flex flex-col items-end space-y-2 pointer-events-none';
            document.body.appendChild(container);
        }

        const div = document.createElement('div');
        div.innerHTML = toastHtml.trim();
        const toastEl = div.firstChild;
        container.appendChild(toastEl);

        // Animate in
        requestAnimationFrame(() => {
            toastEl.classList.remove('translate-x-full', 'opacity-0');
        });

        // Auto remove
        setTimeout(() => {
            toastEl.style.opacity = '0';
            setTimeout(() => toastEl.remove(), 300);
        }, 5000);
    },

    /**
     * Show a sliding Drawer
     * @param {string} title 
     * @param {string|HTMLElement} content 
     */
    drawer(title, content) {
        const id = 'drawer-' + Date.now();
        const html = `
            <div id="${id}-overlay" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-40 transition-opacity duration-300 opacity-0 cursor-pointer"></div>
            <div id="${id}" class="fixed inset-y-0 right-0 w-full md:w-1/2 lg:w-1/3 bg-white dark:bg-slate-900 shadow-2xl z-50 transform translate-x-full transition-transform duration-300 flex flex-col border-l border-slate-200 dark:border-slate-700">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white">${title}</h3>
                    <button id="${id}-close" class="text-slate-400 hover:text-rose-500 transition-colors p-2">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-6" id="${id}-content">
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', html);
        
        const contentContainer = document.getElementById(`${id}-content`);
        if (typeof content === 'string') {
            contentContainer.innerHTML = content;
        } else {
            contentContainer.appendChild(content);
        }

        const overlay = document.getElementById(`${id}-overlay`);
        const drawer = document.getElementById(id);
        const closeBtn = document.getElementById(`${id}-close`);

        const close = () => {
            drawer.classList.add('translate-x-full');
            overlay.classList.add('opacity-0');
            setTimeout(() => {
                drawer.remove();
                overlay.remove();
            }, 300);
        };

        closeBtn.addEventListener('click', close);
        overlay.addEventListener('click', close);

        // Animate in
        requestAnimationFrame(() => {
            overlay.classList.remove('opacity-0');
            drawer.classList.remove('translate-x-full');
        });
    },

    /**
     * Show a Modal dialog
     * @param {string} title 
     * @param {string|HTMLElement} content 
     */
    modal(title, content) {
        const id = 'modal-' + Date.now();
        const html = `
            <div id="${id}-overlay" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0 cursor-pointer">
                <div id="${id}" class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-lg transform scale-95 transition-all duration-300 cursor-auto border border-slate-200 dark:border-slate-700 flex flex-col max-h-[90vh]">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 rounded-t-xl">
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">${title}</h3>
                        <button id="${id}-close" class="text-slate-400 hover:text-rose-500 transition-colors p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>
                    <div class="p-6 overflow-y-auto" id="${id}-content">
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', html);
        
        const contentContainer = document.getElementById(`${id}-content`);
        if (typeof content === 'string') {
            contentContainer.innerHTML = content;
        } else {
            contentContainer.appendChild(content);
        }

        const overlay = document.getElementById(`${id}-overlay`);
        const modal = document.getElementById(id);
        const closeBtn = document.getElementById(`${id}-close`);

        const close = () => {
            overlay.classList.add('opacity-0');
            modal.classList.remove('scale-100');
            modal.classList.add('scale-95');
            setTimeout(() => {
                overlay.remove();
            }, 300);
        };

        closeBtn.addEventListener('click', close);
        overlay.addEventListener('mousedown', (e) => {
            if (e.target === overlay) close();
        });

        // Animate in
        requestAnimationFrame(() => {
            overlay.classList.remove('opacity-0');
            modal.classList.remove('scale-95');
            modal.classList.add('scale-100');
        });
    }
};

window.UI = UI;
