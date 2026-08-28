<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<!--
    OpenYacht mark, "Code Hoist" (openyacht docs/branding.md): Oscar over
    Yankee spells O·Y in the International Code of Signals. Inlined from
    brand master openyacht-mark.svg; the halyard swaps ink/ivory with the
    theme (matching the master and reversed variants), the flag colours are
    the letters and must never be recoloured.
-->
<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 46 100"
        :class="className"
        v-bind="$attrs"
    >
        <defs>
            <clipPath id="oy-yankee">
                <rect x="8" y="56" width="38" height="38" />
            </clipPath>
        </defs>
        <rect
            x="0"
            y="0"
            width="4"
            height="100"
            class="fill-ink dark:fill-ivory"
        />
        <polygon points="8,6 46,6 8,44" fill="#c30b2d" />
        <polygon points="46,6 46,44 8,44" fill="#fecf16" />
        <g clip-path="url(#oy-yankee)">
            <rect x="8" y="56" width="38" height="38" fill="#fecf16" />
            <g stroke="#c30b2d" stroke-width="5.7">
                <line x1="0" y1="16" x2="54" y2="70" />
                <line x1="0" y1="32" x2="54" y2="86" />
                <line x1="0" y1="48" x2="54" y2="102" />
                <line x1="0" y1="64" x2="54" y2="118" />
                <line x1="0" y1="80" x2="54" y2="134" />
            </g>
        </g>
    </svg>
</template>
