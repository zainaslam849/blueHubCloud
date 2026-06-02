import { createApp } from "vue";
import "./style.css";
import App from "./App.vue";
import { router } from "./router";
import { auth } from "./composables/useAuth";

// Restore session state before mounting so the router guard has the correct
// auth state on the very first navigation (handles hard page refreshes).
auth.checkSession().finally(() => {
    createApp(App).use(router).mount("#app");
});
