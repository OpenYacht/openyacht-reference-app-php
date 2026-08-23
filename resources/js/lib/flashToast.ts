import { router } from '@inertiajs/vue3';
import { useToast } from '@nuxt/ui/composables';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        useToast().add({
            title: data.message,
            color: data.type,
        });
    });
}
