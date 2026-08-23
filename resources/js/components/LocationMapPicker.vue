<script lang="ts">
export type MapConfig = {
    provider: 'openstreetmap' | 'mapbox';
    mapbox_token: string | null;
};
</script>

<script setup lang="ts">
/*
 * Data-entry aid for listing coordinates: plots the current lat/lon so an
 * obviously wrong position is visible at a glance, and lets the user pick a
 * point (click or drag) or search an address. Nothing on the wire depends on
 * this — it only writes back through the pick event.
 *
 * Two engines behind one interface: Mapbox GL (3D globe, vector styles,
 * Mapbox geocoding) when a token is configured, Leaflet + OpenStreetMap
 * otherwise. Both are loaded on demand so neither ships in the main bundle.
 */
import type * as Leaflet from 'leaflet';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    lat: number | null;
    lon: number | null;
    map: MapConfig;
}>();

const emit = defineEmits<{ pick: [lat: number, lon: number] }>();

const container = ref<HTMLDivElement>();

const round = (value: number) => Math.round(value * 1e6) / 1e6;

const emitPick = (lat: number, lon: number) => {
    emit('pick', round(lat), round(lon));
};

type Engine = {
    placeMarker(lat: number, lon: number): void;
    markerPosition(): { lat: number; lon: number } | null;
    setView(lat: number, lon: number, minZoom: number): void;
    destroy(): void;
};

let engine: Engine | null = null;

const useMapbox = props.map.provider === 'mapbox' && !!props.map.mapbox_token;

const initMapbox = async (): Promise<Engine> => {
    const [{ default: mapboxgl }] = await Promise.all([
        import('mapbox-gl'),
        import('mapbox-gl/dist/mapbox-gl.css'),
    ]);

    mapboxgl.accessToken = props.map.mapbox_token!;

    const hasCoordinates = props.lat !== null && props.lon !== null;
    const map = new mapboxgl.Map({
        container: container.value!,
        style: 'mapbox://styles/mapbox/standard',
        projection: 'globe',
        center: hasCoordinates ? [props.lon!, props.lat!] : [0, 20],
        zoom: hasCoordinates ? 9 : 1,
    });
    map.addControl(new mapboxgl.NavigationControl());
    map.scrollZoom.disable();

    let marker: InstanceType<typeof mapboxgl.Marker> | null = null;

    const placeMarker = (lat: number, lon: number) => {
        if (marker) {
            marker.setLngLat([lon, lat]);

            return;
        }

        marker = new mapboxgl.Marker({ draggable: true })
            .setLngLat([lon, lat])
            .addTo(map);
        marker.on('dragend', () => {
            const position = marker!.getLngLat();
            emitPick(position.lat, position.lng);
        });
    };

    map.on('click', (event) => {
        placeMarker(event.lngLat.lat, event.lngLat.lng);
        emitPick(event.lngLat.lat, event.lngLat.lng);
    });

    if (hasCoordinates) {
        placeMarker(props.lat!, props.lon!);
    }

    return {
        placeMarker,
        markerPosition: () => {
            const position = marker?.getLngLat();

            return position ? { lat: position.lat, lon: position.lng } : null;
        },
        setView: (lat, lon, minZoom) =>
            map.easeTo({
                center: [lon, lat],
                zoom: Math.max(map.getZoom(), minZoom),
            }),
        destroy: () => map.remove(),
    };
};

const initLeaflet = async (): Promise<Engine> => {
    const [{ default: L }] = await Promise.all([
        import('leaflet'),
        import('leaflet/dist/leaflet.css'),
    ]);

    // Leaflet's default marker is a bundled PNG whose URL does not survive
    // the build; an inline SVG pin sidesteps assets entirely and follows the
    // app's primary colour.
    const pinIcon = L.divIcon({
        className: '',
        html: '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="42" viewBox="0 0 30 42"><path d="M15 1C7.3 1 1 7.3 1 15c0 10.5 14 26 14 26s14-15.5 14-26C29 7.3 22.7 1 15 1z" fill="var(--ui-primary)" stroke="white" stroke-width="1.5"/><circle cx="15" cy="15" r="5" fill="white"/></svg>',
        iconSize: [30, 42],
        iconAnchor: [15, 42],
    });

    const map = L.map(container.value!, { scrollWheelZoom: false });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution:
            '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);

    if (props.lat !== null && props.lon !== null) {
        map.setView([props.lat, props.lon], 10);
    } else {
        map.setView([25, 0], 1);
    }

    let marker: Leaflet.Marker | null = null;

    const placeMarker = (lat: number, lon: number) => {
        if (marker) {
            marker.setLatLng([lat, lon]);

            return;
        }

        marker = L.marker([lat, lon], { draggable: true, icon: pinIcon }).addTo(
            map,
        );
        marker.on('dragend', () => {
            const position = marker!.getLatLng();
            emitPick(position.lat, position.lng);
        });
    };

    map.on('click', (event: Leaflet.LeafletMouseEvent) => {
        placeMarker(event.latlng.lat, event.latlng.lng);
        emitPick(event.latlng.lat, event.latlng.lng);
    });

    if (props.lat !== null && props.lon !== null) {
        placeMarker(props.lat, props.lon);
    }

    return {
        placeMarker,
        markerPosition: () => {
            const position = marker?.getLatLng();

            return position ? { lat: position.lat, lon: position.lng } : null;
        },
        setView: (lat, lon, minZoom) =>
            map.setView([lat, lon], Math.max(map.getZoom(), minZoom)),
        destroy: () => map.remove(),
    };
};

onMounted(async () => {
    engine = await (useMapbox ? initMapbox() : initLeaflet());
});

onBeforeUnmount(() => {
    engine?.destroy();
    engine = null;
});

// Manual edits to the lat/lon inputs move the marker; ignore echoes of our
// own pick events (marker already there within rounding).
watch(
    () => [props.lat, props.lon] as const,
    ([lat, lon]) => {
        if (!engine || lat === null || lon === null) {
            return;
        }

        const current = engine.markerPosition();

        if (
            current &&
            Math.abs(current.lat - lat) < 1e-6 &&
            Math.abs(current.lon - lon) < 1e-6
        ) {
            return;
        }

        engine.placeMarker(lat, lon);
        engine.setView(lat, lon, 8);
    },
);

// Address search — Mapbox geocoding when configured, Nominatim otherwise.
type GeocodeResult = { label: string; lat: number; lon: number };

const query = ref('');
const searching = ref(false);
const results = ref<GeocodeResult[]>([]);
const searchError = ref<string | null>(null);

const search = async () => {
    const term = query.value.trim();

    if (!term || searching.value) {
        return;
    }

    searching.value = true;
    searchError.value = null;
    results.value = [];

    try {
        if (useMapbox) {
            // The Search Box API, not classic geocoding — the older
            // geocoding endpoints are address-centric and miss POIs like
            // marinas ("Port Hercule"), which are exactly what gets
            // searched here.
            const response = await fetch(
                `https://api.mapbox.com/search/searchbox/v1/forward?q=${encodeURIComponent(term)}&limit=5&access_token=${props.map.mapbox_token}`,
            );
            const data = (await response.json()) as {
                features: {
                    properties: {
                        name: string;
                        place_formatted: string | null;
                    };
                    geometry: { coordinates: [number, number] };
                }[];
            };
            results.value = data.features.map((feature) => ({
                label: feature.properties.place_formatted
                    ? `${feature.properties.name}, ${feature.properties.place_formatted}`
                    : feature.properties.name,
                lat: feature.geometry.coordinates[1],
                lon: feature.geometry.coordinates[0],
            }));
        } else {
            const response = await fetch(
                `https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&q=${encodeURIComponent(term)}`,
            );
            const data = (await response.json()) as {
                display_name: string;
                lat: string;
                lon: string;
            }[];
            results.value = data.map((row) => ({
                label: row.display_name,
                lat: Number(row.lat),
                lon: Number(row.lon),
            }));
        }

        if (results.value.length === 0) {
            searchError.value = 'No places found.';
        }
    } catch {
        searchError.value = 'The geocoding service could not be reached.';
    } finally {
        searching.value = false;
    }
};

const choose = (result: GeocodeResult) => {
    results.value = [];
    query.value = result.label;
    engine?.placeMarker(result.lat, result.lon);
    engine?.setView(result.lat, result.lon, 12);
    emitPick(result.lat, result.lon);
};
</script>

<template>
    <div class="space-y-2">
        <div class="flex gap-2">
            <UInput
                v-model="query"
                class="flex-1"
                placeholder="Search a place or address…"
                icon="i-lucide-search"
                @keydown.enter.prevent="search"
            />
            <UButton
                color="neutral"
                variant="subtle"
                :loading="searching"
                label="Find"
                @click="search"
            />
        </div>
        <ul
            v-if="results.length"
            class="divide-y divide-default rounded-md border border-default text-sm"
        >
            <li v-for="result in results" :key="result.label">
                <button
                    type="button"
                    class="w-full px-3 py-2 text-left hover:bg-elevated"
                    @click="choose(result)"
                >
                    {{ result.label }}
                </button>
            </li>
        </ul>
        <p v-if="searchError" class="text-sm text-muted">{{ searchError }}</p>
        <div
            ref="container"
            class="h-96 w-full overflow-hidden rounded-md border border-default"
        />
        <p class="text-xs text-muted">
            Click the map or drag the marker to set the coordinates — check the
            pin matches where the yacht actually lies.
        </p>
    </div>
</template>
