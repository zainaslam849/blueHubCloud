/**
 * Returns the input URL only when it parses to a safe http(s) URL whose
 * origin is allow-listed; otherwise returns undefined.
 *
 * Used to guard `:href` bindings against `javascript:` / `data:` / unknown
 * external hosts that may be injected through report payloads or settings.
 */

const ALWAYS_ALLOWED_PROTOCOLS = new Set(["http:", "https:"]);

/**
 * Build the default allow-list at runtime:
 *  - Same origin as the dashboard (window.location.origin)
 *  - VITE_API_BASE_URL origin (if defined)
 *  - VITE_REPORTS_DOWNLOAD_HOSTS comma-separated extra hosts (optional)
 */
function defaultAllowedHosts(): Set<string> {
    const allowed = new Set<string>();

    try {
        if (typeof window !== "undefined" && window.location?.origin) {
            allowed.add(new URL(window.location.origin).host);
        }
    } catch {
        // ignore
    }

    const apiBase = import.meta.env.VITE_API_BASE_URL as string | undefined;
    if (apiBase) {
        try {
            allowed.add(new URL(apiBase).host);
        } catch {
            // ignore malformed env value
        }
    }

    const extra = import.meta.env.VITE_REPORTS_DOWNLOAD_HOSTS as
        | string
        | undefined;
    if (extra) {
        for (const host of extra.split(",")) {
            const trimmed = host.trim();
            if (trimmed) {
                allowed.add(trimmed);
            }
        }
    }

    return allowed;
}

let cachedAllowed: Set<string> | null = null;
function getAllowedHosts(): Set<string> {
    if (!cachedAllowed) {
        cachedAllowed = defaultAllowedHosts();
    }
    return cachedAllowed;
}

export function safeUrl(value: unknown): string | undefined {
    if (typeof value !== "string") return undefined;
    const trimmed = value.trim();
    if (trimmed === "") return undefined;

    // Allow purely relative URLs (same-origin by definition).
    if (trimmed.startsWith("/") && !trimmed.startsWith("//")) {
        return trimmed;
    }
    if (trimmed.startsWith("#")) {
        return trimmed;
    }

    let parsed: URL;
    try {
        parsed = new URL(trimmed);
    } catch {
        return undefined;
    }

    if (!ALWAYS_ALLOWED_PROTOCOLS.has(parsed.protocol)) {
        return undefined;
    }

    const allowed = getAllowedHosts();
    if (!allowed.has(parsed.host)) {
        return undefined;
    }

    return parsed.toString();
}
