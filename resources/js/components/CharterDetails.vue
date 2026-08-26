<script setup lang="ts">
import { computed } from 'vue';

/**
 * Renders a synced listing's charter block verbatim (copy fidelity — the
 * consumer never dedupes or synthesises). All keys are present on the
 * wire by LS-1; empty lists simply hide their card.
 */

type Rate = {
    season: string | null;
    rate_type: string | null;
    amount_min: string | null;
    amount_max: string | null;
    currency: string | null;
    contract_terms: string | null;
    apa_percent: number | null;
    vat_percent: number | null;
    valid_from: string | null;
    valid_to: string | null;
};

type OperatingArea = {
    name: string;
    slug: string | null;
    season: string | null;
};

type CrewMember = {
    role: string;
    name: string | null;
    nationality: string | null;
    bio: string | null;
    photo_url: string | null;
    tba: boolean;
};

const props = defineProps<{
    charter: Record<string, unknown> | null;
}>();

const rates = computed(() => (props.charter?.rates ?? []) as Rate[]);
const areas = computed(
    () => (props.charter?.operating_areas ?? []) as OperatingArea[],
);
const crew = computed(() => (props.charter?.crew ?? []) as CrewMember[]);
const summerBasePort = computed(
    () => (props.charter?.summer_base_port ?? null) as string | null,
);
const winterBasePort = computed(
    () => (props.charter?.winter_base_port ?? null) as string | null,
);

const hasContent = computed(
    () =>
        rates.value.length > 0 ||
        areas.value.length > 0 ||
        crew.value.length > 0 ||
        summerBasePort.value !== null ||
        winterBasePort.value !== null,
);

const money = (amount: string | null, currency: string | null): string => {
    if (!amount || !Number.isFinite(Number(amount))) {
        return '—';
    }

    const formatted = Number(amount).toLocaleString();

    return currency ? `${currency} ${formatted}` : formatted;
};

const rateAmounts = (rate: Rate): string => {
    const low = money(rate.amount_min, rate.currency);
    const high = money(rate.amount_max, rate.currency);

    return high !== low && high !== '—' ? `${low} – ${high}` : low;
};

const validity = (rate: Rate): string | null =>
    rate.valid_from || rate.valid_to
        ? `${rate.valid_from ?? '…'} → ${rate.valid_to ?? '…'}`
        : null;
</script>

<template>
    <section v-if="hasContent" class="space-y-4">
        <h3 class="text-lg font-semibold">Charter</h3>

        <div
            v-if="rates.length"
            class="overflow-x-auto rounded-lg border border-default"
        >
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-default bg-elevated/50">
                        <th class="p-3 text-left font-medium">Season</th>
                        <th class="p-3 text-left font-medium">Rate</th>
                        <th class="p-3 text-left font-medium">Terms</th>
                        <th class="p-3 text-left font-medium">APA</th>
                        <th class="p-3 text-left font-medium">VAT</th>
                        <th class="p-3 text-left font-medium">Validity</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(rate, i) in rates"
                        :key="i"
                        class="border-b border-default last:border-b-0"
                    >
                        <td class="p-3 capitalize">{{ rate.season }}</td>
                        <td class="p-3 font-medium">
                            {{ rateAmounts(rate) }}
                            <span class="font-normal text-muted">
                                /
                                {{
                                    rate.rate_type === 'daily' ? 'day' : 'week'
                                }}
                            </span>
                        </td>
                        <td class="p-3">{{ rate.contract_terms ?? '—' }}</td>
                        <td class="p-3">
                            {{
                                rate.apa_percent !== null
                                    ? `${rate.apa_percent}%`
                                    : '—'
                            }}
                        </td>
                        <td class="p-3">
                            {{
                                rate.vat_percent !== null
                                    ? `${rate.vat_percent}%`
                                    : '—'
                            }}
                        </td>
                        <td class="p-3 text-muted">
                            {{ validity(rate) ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <UCard v-if="areas.length || summerBasePort || winterBasePort">
                <template #header>
                    <h4 class="font-semibold">Operating areas</h4>
                </template>
                <div class="space-y-3 text-sm">
                    <div v-if="areas.length" class="flex flex-wrap gap-1.5">
                        <UBadge
                            v-for="(area, i) in areas"
                            :key="i"
                            color="neutral"
                            variant="subtle"
                            :label="
                                area.season
                                    ? `${area.name} (${area.season})`
                                    : area.name
                            "
                        />
                    </div>
                    <dl class="space-y-1">
                        <div
                            v-if="summerBasePort"
                            class="flex justify-between gap-4"
                        >
                            <dt class="text-muted">Summer base port</dt>
                            <dd class="font-medium">{{ summerBasePort }}</dd>
                        </div>
                        <div
                            v-if="winterBasePort"
                            class="flex justify-between gap-4"
                        >
                            <dt class="text-muted">Winter base port</dt>
                            <dd class="font-medium">{{ winterBasePort }}</dd>
                        </div>
                    </dl>
                </div>
            </UCard>

            <UCard v-if="crew.length">
                <template #header>
                    <h4 class="font-semibold">Crew</h4>
                </template>
                <ul class="space-y-3 text-sm">
                    <li v-for="(member, i) in crew" :key="i">
                        <p class="font-medium">
                            {{ member.role }}
                            <span v-if="member.tba" class="text-muted">
                                — to be announced
                            </span>
                            <template v-else-if="member.name">
                                — {{ member.name }}
                            </template>
                        </p>
                        <p v-if="member.nationality" class="text-muted">
                            {{ member.nationality }}
                        </p>
                        <p v-if="member.bio" class="text-muted">
                            {{ member.bio }}
                        </p>
                    </li>
                </ul>
            </UCard>
        </div>
    </section>
</template>
