const TOAST_CONTAINER_ID = "__admin_toast_container";
const TOAST_STYLE_ID = "__admin_toast_styles";
const DEFAULT_DURATION = 3200;

function ensureToastStyles() {
    if (document.getElementById(TOAST_STYLE_ID)) return;

    const style = document.createElement("style");
    style.id = TOAST_STYLE_ID;
    style.textContent = `
        #${TOAST_CONTAINER_ID} {
            position: fixed;
            top: 16px;
            right: 16px;
            z-index: 12000;
            display: flex;
            flex-direction: column;
            gap: 12px;
            pointer-events: none;
            max-width: min(420px, calc(100vw - 24px));
        }

        .admin-toast {
            --toast-accent: #16a34a;
            pointer-events: auto;
            position: relative;
            overflow: hidden;
            border-radius: 14px;
            border: 1px solid var(--border-soft, rgba(15, 23, 42, 0.1));
            background: color-mix(
                in srgb,
                var(--bg-surface, #ffffff) 95%,
                var(--toast-accent) 5%
            );
            color: var(--text-primary, #0f172a);
            backdrop-filter: blur(6px);
            box-shadow:
                0 14px 34px rgba(15, 23, 42, 0.16),
                0 4px 10px rgba(15, 23, 42, 0.08);
            transform: translateY(-8px) scale(0.985);
            opacity: 0;
            transition: transform 180ms ease, opacity 180ms ease;
        }

        .admin-toast.is-visible {
            transform: translateY(0) scale(1);
            opacity: 1;
        }

        .admin-toast__accent {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(
                180deg,
                var(--toast-accent),
                color-mix(in srgb, var(--toast-accent) 70%, #000000 30%)
            );
        }

        .admin-toast__body {
            padding: 12px 12px 13px 14px;
            display: grid;
            grid-template-columns: 22px 1fr 22px;
            align-items: center;
            gap: 11px;
        }

        .admin-toast__icon {
            width: 22px;
            height: 22px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 22px;
            font-size: 13px;
            font-weight: 700;
            line-height: 1;
            color: var(--toast-accent);
            background: color-mix(in srgb, var(--toast-accent) 14%, transparent);
            border: 1px solid color-mix(in srgb, var(--toast-accent) 35%, transparent);
        }

        .admin-toast__message {
            font-size: 13.5px;
            line-height: 1.35;
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        .admin-toast__close {
            appearance: none;
            border: 0;
            background: transparent;
            color: var(--text-muted, #64748b);
            width: 22px;
            height: 22px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            font-size: 15px;
            transition:
                background-color 140ms ease,
                color 140ms ease;
        }

        .admin-toast__close:hover {
            background: color-mix(in srgb, var(--toast-accent) 12%, transparent);
            color: var(--text-primary, #0f172a);
        }

        .admin-toast__progress {
            position: absolute;
            left: 4px;
            bottom: 0;
            width: calc(100% - 4px);
            height: 2px;
            background: linear-gradient(
                90deg,
                color-mix(in srgb, var(--toast-accent) 75%, transparent),
                color-mix(in srgb, var(--toast-accent) 38%, transparent)
            );
            transform-origin: left center;
            animation-name: admin-toast-progress;
            animation-timing-function: linear;
            animation-fill-mode: forwards;
        }

        .admin-toast--success {
            --toast-accent: #16a34a;
        }

        .admin-toast--error {
            --toast-accent: #dc2626;
        }

        @keyframes admin-toast-progress {
            from {
                transform: scaleX(1);
            }
            to {
                transform: scaleX(0);
            }
        }

        html[data-theme="dark"] .admin-toast {
            border-color: color-mix(in srgb, var(--border-soft, #334155) 88%, transparent);
            background: color-mix(in srgb, var(--bg-surface, #0f172a) 90%, var(--toast-accent) 10%);
            box-shadow: 0 20px 42px rgba(2, 6, 23, 0.56);
            color: var(--text-primary, #e2e8f0);
        }

        html[data-theme="dark"] .admin-toast__close {
            color: var(--text-muted, #94a3b8);
        }

        html[data-theme="dark"] .admin-toast__close:hover {
            color: var(--text-primary, #e2e8f0);
            background: color-mix(in srgb, var(--toast-accent) 16%, transparent);
        }

        @media (max-width: 640px) {
            #${TOAST_CONTAINER_ID} {
                left: 12px;
                right: 12px;
                top: 12px;
                max-width: none;
            }
        }
    `;

    document.head.appendChild(style);
}

function getToastContainer() {
    let container = document.getElementById(TOAST_CONTAINER_ID);

    if (!container) {
        container = document.createElement("div");
        container.id = TOAST_CONTAINER_ID;
        document.body.appendChild(container);
    }

    return container;
}

export function showAdminToast(message, type = "success", options = {}) {
    try {
        ensureToastStyles();
        const container = getToastContainer();
        const duration = Number(options.duration || DEFAULT_DURATION);
        const tone = type === "error" ? "error" : "success";
        const icon = tone === "error" ? "!" : "✓";

        const toast = document.createElement("div");
        toast.className = `admin-toast admin-toast--${tone}`;

        const accent = document.createElement("div");
        accent.className = "admin-toast__accent";

        const body = document.createElement("div");
        body.className = "admin-toast__body";

        const iconEl = document.createElement("span");
        iconEl.className = "admin-toast__icon";
        iconEl.textContent = icon;

        const msg = document.createElement("div");
        msg.className = "admin-toast__message";
        msg.textContent = String(message || "Done");

        const closeButton = document.createElement("button");
        closeButton.type = "button";
        closeButton.className = "admin-toast__close";
        closeButton.setAttribute("aria-label", "Dismiss notification");
        closeButton.textContent = "×";

        const progress = document.createElement("div");
        progress.className = "admin-toast__progress";
        progress.style.animationDuration = `${duration}ms`;

        body.appendChild(iconEl);
        body.appendChild(msg);
        body.appendChild(closeButton);
        toast.appendChild(accent);
        toast.appendChild(body);
        toast.appendChild(progress);
        container.appendChild(toast);

        requestAnimationFrame(() => {
            toast.classList.add("is-visible");
        });

        let isClosing = false;
        const closeToast = () => {
            if (isClosing) return;
            isClosing = true;
            toast.classList.remove("is-visible");
            window.setTimeout(() => {
                toast.remove();
            }, 180);
        };

        const hideTimer = window.setTimeout(closeToast, duration);
        closeButton.addEventListener("click", () => {
            window.clearTimeout(hideTimer);
            closeToast();
        });
    } catch {
        // eslint-disable-next-line no-alert
        alert(message);
    }
}
