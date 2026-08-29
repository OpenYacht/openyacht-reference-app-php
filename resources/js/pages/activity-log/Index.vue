<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { index, prune } from '@/routes/activity-log';
import { update as updateRetention } from '@/routes/activity-log/retention';

type ActivityRow = {
    id: number;
    log_name: string | null;
    event: string | null;
    description: string;
    causer: string | null;
    subject_type: string | null;
    properties: Record<string, unknown> | null;
    created_at: string | null;
    created_at_iso: string | null;
};

type Paginator = {
    data: ActivityRow[];
    prev_page_url: string | null;
    next_page_url: string | null;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    activities: Paginator;
    filters: {
        log_name: string;
        event: string;
        causer_id: string;
        date_from: string;
        date_to: string;
    };
    filterOptions: {
        logNames: string[];
        events: string[];
        causers: { id: number; name: string }[];
    };
    retention: { days: number; total: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Activity log', href: index() }],
    },
});

// reka selects forbid empty-string values; 'all' is the no-filter sentinel.
const state = reactive({
    log_name: props.filters.log_name || 'all',
    event: props.filters.event || 'all',
    causer_id: props.filters.causer_id || 'all',
    date_from: props.filters.date_from,
    date_to: props.filters.date_to,
});

const logNameItems = computed(() => [
    { label: 'All channels', value: 'all' },
    ...props.filterOptions.logNames.map((name) => ({
        label: name,
        value: name,
    })),
]);

const eventItems = computed(() => [
    { label: 'All events', value: 'all' },
    ...props.filterOptions.events.map((event) => ({
        label: event.replace(/_/g, ' '),
        value: event,
    })),
]);

const causerItems = computed(() => [
    { label: 'All users', value: 'all' },
    ...props.filterOptions.causers.map((causer) => ({
        label: causer.name,
        value: String(causer.id),
    })),
]);

let timer: ReturnType<typeof setTimeout> | null = null;

watch(state, () => {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => {
        const params: Record<string, string> = {};

        if (state.log_name !== 'all') {
            params.log_name = state.log_name;
        }

        if (state.event !== 'all') {
            params.event = state.event;
        }

        if (state.causer_id !== 'all') {
            params.causer_id = state.causer_id;
        }

        if (state.date_from) {
            params.date_from = state.date_from;
        }

        if (state.date_to) {
            params.date_to = state.date_to;
        }

        router.get(window.location.pathname, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

const expanded = ref<number | null>(null);

const toggle = (id: number) => {
    expanded.value = expanded.value === id ? null : id;
};

const eventColor = (
    event: string | null,
): 'success' | 'error' | 'info' | 'neutral' => {
    if (event === null) {
        return 'neutral';
    }

    if (/added|approved|created|imported|shared/.test(event)) {
        return 'success';
    }

    if (/blocked|revoked|removed|deleted|failed|withdrawn|hidden/.test(event)) {
        return 'error';
    }

    if (/changed|updated|repinned|refreshed|rotated/.test(event)) {
        return 'info';
    }

    return 'neutral';
};

const retentionForm = useForm({ retention_days: props.retention.days });

const saveRetention = () => retentionForm.submit(updateRetention());

const runCleanup = () => router.post(prune.url(), {}, { preserveScroll: true });
</script>

<template>
    <Head title="Activity log" />

    <div class="mx-auto w-full max-w-6xl space-y-6 px-4 py-6">
        <Heading
            title="Activity log"
            description="The audit trail of everything this node records — partners, keys, sharing, roles, and users."
        />

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
            <USelectMenu
                v-model="state.log_name"
                :items="logNameItems"
                value-key="value"
                class="w-full lg:w-48"
                aria-label="Channel"
            />
            <USelectMenu
                v-model="state.event"
                :items="eventItems"
                value-key="value"
                class="w-full lg:w-56"
                aria-label="Event"
            />
            <USelectMenu
                v-model="state.causer_id"
                :items="causerItems"
                value-key="value"
                class="w-full lg:w-48"
                aria-label="User"
            />
            <div class="flex items-center gap-2">
                <UInput
                    v-model="state.date_from"
                    type="date"
                    class="w-full lg:w-40"
                    aria-label="From date"
                />
                <span class="text-sm text-muted">–</span>
                <UInput
                    v-model="state.date_to"
                    type="date"
                    class="w-full lg:w-40"
                    aria-label="To date"
                />
            </div>
        </div>

        <div
            v-if="activities.data.length > 0"
            class="overflow-hidden rounded-lg border border-default"
        >
            <div
                v-for="entry in activities.data"
                :key="entry.id"
                class="border-b border-default p-4 last:border-b-0"
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <UBadge
                                v-if="entry.log_name"
                                color="neutral"
                                variant="subtle"
                                size="sm"
                                :label="entry.log_name"
                            />
                            <UBadge
                                v-if="entry.event"
                                :color="eventColor(entry.event)"
                                variant="soft"
                                size="sm"
                                :label="entry.event.replace(/_/g, ' ')"
                            />
                            <span
                                v-if="entry.subject_type"
                                class="text-xs text-muted"
                                >{{ entry.subject_type }}</span
                            >
                        </div>
                        <p class="truncate font-medium">
                            {{ entry.description }}
                        </p>
                        <p class="text-xs text-muted">
                            {{ entry.causer ?? 'System' }} ·
                            <time
                                :datetime="entry.created_at_iso ?? undefined"
                                >{{ entry.created_at }}</time
                            >
                        </p>
                    </div>

                    <UButton
                        v-if="entry.properties"
                        color="neutral"
                        variant="ghost"
                        size="xs"
                        :icon="
                            expanded === entry.id
                                ? 'i-lucide-chevron-up'
                                : 'i-lucide-chevron-down'
                        "
                        label="Details"
                        @click="toggle(entry.id)"
                    />
                </div>

                <pre
                    v-if="entry.properties && expanded === entry.id"
                    class="mt-3 overflow-x-auto rounded-md bg-elevated p-3 text-xs"
                    >{{ JSON.stringify(entry.properties, null, 2) }}</pre>
            </div>
        </div>

        <p v-else class="text-sm text-muted">
            No activity found. Try widening the filters, or check back once the
            node has recorded some events.
        </p>

        <div
            v-if="activities.total > 0"
            class="flex items-center justify-between"
        >
            <p class="text-sm text-muted">
                Showing {{ activities.from }}–{{ activities.to }} of
                {{ activities.total }}
            </p>
            <div class="flex items-center gap-2">
                <UButton
                    color="neutral"
                    variant="outline"
                    size="sm"
                    icon="i-lucide-chevron-left"
                    label="Previous"
                    :disabled="!activities.prev_page_url"
                    @click="
                        activities.prev_page_url &&
                        router.visit(activities.prev_page_url, {
                            preserveScroll: true,
                        })
                    "
                />
                <UButton
                    color="neutral"
                    variant="outline"
                    size="sm"
                    trailing-icon="i-lucide-chevron-right"
                    label="Next"
                    :disabled="!activities.next_page_url"
                    @click="
                        activities.next_page_url &&
                        router.visit(activities.next_page_url, {
                            preserveScroll: true,
                        })
                    "
                />
            </div>
        </div>

        <div
            v-if="$page.props.auth.canManageSettings"
            class="rounded-lg border border-default p-4"
        >
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
            >
                <div class="space-y-1">
                    <h2 class="font-medium">Cleanup</h2>
                    <p class="max-w-xl text-sm text-muted">
                        A daily task removes only the per-run sync summaries
                        older than the retention window. The audit trail —
                        partnerships, shares, imports, and withdrawals — is
                        always kept, since it is the record of when a listing
                        was displayed. Set it to <strong>0</strong> to keep the
                        summaries too. {{ retention.total }} entries are stored
                        now.
                    </p>
                </div>
                <div class="flex items-end gap-2">
                    <UFormField
                        label="Retention (days)"
                        :error="retentionForm.errors.retention_days"
                    >
                        <UInput
                            v-model.number="retentionForm.retention_days"
                            type="number"
                            min="0"
                            max="3650"
                            class="w-32"
                        />
                    </UFormField>
                    <UButton
                        label="Save"
                        :loading="retentionForm.processing"
                        @click="saveRetention"
                    />
                    <UButton
                        color="neutral"
                        variant="outline"
                        label="Run cleanup now"
                        @click="runCleanup"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
