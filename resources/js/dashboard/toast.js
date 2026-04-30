// Tiny event bus the topbar/Alpine layout subscribes to. Anything in the app
// can call `toast.success('Saved')` and the layout's <template> renders it.

let nextId = 1;
const items = [];
const listeners = new Set();

function emit() {
    listeners.forEach((fn) => fn(items.slice()));
}

function push(message, kind = 'info', timeout = 3500) {
    const t = { id: nextId++, message, kind };
    items.push(t);
    emit();
    if (timeout) setTimeout(() => dismiss(t.id), timeout);
    return t.id;
}

function dismiss(id) {
    const idx = items.findIndex((t) => t.id === id);
    if (idx >= 0) {
        items.splice(idx, 1);
        emit();
    }
}

function subscribe(fn) {
    listeners.add(fn);
    fn(items.slice());
    return () => listeners.delete(fn);
}

export const toast = {
    info: (m) => push(m, 'info'),
    success: (m) => push(m, 'success'),
    error: (m) => push(m, 'error', 5000),
    dismiss,
    subscribe,
};

// Alpine data factory exposed on window.toastBus
export function toastBus() {
    return {
        items: [],
        init() {
            toast.subscribe((next) => {
                this.items = next;
            });
        },
        dismiss(id) {
            toast.dismiss(id);
        },
    };
}
