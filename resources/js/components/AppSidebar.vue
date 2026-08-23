<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import type { DropdownMenuItem, NavigationMenuItem } from '@nuxt/ui';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useInitials } from '@/composables/useInitials';
import { toUrl } from '@/lib/utils';
import { dashboard, logout } from '@/routes';
import { index as apiKeysIndex } from '@/routes/api-keys';
import { index as importedYachtsIndex } from '@/routes/imported-yachts';
import { index as partnersIndex } from '@/routes/partners';
import { edit as editProfile } from '@/routes/profile';
import { index as rolesIndex } from '@/routes/roles';
import { index as syncedListingsIndex } from '@/routes/synced-listings';
import { index as usersIndex } from '@/routes/users';
import { index as yachtsIndex } from '@/routes/yachts';

const page = usePage();
const user = computed(() => page.props.auth.user);

const { getInitials } = useInitials();
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

const mainNavItems = computed<NavigationMenuItem[]>(() => [
    {
        label: 'Dashboard',
        icon: 'i-lucide-layout-grid',
        to: toUrl(dashboard()),
        active: isCurrentUrl(dashboard()),
    },
    ...(page.props.auth.canManageOwnYachts
        ? [
              {
                  label: 'Our yachts',
                  icon: 'i-lucide-sailboat',
                  to: toUrl(yachtsIndex()),
                  active: isCurrentOrParentUrl(yachtsIndex()),
              },
          ]
        : []),
    {
        label: 'Imported yachts',
        icon: 'i-lucide-ship',
        to: toUrl(importedYachtsIndex()),
        active: isCurrentUrl(importedYachtsIndex()),
    },
    {
        label: 'Synced listings',
        icon: 'i-lucide-refresh-cw',
        to: toUrl(syncedListingsIndex()),
        active: isCurrentUrl(syncedListingsIndex()),
    },
    ...(page.props.auth.canManageFederation
        ? [
              {
                  label: 'Partners',
                  icon: 'i-lucide-network',
                  to: toUrl(partnersIndex()),
                  active: isCurrentOrParentUrl(partnersIndex()),
              },
          ]
        : []),
    ...(page.props.auth.canManageSettings
        ? [
              {
                  label: 'API keys',
                  icon: 'i-lucide-key-round',
                  to: toUrl(apiKeysIndex()),
                  active: isCurrentUrl(apiKeysIndex()),
              },
          ]
        : []),
    ...(page.props.auth.canManageUsers
        ? [
              {
                  label: 'Users',
                  icon: 'i-lucide-users',
                  to: toUrl(usersIndex()),
                  active: isCurrentUrl(usersIndex()),
              },
              {
                  label: 'Roles',
                  icon: 'i-lucide-shield',
                  to: toUrl(rolesIndex()),
                  active: isCurrentUrl(rolesIndex()),
              },
          ]
        : []),
]);

const userMenuItems = computed<DropdownMenuItem[][]>(() => [
    [
        {
            type: 'label',
            label: user.value.name,
            avatar: {
                src: user.value.avatar ?? undefined,
                alt: user.value.name,
                text: getInitials(user.value.name),
            },
        },
    ],
    [
        {
            label: 'Settings',
            icon: 'i-lucide-settings',
            to: toUrl(editProfile()),
        },
    ],
    [
        {
            label: 'Log out',
            icon: 'i-lucide-log-out',
            onSelect: () => {
                router.flushAll();
                router.post(logout.url());
            },
        },
    ],
]);
</script>

<template>
    <UDashboardSidebar collapsible>
        <template #header="{ collapsed }">
            <Link
                :href="dashboard()"
                class="flex items-center gap-2 overflow-hidden"
            >
                <AppLogoIcon class="size-6 shrink-0 fill-current" />
                <span
                    v-if="!collapsed"
                    class="truncate text-sm font-semibold"
                    >{{ page.props.name }}</span
                >
            </Link>
        </template>

        <template #default="{ collapsed }">
            <UNavigationMenu
                :items="mainNavItems"
                :collapsed="collapsed"
                orientation="vertical"
            />
        </template>

        <template #footer="{ collapsed }">
            <UDropdownMenu
                :items="userMenuItems"
                :content="{ align: 'center', collisionPadding: 12 }"
            >
                <UButton
                    color="neutral"
                    variant="ghost"
                    block
                    :square="collapsed"
                    class="data-[state=open]:bg-elevated"
                    data-test="sidebar-menu-button"
                >
                    <UAvatar
                        :src="user.avatar ?? undefined"
                        :alt="user.name"
                        :text="getInitials(user.name)"
                        size="2xs"
                    />
                    <template v-if="!collapsed">
                        <span class="truncate">{{ user.name }}</span>
                        <UIcon
                            name="i-lucide-chevrons-up-down"
                            class="ms-auto size-4 shrink-0 text-muted"
                        />
                    </template>
                </UButton>
            </UDropdownMenu>
        </template>
    </UDashboardSidebar>
</template>
