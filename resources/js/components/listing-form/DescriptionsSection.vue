<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import { ref } from 'vue';
import type { ListingForm } from '@/components/listing-form/types';

defineProps<{ form: ListingForm }>();

// Per-description-section source view (the escape hatch for cleaning up
// pasted junk by hand; the server sanitises on save regardless).
const sourceMode = ref<Record<number, boolean>>({});

// Toolbar for the restricted description subset (listing-schema.md
// §Conventions: p, br, ul, ol, li, strong, em, h3, h4, a[href]) — the
// UEditor is headless without one.
const editorToolbarItems = [
    [
        { kind: 'undo', icon: 'i-lucide-undo-2', tooltip: { text: 'Undo' } },
        { kind: 'redo', icon: 'i-lucide-redo-2', tooltip: { text: 'Redo' } },
    ],
    [
        {
            kind: 'mark',
            mark: 'bold',
            icon: 'i-lucide-bold',
            tooltip: { text: 'Bold' },
        },
        {
            kind: 'mark',
            mark: 'italic',
            icon: 'i-lucide-italic',
            tooltip: { text: 'Italic' },
        },
    ],
    [
        {
            kind: 'paragraph',
            icon: 'i-lucide-pilcrow',
            tooltip: { text: 'Paragraph' },
        },
        {
            kind: 'heading',
            level: 3,
            icon: 'i-lucide-heading-3',
            tooltip: { text: 'Heading' },
        },
        {
            kind: 'heading',
            level: 4,
            icon: 'i-lucide-heading-4',
            tooltip: { text: 'Subheading' },
        },
    ],
    [
        {
            kind: 'bulletList',
            icon: 'i-lucide-list',
            tooltip: { text: 'Bullet list' },
        },
        {
            kind: 'orderedList',
            icon: 'i-lucide-list-ordered',
            tooltip: { text: 'Numbered list' },
        },
    ],
    [
        {
            kind: 'link',
            icon: 'i-lucide-link',
            tooltip: { text: 'Link (https only, never your own site)' },
        },
        {
            kind: 'clearFormatting',
            icon: 'i-lucide-remove-formatting',
            tooltip: { text: 'Clear formatting' },
        },
    ],
];
</script>

<template>
    <div class="space-y-3 rounded-lg border border-default p-4">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium">Descriptions</p>
            <UButton
                color="neutral"
                variant="outline"
                size="xs"
                icon="i-lucide-plus"
                label="Add section"
                @click="form.descriptions.push({ section: '', content: '' })"
            />
        </div>
        <p class="text-xs text-muted">
            Restricted HTML only: p, br, ul, ol, li, strong, em, h3, h4, https
            links. Use the well-known labels
            <code>overview</code> and <code>highlights</code> where they apply.
        </p>
        <div
            v-for="(description, i) in form.descriptions"
            :key="`desc-${i}`"
            class="space-y-2 rounded-md bg-elevated/50 p-3"
        >
            <div class="flex items-end gap-2">
                <UFormField label="Section" class="flex-1">
                    <UInput
                        v-model="description.section"
                        class="w-full"
                        placeholder="overview"
                    />
                </UFormField>
                <UButton
                    color="error"
                    variant="ghost"
                    icon="i-lucide-trash-2"
                    aria-label="Remove section"
                    @click="form.descriptions.splice(i, 1)"
                />
            </div>
            <UFormField
                label="Content"
                :error="form.errors[`descriptions.${i}.content`]"
            >
                <UTextarea
                    v-if="sourceMode[i]"
                    v-model="description.content"
                    class="w-full font-mono text-xs"
                    :rows="12"
                    autoresize
                />
                <UEditor
                    v-else
                    v-model="description.content"
                    content-class="min-h-32 px-3 py-2"
                    :starter-kit="{
                        heading: { levels: [3, 4] },
                        codeBlock: false,
                        code: false,
                        blockquote: false,
                        horizontalRule: false,
                        strike: false,
                        underline: false,
                    }"
                    class="w-full rounded-md border border-default"
                >
                    <template #default="{ editor }">
                        <UEditorToolbar
                            v-if="editor"
                            :editor="editor"
                            :items="editorToolbarItems"
                            class="border-b border-default p-1"
                        />
                    </template>
                </UEditor>
            </UFormField>
            <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                :icon="sourceMode[i] ? 'i-lucide-eye' : 'i-lucide-code'"
                :label="sourceMode[i] ? 'Visual editor' : 'View source'"
                @click="sourceMode[i] = !sourceMode[i]"
            />
        </div>
        <UButton
            color="neutral"
            variant="outline"
            size="xs"
            icon="i-lucide-plus"
            label="Add section"
            @click="form.descriptions.push({ section: '', content: '' })"
        />
    </div>
</template>
