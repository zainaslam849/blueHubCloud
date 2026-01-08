<script setup lang="ts">
import type { RouteLocationRaw } from "vue-router";

type ActionVariant = "primary" | "secondary" | "ghost";

type Action = {
    label: string;
    to?: RouteLocationRaw;
    href?: string;
    variant?: ActionVariant;
};

type Props = {
    title: string;
    description: string;
    tone?: "neutral" | "danger";
    primaryAction?: Action;
    secondaryAction?: Action;
};

const props = withDefaults(defineProps<Props>(), {
    tone: "neutral",
});

defineEmits<{
    (e: "primary"): void;
    (e: "secondary"): void;
}>();

function btnClass(variant: ActionVariant | undefined) {
    if (variant === "primary") return "btn btn--primary";
    if (variant === "secondary") return "btn btn--secondary";
    return "btn btn--ghost";
}
</script>

<template>
    <section
        class="panel"
        :class="{ danger: props.tone === 'danger' }"
        role="status"
    >
        <div class="illus" aria-hidden="true">
            <slot name="illustration" />
        </div>

        <div class="content">
            <div class="title">{{ props.title }}</div>
            <div class="desc">{{ props.description }}</div>

            <div class="actions">
                <template v-if="props.primaryAction">
                    <router-link
                        v-if="props.primaryAction.to"
                        :to="props.primaryAction.to"
                        :class="
                            btnClass(props.primaryAction.variant ?? 'primary')
                        "
                    >
                        {{ props.primaryAction.label }}
                    </router-link>
                    <a
                        v-else-if="props.primaryAction.href"
                        :href="props.primaryAction.href"
                        target="_blank"
                        rel="noreferrer"
                        :class="
                            btnClass(props.primaryAction.variant ?? 'primary')
                        "
                    >
                        {{ props.primaryAction.label }}
                    </a>
                    <button
                        v-else
                        type="button"
                        :class="
                            btnClass(props.primaryAction.variant ?? 'primary')
                        "
                        @click="$emit('primary')"
                    >
                        {{ props.primaryAction.label }}
                    </button>
                </template>

                <template v-if="props.secondaryAction">
                    <router-link
                        v-if="props.secondaryAction.to"
                        :to="props.secondaryAction.to"
                        :class="
                            btnClass(props.secondaryAction.variant ?? 'ghost')
                        "
                    >
                        {{ props.secondaryAction.label }}
                    </router-link>
                    <a
                        v-else-if="props.secondaryAction.href"
                        :href="props.secondaryAction.href"
                        target="_blank"
                        rel="noreferrer"
                        :class="
                            btnClass(props.secondaryAction.variant ?? 'ghost')
                        "
                    >
                        {{ props.secondaryAction.label }}
                    </a>
                    <button
                        v-else
                        type="button"
                        :class="
                            btnClass(props.secondaryAction.variant ?? 'ghost')
                        "
                        @click="$emit('secondary')"
                    >
                        {{ props.secondaryAction.label }}
                    </button>
                </template>
            </div>
        </div>
    </section>
</template>

<style scoped>
.panel {
    border-radius: var(--radius-lg);
    border: 1px dashed var(--border);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
    padding: var(--space-8);

    display: grid;
    grid-template-columns: 140px 1fr;
    gap: var(--space-6);
    align-items: center;
}

.panel.danger {
    border-style: solid;
    border-color: color-mix(in srgb, var(--color-error) 30%, var(--border));
    background: color-mix(in srgb, var(--color-error) 3%, var(--surface));
}

.illus {
    width: 140px;
    height: 100px;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    background: var(--surface-2);

    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: color-mix(in srgb, var(--color-primary) 55%, var(--color-muted));
}

.panel.danger .illus {
    color: color-mix(in srgb, var(--color-error) 60%, var(--color-muted));
}

.content {
    min-width: 0;
}

.title {
    font-weight: 900;
    letter-spacing: 0.2px;
}

.desc {
    margin-top: 6px;
    color: var(--color-muted);
    max-width: 60ch;
}

.actions {
    margin-top: var(--space-4);
    display: flex;
    gap: var(--space-3);
    flex-wrap: wrap;
}

@media (max-width: 960px) {
    .panel {
        grid-template-columns: 1fr;
        text-align: center;
        justify-items: center;
    }

    .desc {
        max-width: 52ch;
    }
}
</style>
