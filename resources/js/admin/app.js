import { createApp } from "vue";

import AdminLayout from "../layouts/admin/AdminLayout.vue";
import router from "../router/admin";

import { initAdminTheme } from "./theme";

// A deploy renames every hashed asset. A tab open since before that deploy
// still holds the OLD chunk filenames, so a lazy route import (sidebar nav,
// etc.) tries to fetch a file that no longer exists on the server and fails
// silently — the click just does nothing. Vite fires this event for exactly
// that case; reload once to pick up the current build. Guarded (cleared a
// few seconds after a successful load) so a genuinely broken deploy can't
// reload-loop forever.
window.addEventListener("vite:preloadError", () => {
    const key = "vite-stale-reload";
    if (!sessionStorage.getItem(key)) {
        sessionStorage.setItem(key, "1");
        window.location.reload();
    }
});
setTimeout(() => sessionStorage.removeItem("vite-stale-reload"), 10_000);

const mountEl = document.getElementById("admin-app");

// Mount only on the admin Blade view
if (mountEl) {
    initAdminTheme();
    createApp(AdminLayout).use(router).mount(mountEl);
}
