<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { addPartner, index, refresh } from '@/routes/node-directory';

type DirectoryEntry = {
    domain: string;
    name: string;
    website: string;
    country: string;
    listed_at: string;
    status: 'self' | 'partner' | 'available';
};

type ListingRequest = {
    token: string;
    signature: string;
};

const props = defineProps<{
    domain: string | null;
    listed: boolean;
    listingRequests: Record<string, ListingRequest> | null;
    issueFormUrl: string;
    directory: DirectoryEntry[];
    fetchedAt: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Node directory',
                href: index(),
            },
        ],
    },
});

const busy = ref(false);

const refreshDirectory = () =>
    router.post(
        refresh.url(),
        {},
        {
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );

const addDirectoryPartner = (domain: string) =>
    router.post(
        addPartner.url(),
        { domain },
        {
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );

const listingActions = [
    { value: 'list', label: 'List this node' },
    { value: 'delist', label: 'Delist this node' },
    { value: 'amend', label: 'Amend the entry' },
];

const listingAction = ref(props.listed ? 'amend' : 'list');

const listingRequest = computed(
    () => props.listingRequests?.[listingAction.value] ?? null,
);

const search = ref('');
const country = ref('all');

const countries = computed(() => [
    { value: 'all', label: 'All countries' },
    ...[...new Set(props.directory.map((entry) => entry.country))]
        .sort()
        .map((code) => ({ value: code, label: code })),
]);

const filteredDirectory = computed(() =>
    props.directory.filter((entry) => {
        const term = search.value.trim().toLowerCase();

        return (
            (country.value === 'all' || entry.country === country.value) &&
            (term === '' ||
                entry.name.toLowerCase().includes(term) ||
                entry.domain.includes(term))
        );
    }),
);
</script>

<template>
    <Head title="Node directory" />

    <div class="mx-auto w-full max-w-4xl space-y-8 px-4 py-6">
        <Heading
            title="Node directory"
            description="An opt-in, advisory phonebook of OpenYacht nodes that asked to be listed. Being in it grants nothing; being absent costs nothing — everything a partner verifies comes from each node's own domain."
        />

        <section class="space-y-3 rounded-lg border border-default p-4">
            <div class="flex items-center gap-2">
                <h2 class="font-semibold">Your listing</h2>
                <UBadge
                    v-if="domain"
                    :color="listed ? 'success' : 'neutral'"
                    variant="subtle"
                    size="sm"
                    :label="listed ? 'Listed' : 'Not listed'"
                />
            </div>

            <p v-if="!domain" class="text-sm text-muted">
                This node has no identity domain yet — set
                <code>OPENYACHT_DOMAIN</code> and run
                <code>php artisan openyacht:install</code> before requesting a
                listing.
            </p>

            <template v-else>
                <p class="text-sm text-muted">
                    <code>{{ domain }}</code>
                    {{
                        listed
                            ? 'appears in the directory (as of the last refresh below).'
                            : 'is not in the directory. Listing is optional in both directions and never affects federation.'
                    }}
                    Listing, delisting, and amending happen by a request signed
                    with this node's federation key, so only you can change your
                    entry.
                </p>

                <p v-if="!listingRequests" class="text-sm text-muted">
                    No active federation key — run
                    <code>php artisan openyacht:install</code> to generate one,
                    then reload this page.
                </p>

                <template v-else>
                    <USelect
                        v-model="listingAction"
                        :items="listingActions"
                        class="w-48"
                        aria-label="Listing action"
                    />

                    <dl
                        v-if="listingRequest"
                        class="space-y-2 rounded-md bg-elevated/50 p-3 text-xs"
                    >
                        <div>
                            <dt class="font-medium text-muted">Token</dt>
                            <dd>
                                <code
                                    class="break-all select-all"
                                    data-test="listing-token"
                                    >{{ listingRequest.token }}</code
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-muted">Signature</dt>
                            <dd>
                                <code class="break-all select-all">{{
                                    listingRequest.signature
                                }}</code>
                            </dd>
                        </div>
                    </dl>

                    <p class="text-sm text-muted">
                        Valid for ±30 days. Paste both lines into the
                        <a
                            :href="issueFormUrl"
                            target="_blank"
                            rel="noopener"
                            class="underline"
                            >node-listing issue form</a
                        >; a new listing also asks for your display name,
                        website, and country.
                    </p>
                </template>
            </template>
        </section>

        <section class="space-y-3">
            <div class="flex items-start justify-between gap-4">
                <h2 class="font-semibold">Find partners</h2>
                <UButton
                    icon="i-lucide-refresh-cw"
                    variant="outline"
                    color="neutral"
                    label="Refresh"
                    :loading="busy"
                    @click="refreshDirectory"
                />
            </div>

            <p class="text-sm text-muted">
                An entry is not an endorsement and proves nothing — adding a
                node from here verifies it from its own domain, exactly like
                typing the domain by hand.
            </p>

            <div
                v-if="directory.length"
                class="flex flex-col gap-2 sm:flex-row"
            >
                <UInput
                    v-model="search"
                    icon="i-lucide-search"
                    placeholder="Search by name or domain"
                    class="w-full sm:max-w-xs"
                    aria-label="Search the directory"
                />
                <USelect
                    v-model="country"
                    :items="countries"
                    class="w-40"
                    aria-label="Filter by country"
                />
            </div>

            <div
                v-if="filteredDirectory.length"
                class="overflow-x-auto rounded-lg border border-default"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b border-default text-left text-muted"
                        >
                            <th class="p-3 font-medium">Brokerage</th>
                            <th class="p-3 font-medium">Node domain</th>
                            <th class="p-3 font-medium">Country</th>
                            <th class="p-3 font-medium">Listed</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="entry in filteredDirectory"
                            :key="entry.domain"
                            class="border-b border-default last:border-b-0"
                        >
                            <td class="p-3">
                                <a
                                    :href="entry.website"
                                    target="_blank"
                                    rel="noopener"
                                    class="font-medium hover:underline"
                                    >{{ entry.name }}</a
                                >
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ entry.domain }}
                            </td>
                            <td class="p-3">{{ entry.country }}</td>
                            <td class="p-3">{{ entry.listed_at }}</td>
                            <td class="p-3 text-right">
                                <em
                                    v-if="entry.status === 'self'"
                                    class="text-muted"
                                    >This node</em
                                >
                                <em
                                    v-else-if="entry.status === 'partner'"
                                    class="text-muted"
                                    >Already a partner</em
                                >
                                <UButton
                                    v-else
                                    size="xs"
                                    variant="outline"
                                    label="Add as partner"
                                    :disabled="busy"
                                    @click="addDirectoryPartner(entry.domain)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else-if="directory.length" class="text-sm text-muted">
                No entries match the current filter.
            </p>

            <p v-else class="text-sm text-muted">
                The directory is currently empty — the network is young, and
                listing is optional in both directions.
            </p>

            <p v-if="fetchedAt" class="text-xs text-muted">
                Last refreshed {{ fetchedAt }} from openyacht.org.
            </p>
        </section>
    </div>
</template>
