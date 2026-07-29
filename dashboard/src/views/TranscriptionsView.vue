<script setup lang="ts">
import { onMounted, ref } from "vue";
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import { userApi } from "../api/user";

type Row = {
    id: number;
    pbx_unique_id: string;
    from: string | null;
    to: string | null;
    direction: string | null;
    status: string;
    duration_seconds: number;
    started_at: string | null;
    ai_summary: string | null;
    transcript_text: string | null;
};

type Meta = { currentPage: number; lastPage: number; perPage: number; total: number };

const loading = ref(true);
const rows = ref<Row[]>([]);
const meta = ref<Meta>({ currentPage: 1, lastPage: 1, perPage: 25, total: 0 });
const error = ref<string | null>(null);
const search = ref("");
const page = ref(1);
const emptyMessage = ref<string | null>(null);
const expandedId = ref<number | null>(null);

function fmtDate(iso: string | null): string {
    if (!iso) return "—";
    return new Date(iso).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
}

async function load(p = page.value) {
    loading.value = true;
    error.value = null;
    try {
        const res = await userApi.get<{ data: Row[]; meta: Meta; message?: string }>("/transcriptions", {
            page: p,
            per_page: 25,
            search: search.value || undefined,
        });
        rows.value = res.data.data ?? [];
        meta.value = res.data.meta ?? meta.value;
        emptyMessage.value = res.data.message ?? null;
        page.value = p;
    } catch (e) {
        error.value = e instanceof Error ? e.message : "Failed to load transcriptions.";
    } finally {
        loading.value = false;
    }
}

function toggle(id: number) {
    expandedId.value = expandedId.value === id ? null : id;
}

let searchTimer: ReturnType<typeof setTimeout> | null = null;
function onSearch() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(1), 350);
}

onMounted(() => load());
</script>

<template>
    <div>
        <PageHeader title="Transcriptions" description="Call transcripts and AI summaries for your company." />

        <Card>
            <div class="toolbar">
                <input
                    v-model="search"
                    class="searchInput"
                    type="search"
                    placeholder="Search transcript, number, call ID…"
                    @input="onSearch"
                />
                <span class="count" v-if="!loading">{{ meta.total }} total</span>
            </div>

            <div v-if="error" class="banner banner--err">{{ error }}</div>

            <div v-if="loading" class="state">Loading transcriptions…</div>
            <div v-else-if="rows.length === 0" class="state">
                {{ emptyMessage || "No transcriptions found yet." }}
            </div>

            <ul v-else class="list">
                <li v-for="r in rows" :key="r.id" class="item">
                    <button class="itemHead" @click="toggle(r.id)">
                        <div class="itemHead__main">
                            <span class="itemHead__num">{{ r.from || "—" }} → {{ r.to || "—" }}</span>
                            <span class="itemHead__meta">{{ fmtDate(r.started_at) }}</span>
                        </div>
                        <span class="chev" :class="{ open: expandedId === r.id }">▾</span>
                    </button>

                    <div v-if="expandedId === r.id" class="itemBody">
                        <div v-if="r.ai_summary" class="block">
                            <h4 class="block__title">AI Summary</h4>
                            <p class="block__summary">{{ r.ai_summary }}</p>
                        </div>
                        <div class="block">
                            <h4 class="block__title">Transcript</h4>
                            <pre class="block__transcript">{{ r.transcript_text }}</pre>
                        </div>
                    </div>
                </li>
            </ul>

            <div v-if="!loading && meta.lastPage > 1" class="pager">
                <button class="pagerBtn" :disabled="meta.currentPage <= 1" @click="load(meta.currentPage - 1)">Prev</button>
                <span class="pagerInfo">{{ meta.currentPage }} / {{ meta.lastPage }}</span>
                <button class="pagerBtn" :disabled="meta.currentPage >= meta.lastPage" @click="load(meta.currentPage + 1)">Next</button>
            </div>
        </Card>
    </div>
</template>

<style scoped>
.toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
.searchInput {
    flex: 1; min-width: 200px; height: 38px; padding: 0 12px;
    border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: inherit;
}
.count { font-size: 0.85rem; opacity: 0.65; }
.banner { padding: 10px 14px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 12px; }
.banner--err { background: color-mix(in srgb, #e53e3e 12%, transparent); border: 1px solid #e53e3e; color: #e53e3e; }
.state { padding: 36px 0; text-align: center; opacity: 0.6; font-size: 0.9rem; }

.list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.item { border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.itemHead {
    width: 100%; display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 12px 14px; background: var(--surface); border: none; cursor: pointer; color: inherit; text-align: left;
}
.itemHead:hover { background: var(--surface-2); }
.itemHead__main { display: flex; flex-direction: column; gap: 2px; }
.itemHead__num { font-weight: 600; font-size: 0.9rem; }
.itemHead__meta { font-size: 0.78rem; opacity: 0.6; }
.chev { transition: transform 0.2s; opacity: 0.6; }
.chev.open { transform: rotate(180deg); }

.itemBody { padding: 14px; border-top: 1px solid var(--border); background: var(--surface-2); display: flex; flex-direction: column; gap: 14px; }
.block__title { margin: 0 0 6px; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.6; }
.block__summary { margin: 0; font-size: 0.9rem; line-height: 1.5; }
.block__transcript {
    margin: 0; white-space: pre-wrap; word-break: break-word;
    font-family: inherit; font-size: 0.85rem; line-height: 1.6; opacity: 0.9;
    max-height: 360px; overflow-y: auto;
}

.pager { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 14px; }
.pagerBtn { height: 32px; padding: 0 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); cursor: pointer; font-size: 0.85rem; }
.pagerBtn:disabled { opacity: 0.4; cursor: not-allowed; }
.pagerInfo { font-size: 0.85rem; opacity: 0.7; }
</style>
