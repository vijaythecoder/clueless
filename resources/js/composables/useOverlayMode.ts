import { onMounted, ref } from 'vue';
import { updateTheme } from './useAppearance';

export function useOverlayMode() {
    const isOverlayMode = ref(false);
    const isSupported = ref(false);
    let previousTheme: string | null = null;

    const checkSupport = async () => {
        try {
            const result = await window.macPermissions?.overlayMode.checkSupport();
            isSupported.value = Boolean(result?.supported);
        } catch (error) {
            console.error('Unable to check overlay mode support:', error);
            isSupported.value = false;
        }
        return isSupported.value;
    };

    const toggleOverlayMode = async () => {
        if (!isSupported.value || !window.macPermissions) return false;

        const enabled = !isOverlayMode.value;
        try {
            const alwaysOnTop = await window.macPermissions.overlayMode.setAlwaysOnTop(enabled, enabled ? 'floating' : undefined);
            if (alwaysOnTop.success === false) return false;

            await window.macPermissions.overlayMode.setBackgroundColor(enabled ? '#00000000' : '#f5f7fa');
            await window.macPermissions.overlayMode.setOpacity(enabled ? 0.8 : 1);

            if (enabled) {
                previousTheme = localStorage.getItem('appearance');
                updateTheme('dark');
            } else {
                updateTheme((previousTheme as 'light' | 'dark' | 'system' | null) ?? 'system');
            }

            isOverlayMode.value = enabled;
            localStorage.setItem('overlayModeEnabled', String(enabled));
            window.dispatchEvent(new CustomEvent('overlayModeChanged', { detail: { enabled } }));
            return true;
        } catch (error) {
            console.error('Unable to toggle overlay mode:', error);
            return false;
        }
    };

    const enableOverlayMode = async () => isOverlayMode.value || toggleOverlayMode();
    const disableOverlayMode = async () => !isOverlayMode.value || toggleOverlayMode();

    onMounted(() => {
        globalThis.setTimeout(async () => {
            if (!(await checkSupport())) return;
            if (localStorage.getItem('overlayModeEnabled') === 'true') {
                await enableOverlayMode();
            } else {
                await window.macPermissions?.overlayMode.setBackgroundColor('#f5f7fa');
            }
        }, 100);
    });

    return {
        isOverlayMode,
        isSupported,
        toggleOverlayMode,
        enableOverlayMode,
        disableOverlayMode,
        setWindowOpacity: () => false,
        checkSupport,
    };
}
