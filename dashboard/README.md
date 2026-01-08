# Vue 3 + TypeScript + Vite

This template should help get you started developing with Vue 3 and TypeScript in Vite. The template uses Vue 3 `<script setup>` SFCs, check out the [script setup docs](https://v3.vuejs.org/api/sfc-script-setup.html#sfc-script-setup) to learn more.

Learn more about the recommended Project Setup and IDE Support in the [Vue Docs TypeScript Guide](https://vuejs.org/guide/typescript/overview.html#project-setup).

## Local setup

Install dependencies:

```bash
npm install
```

Create your local env file:

```bash
copy .env.example .env
```

Set the Laravel API URL in `.env`:

```env
VITE_API_BASE_URL=http://localhost:8000
```

Run the dev server:

```bash
npm run dev
```

## App structure

-   `src/router` — Vue Router + auth guard
-   `src/composables/useAuth.ts` — minimal token-based auth state
-   `src/api` — Axios client + API calls (uses `VITE_API_BASE_URL`)
-   `src/views` — pages (`Login`, protected `Dashboard`)
