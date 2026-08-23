<script setup lang="ts">
import { ref } from 'vue';
import type { Passkey } from '@/types/auth';

const props = defineProps<{
    passkey: Passkey;
}>();

const emit = defineEmits<{
    remove: [id: number, onError: () => void];
}>();

const showRemoveModal = ref(false);
const isDeleting = ref(false);

const handleDelete = () => {
    isDeleting.value = true;
    emit('remove', props.passkey.id, () => {
        isDeleting.value = false;
    });
};
</script>

<template>
    <div
        class="flex items-center justify-between border-b border-default p-4 last:border-b-0"
    >
        <div class="flex items-center gap-4">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-elevated"
            >
                <UIcon name="i-lucide-key-round" class="size-5 text-muted" />
            </div>
            <div class="space-y-1">
                <div class="flex items-center gap-2.5">
                    <p class="font-medium tracking-tight">{{ passkey.name }}</p>
                    <UBadge
                        v-if="passkey.authenticator"
                        color="neutral"
                        variant="subtle"
                        size="sm"
                        class="uppercase"
                    >
                        {{ passkey.authenticator }}
                    </UBadge>
                </div>
                <p class="text-sm text-muted">
                    Added {{ passkey.created_at_diff }}
                    <template v-if="passkey.last_used_at_diff">
                        <span class="mx-1 text-dimmed">/</span>
                        Last used {{ passkey.last_used_at_diff }}
                    </template>
                </p>
            </div>
        </div>

        <UButton
            color="error"
            variant="ghost"
            size="sm"
            icon="i-lucide-trash-2"
            aria-label="Remove"
            @click="showRemoveModal = true"
        />

        <UModal
            v-model:open="showRemoveModal"
            title="Remove passkey"
            :description="`Are you sure you want to remove the &quot;${passkey.name}&quot; passkey? You will no longer be able to use it to sign in.`"
        >
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton
                        color="neutral"
                        variant="soft"
                        label="Cancel"
                        @click="showRemoveModal = false"
                    />
                    <UButton
                        color="error"
                        :loading="isDeleting"
                        :label="isDeleting ? 'Removing...' : 'Remove passkey'"
                        @click="handleDelete"
                    />
                </div>
            </template>
        </UModal>
    </div>
</template>
