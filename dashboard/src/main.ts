import { createApp } from "vue";
import "./style.css";
import App from "./App.vue";
import { router } from "./router";
import { auth } from "./composables/useAuth";

// A deploy replaces every hashed asset filename. A tab that's had the app
// open since before that deploy still holds the OLD filenames in memory, so
// a lazy route import (sidebar nav, etc.) tries to fetch a chunk that no
// longer exists on the server and silently fails — the click just does
// nothing. Vite fires this event for exactly that case; reload once to pick
// up the current build. Guarded (cleared a few seconds after a successful
// load) so a genuinely broken deploy can't reload-loop forever.
window.addEventListener("vite:preloadError", () => {
    const key = "vite-stale-reload";
    if (!sessionStorage.getItem(key)) {
        sessionStorage.setItem(key, "1");
        window.location.reload();
    }
});
setTimeout(() => sessionStorage.removeItem("vite-stale-reload"), 10_000);

// Restore session state before mounting so the router guard has the correct
// auth state on the very first navigation (handles hard page refreshes).
auth.checkSession().finally(() => {
    createApp(App).use(router).mount("#app");
});
