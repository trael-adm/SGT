/**
 * SOMA — JavaScript Global e Utilitários (Trael PCP)
 */

// Sistema Global de Toasts
const Toast = {
    getContainer() {
        let container = document.getElementById('soma-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'soma-toast-container';
            container.style.position = 'fixed';
            container.style.bottom = '24px';
            container.style.right = '24px';
            container.style.zIndex = '9999';
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.gap = '10px';
            container.style.maxWidth = '380px';
            container.style.pointerEvents = 'none';
            document.body.appendChild(container);
        }
        return container;
    },

    show(mensagem, tipo = 'info', duracao = 4000) {
        const container = this.getContainer();
        const toast = document.createElement('div');
        toast.style.pointerEvents = 'auto';
        toast.style.padding = '12px 16px';
        toast.style.borderRadius = '8px';
        toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
        toast.style.fontSize = '13px';
        toast.style.fontWeight = '500';
        toast.style.display = 'flex';
        toast.style.alignItems = 'center';
        toast.style.justifyContent = 'space-between';
        toast.style.gap = '12px';
        toast.style.transition = 'all 0.2s ease';

        if (tipo === 'success') {
            toast.style.background = '#14532d';
            toast.style.color = '#ffffff';
            toast.style.borderLeft = '4px solid #4ade80';
        } else if (tipo === 'error') {
            toast.style.background = '#7f1d1d';
            toast.style.color = '#ffffff';
            toast.style.borderLeft = '4px solid #f87171';
        } else if (tipo === 'warning') {
            toast.style.background = '#78350f';
            toast.style.color = '#ffffff';
            toast.style.borderLeft = '4px solid #fcd34d';
        } else {
            toast.style.background = '#1e293b';
            toast.style.color = '#ffffff';
            toast.style.borderLeft = '4px solid #60a5fa';
        }

        toast.innerHTML = `
            <span>${mensagem}</span>
            <button type="button" style="background:none;border:none;cursor:pointer;color:rgba(255,255,255,0.7);font-size:18px;line-height:1;" onclick="this.parentElement.remove()">&times;</button>
        `;

        container.appendChild(toast);

        if (duracao > 0) {
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(10px)';
                    setTimeout(() => toast.remove(), 250);
                }
            }, duracao);
        }
    },

    success(msg, duracao) { this.show(msg, 'success', duracao); },
    error(msg, duracao) { this.show(msg, 'error', duracao); },
    warning(msg, duracao) { this.show(msg, 'warning', duracao); },
    info(msg, duracao) { this.show(msg, 'info', duracao); }
};

// Modais
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}
