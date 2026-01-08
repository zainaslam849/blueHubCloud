import { inject, provide, readonly, ref, type Ref } from "vue";

export type ToastVariant = "success" | "error";

export type Toast = {
    id: string;
    variant: ToastVariant;
    title: string;
    message?: string;
    createdAt: number;
};

type ToastInput = {
    variant: ToastVariant;
    title: string;
    message?: string;
    durationMs?: number;
};

type ToastApi = {
    toasts: Readonly<Ref<readonly Toast[]>>;
    push: (t: ToastInput) => string;
    remove: (id: string) => void;
    clear: () => void;
};

const ToastKey: unique symbol = Symbol("ToastKey");

function uid() {
    return `t_${Date.now().toString(36)}_${Math.random()
        .toString(36)
        .slice(2, 8)}`;
}

export function provideToasts() {
    const toasts = ref<Toast[]>([]);
    const timers = new Map<string, number>();

    function remove(id: string) {
        const t = timers.get(id);
        if (t) {
            window.clearTimeout(t);
            timers.delete(id);
        }
        toasts.value = toasts.value.filter((x) => x.id !== id);
    }

    function clear() {
        Array.from(timers.keys()).forEach(remove);
        toasts.value = [];
    }

    function push(input: ToastInput) {
        const id = uid();

        const toast: Toast = {
            id,
            variant: input.variant,
            title: input.title,
            message: input.message,
            createdAt: Date.now(),
        };

        toasts.value = [toast, ...toasts.value].slice(0, 4);

        const duration = input.durationMs ?? 4000;
        if (duration > 0) {
            const timerId = window.setTimeout(() => remove(id), duration);
            timers.set(id, timerId);
        }

        return id;
    }

    const api: ToastApi = {
        toasts: readonly(toasts),
        push,
        remove,
        clear,
    };

    provide(ToastKey, api);

    return api;
}

export function useToasts(): ToastApi {
    const api = inject<ToastApi>(ToastKey);
    if (!api) {
        throw new Error(
            "useToasts() must be used after provideToasts() is called."
        );
    }
    return api;
}
