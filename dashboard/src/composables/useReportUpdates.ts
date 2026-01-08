import { onMounted, onUnmounted, ref } from "vue";
import type { WeeklyReportDetail, WeeklyReportSummary } from "../api/reports";

export type ReportUpdateMessage = {
    type: string;
    report?: Partial<WeeklyReportDetail> & { id: string };
};

export function useReportUpdates(
    onReportUpdate: (r: Partial<WeeklyReportDetail> & { id: string }) => void
) {
    const wsUrl = (import.meta as any).env?.VITE_WS_URL as string | undefined;

    const connected = ref(false);
    const supported = ref(Boolean(wsUrl));

    let socket: WebSocket | null = null;
    let reconnectTimer: number | null = null;
    let attempt = 0;

    function clearReconnect() {
        if (reconnectTimer !== null) {
            window.clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }
    }

    function close() {
        clearReconnect();
        connected.value = false;
        attempt = 0;

        if (socket) {
            socket.close();
            socket = null;
        }
    }

    function scheduleReconnect() {
        if (!wsUrl) return;
        clearReconnect();

        attempt += 1;
        const delay = Math.min(30_000, 1000 * Math.pow(2, attempt));
        reconnectTimer = window.setTimeout(connect, delay);
    }

    function connect() {
        if (!wsUrl) return;

        try {
            socket = new WebSocket(wsUrl);
        } catch {
            scheduleReconnect();
            return;
        }

        socket.addEventListener("open", () => {
            connected.value = true;
            attempt = 0;
        });

        socket.addEventListener("close", () => {
            connected.value = false;
            scheduleReconnect();
        });

        socket.addEventListener("error", () => {
            connected.value = false;
            scheduleReconnect();
        });

        socket.addEventListener("message", (evt) => {
            try {
                const msg = JSON.parse(String(evt.data)) as ReportUpdateMessage;
                if (msg?.type && msg.report?.id) {
                    onReportUpdate(msg.report);
                }
            } catch {
                // ignore malformed messages
            }
        });
    }

    onMounted(() => {
        if (wsUrl) connect();
    });

    onUnmounted(() => {
        close();
    });

    function applyUpdateToList(
        items: WeeklyReportSummary[],
        update: { id: string } & Partial<WeeklyReportSummary>
    ) {
        const idx = items.findIndex((x) => String(x.id) === String(update.id));
        if (idx === -1) return items;

        const next = items.slice();
        const existing = next[idx];
        if (!existing) return items;

        const merged: WeeklyReportSummary = { ...existing };

        (Object.keys(update) as Array<keyof typeof update>).forEach((key) => {
            const value = update[key];
            if (value !== undefined) {
                (merged as any)[key] = value;
            }
        });

        next[idx] = merged;
        return next;
    }

    return {
        supported,
        connected,
        connect,
        close,
        applyUpdateToList,
    };
}
