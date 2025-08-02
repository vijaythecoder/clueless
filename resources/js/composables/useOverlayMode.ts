import { onMounted, ref, watch } from 'vue';
import { updateTheme } from './useAppearance';

declare global {
    interface Window {
        remote?: {
            getCurrentWindow: () => {
                setAlwaysOnTop: (flag: boolean, level?: string) => void;
                setIgnoreMouseEvents: (ignore: boolean, options?: { forward?: boolean }) => void;
                setOpacity: (opacity: number) => void;
                getOpacity: () => number;
                setTransparent?: (transparent: boolean) => void;
                setBackgroundColor?: (color: string) => void;
            };
        };
        macPermissions?: {
            overlayMode: {
                checkSupport: () => Promise<{ supported: boolean; platform?: string; reason?: string; error?: string }>;
                setAlwaysOnTop: (flag: boolean, level?: string) => Promise<{ success: boolean; alwaysOnTop?: boolean; error?: string }>;
                setOpacity: (opacity: number) => Promise<{ success: boolean; opacity?: number; error?: string }>;
                getOpacity: () => Promise<{ success: boolean; opacity?: number; error?: string }>;
                setBackgroundColor: (color: string) => Promise<{ success: boolean; color?: string; error?: string }>;
            };
        };
    }
}

export function useOverlayMode() {
    const isOverlayMode = ref(false);
    const isSupported = ref(false);
    let previousTheme: string | null = null;

    // Check if overlay mode is supported
    const checkSupport = async () => {
        // First try IPC method
        if (window.macPermissions?.overlayMode) {
            try {
                const result = await window.macPermissions.overlayMode.checkSupport();
                if (result.supported) {
                    isSupported.value = true;
                    return true;
                }
            } catch (error) {
                console.error('Error checking overlay mode support via IPC:', error);
            }
        }
        
        // Fallback to window.remote for backward compatibility
        try {
            if (window.remote && typeof window.remote.getCurrentWindow === 'function') {
                const currentWindow = window.remote.getCurrentWindow();
                if (currentWindow && typeof currentWindow.setAlwaysOnTop === 'function' && typeof currentWindow.setIgnoreMouseEvents === 'function') {
                    isSupported.value = true;
                    return true;
                }
            }
        } catch {}

        isSupported.value = false;
        return false;
    };

    // Load saved preference
    const loadPreference = () => {
        const saved = localStorage.getItem('overlayModeEnabled');
        return saved === 'true';
    };

    // Save preference
    const savePreference = (enabled: boolean) => {
        localStorage.setItem('overlayModeEnabled', String(enabled));
    };

    // Toggle overlay mode
    const toggleOverlayMode = async () => {
        if (!isSupported.value) {
            return false;
        }

        const newState = !isOverlayMode.value;

        try {
            if (newState) {
                // Enable overlay mode
                if (window.macPermissions?.overlayMode) {
                    // Use IPC methods
                    await window.macPermissions.overlayMode.setAlwaysOnTop(true, 'floating');
                    await window.macPermissions.overlayMode.setBackgroundColor('#00000000');
                    await window.macPermissions.overlayMode.setOpacity(0.8);
                } else if (window.remote) {
                    // Fallback to window.remote
                    const currentWindow = window.remote.getCurrentWindow();
                    currentWindow.setAlwaysOnTop(true, 'floating');
                    if (typeof currentWindow.setBackgroundColor === 'function') {
                        currentWindow.setBackgroundColor('#00000000');
                    }
                    if (typeof currentWindow.setOpacity === 'function') {
                        currentWindow.setOpacity(0.8);
                    }
                }

                // Save current theme and force dark mode
                previousTheme = localStorage.getItem('appearance');
                updateTheme('dark');
            } else {
                // Disable overlay mode
                if (window.macPermissions?.overlayMode) {
                    // Use IPC methods
                    await window.macPermissions.overlayMode.setAlwaysOnTop(false);
                    await window.macPermissions.overlayMode.setBackgroundColor('#f5f7fa');
                    await window.macPermissions.overlayMode.setOpacity(1);
                } else if (window.remote) {
                    // Fallback to window.remote
                    const currentWindow = window.remote.getCurrentWindow();
                    currentWindow.setAlwaysOnTop(false);
                    if (typeof currentWindow.setBackgroundColor === 'function') {
                        currentWindow.setBackgroundColor('#f5f7fa');
                    }
                    if (typeof currentWindow.setOpacity === 'function') {
                        currentWindow.setOpacity(1);
                    }
                }

                // Restore previous theme
                if (previousTheme) {
                    updateTheme(previousTheme as 'light' | 'dark' | 'system');
                } else {
                    updateTheme('system');
                }
            }

            isOverlayMode.value = newState;
            savePreference(newState);

            // Emit event for other components
            window.dispatchEvent(
                new CustomEvent('overlayModeChanged', {
                    detail: { enabled: newState },
                }),
            );

            return true;
        } catch (error) {
            console.error('Error toggling overlay mode:', error);
            return false;
        }
    };

    // Enable overlay mode
    const enableOverlayMode = () => {
        if (isOverlayMode.value) return true;
        return toggleOverlayMode();
    };

    // Disable overlay mode
    const disableOverlayMode = () => {
        if (!isOverlayMode.value) return true;
        return toggleOverlayMode();
    };

    // Set window opacity - deprecated in favor of CSS-based transparency
    const setWindowOpacity = () => {
        // We don't use window-level opacity anymore to allow different
        // opacity levels for different UI elements (title bar, cards, etc.)
        return false;
    };

    // Initialize
    onMounted(() => {
        setTimeout(async () => {
            if (await checkSupport()) {
                const savedPreference = loadPreference();
                if (savedPreference) {
                    await enableOverlayMode();
                } else {
                    // Ensure normal mode has opaque background on startup
                    try {
                        if (window.macPermissions?.overlayMode) {
                            await window.macPermissions.overlayMode.setBackgroundColor('#f5f7fa');
                        } else if (window.remote) {
                            const currentWindow = window.remote.getCurrentWindow();
                            if (typeof currentWindow.setBackgroundColor === 'function') {
                                currentWindow.setBackgroundColor('#f5f7fa');
                            }
                        }
                    } catch {}
                }
            }
        }, 100);
    });

    // Watch for changes
    watch(isOverlayMode, () => {});

    return {
        isOverlayMode,
        isSupported,
        toggleOverlayMode,
        enableOverlayMode,
        disableOverlayMode,
        setWindowOpacity,
        checkSupport,
    };
}
