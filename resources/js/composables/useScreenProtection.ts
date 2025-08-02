import { onMounted, onUnmounted, ref } from 'vue';

// Type definitions for Electron APIs
interface ElectronAPI {
    screenProtection?: {
        setContentProtection: (enabled: boolean) => boolean;
        isContentProtectionSupported: () => boolean;
    };
}

interface Remote {
    getCurrentWindow: () => {
        setContentProtection: (enable: boolean) => void;
    };
}

interface MacPermissions {
    screenProtection: {
        checkSupport: () => Promise<{ supported: boolean; platform?: string; reason?: string; error?: string }>;
        set: (enabled: boolean) => Promise<{ success: boolean; enabled?: boolean; error?: string }>;
        getStatus: () => Promise<{ status: string; reason?: string; error?: string }>;
    };
}

declare global {
    interface Window {
        electronAPI?: ElectronAPI;
        remote?: Remote;
        electron?: any;
        macPermissions?: MacPermissions;
    }
}

export function useScreenProtection() {
    const isProtectionEnabled = ref(false);
    const isProtectionSupported = ref(false);
    const protectionStatus = ref<'active' | 'inactive' | 'unsupported'>('inactive');

    // Load saved preference
    const loadPreference = () => {
        const saved = localStorage.getItem('screenProtectionEnabled');
        return saved === 'true';
    };

    // Save preference
    const savePreference = (enabled: boolean) => {
        localStorage.setItem('screenProtectionEnabled', String(enabled));
    };

    // Check if protection is supported
    const checkSupport = async () => {
        // Try different methods to access Electron APIs
        try {
            // Method 1: Use new IPC handlers via macPermissions API
            if (window.macPermissions?.screenProtection) {
                const result = await window.macPermissions.screenProtection.checkSupport();
                if (result.supported) {
                    isProtectionSupported.value = true;
                    return true;
                }
            }
            
            // Method 2: Check if window.remote is available (NativePHP exposes this)
            if (window.remote && typeof window.remote.getCurrentWindow === 'function') {
                const currentWindow = window.remote.getCurrentWindow();
                if (currentWindow && typeof currentWindow.setContentProtection === 'function') {
                    isProtectionSupported.value = true;
                    return true;
                }
            }

            // Method 3: Check custom electronAPI (for custom preload)
            if (window.electronAPI?.screenProtection?.isContentProtectionSupported) {
                isProtectionSupported.value = window.electronAPI.screenProtection.isContentProtectionSupported();
                return isProtectionSupported.value;
            }

            // Method 4: Check if we're in Electron environment
            if (window.electron || (window as any).process?.versions?.electron) {
                console.log('Electron detected but no screen protection API available');
            }
        } catch (error) {
            console.error('Error checking screen protection support:', error);
        }

        isProtectionSupported.value = false;
        protectionStatus.value = 'unsupported';
        return false;
    };

    // Toggle screen protection
    const toggleProtection = async () => {
        if (!isProtectionSupported.value) {
            return false;
        }

        const newState = !isProtectionEnabled.value;
        let success = false;

        try {
            // Method 1: Use new IPC handlers via macPermissions API
            if (window.macPermissions?.screenProtection) {
                try {
                    const result = await window.macPermissions.screenProtection.set(newState);
                    if (result.success) {
                        success = true;
                    }
                } catch (error) {
                    console.error('Error using macPermissions for screen protection:', error);
                }
            }
            
            // Method 2: Try window.remote (NativePHP) as fallback
            if (!success && window.remote && typeof window.remote.getCurrentWindow === 'function') {
                const currentWindow = window.remote.getCurrentWindow();
                
                if (currentWindow && typeof currentWindow.setContentProtection === 'function') {
                    try {
                        currentWindow.setContentProtection(newState);
                        
                        // Additional protection methods for macOS
                        if (newState && (window as any).process?.platform === 'darwin') {
                            // Try to set additional privacy settings
                            try {
                                // Set window level to be excluded from screen capture
                                if (typeof currentWindow.setVisibleOnAllWorkspaces === 'function') {
                                    currentWindow.setVisibleOnAllWorkspaces(true, { visibleOnFullScreen: true });
                                    currentWindow.setVisibleOnAllWorkspaces(false);
                                }

                                // Set window to be excluded from mission control
                                if (typeof currentWindow.setExcludedFromShownWindowsMenu === 'function') {
                                    currentWindow.setExcludedFromShownWindowsMenu(true);
                                }
                                
                                // Try sharingType method (newer Electron versions)
                                if (typeof currentWindow.setSharingType === 'function') {
                                    currentWindow.setSharingType('none');
                                }
                            } catch (e) {
                                console.error('Error applying additional macOS protection:', e);
                            }
                        }
                        
                        success = true;
                    } catch {
                        // Silently ignore errors
                    }
                }
            }

            // Method 3: Try custom electronAPI
            if (!success && window.electronAPI?.screenProtection?.setContentProtection) {
                success = window.electronAPI.screenProtection.setContentProtection(newState);
            }

            if (success) {
                isProtectionEnabled.value = newState;
                protectionStatus.value = newState ? 'active' : 'inactive';
                savePreference(newState);

                // Emit custom event for other components to listen
                window.dispatchEvent(
                    new CustomEvent('screenProtectionChanged', {
                        detail: { enabled: newState },
                    }),
                );

                return true;
            }
        } catch (error) {
            console.error('Error toggling screen protection:', error);
        }

        return false;
    };

    // Enable protection
    const enableProtection = () => {
        if (isProtectionEnabled.value) return true;
        return toggleProtection();
    };

    // Disable protection
    const disableProtection = () => {
        if (!isProtectionEnabled.value) return true;
        return toggleProtection();
    };

    // Auto-enable during calls
    const enableForCall = () => {
        if (!isProtectionEnabled.value) {
            enableProtection();
            return true;
        }
        return false;
    };

    // Check if protection is actually active
    const verifyProtection = async () => {
        if (!isProtectionSupported.value) return false;
        
        try {
            // Method 1: Use new IPC handlers via macPermissions API
            if (window.macPermissions?.screenProtection) {
                const result = await window.macPermissions.screenProtection.getStatus();
                if (result.status === 'active') {
                    return true;
                } else if (result.status === 'inactive') {
                    return false;
                }
            }
            
            // Method 2: Try window.remote as fallback
            if (window.remote && typeof window.remote.getCurrentWindow === 'function') {
                const currentWindow = window.remote.getCurrentWindow();
                
                // Try to check if content protection is active
                if (typeof currentWindow.isContentProtectionEnabled === 'function') {
                    const isEnabled = currentWindow.isContentProtectionEnabled();
                    return isEnabled;
                }
                
                // For newer Electron versions, check sharingType
                if (typeof currentWindow.getSharingType === 'function') {
                    const sharingType = currentWindow.getSharingType();
                    return sharingType === 'none';
                }
            }
        } catch (error) {
            console.error('Error verifying protection:', error);
        }
        
        return isProtectionEnabled.value;
    };

    // Initialize on mount
    onMounted(() => {
        // Add a small delay to ensure Electron APIs are loaded
        setTimeout(async () => {
            if (await checkSupport()) {
                // Load saved preference
                const savedPreference = loadPreference();
                if (savedPreference) {
                    await enableProtection();
                    // Verify after enabling
                    setTimeout(async () => {
                        await verifyProtection();
                    }, 500);
                }
            }
        }, 100);

        // Listen for protection change events from other sources
        const handleProtectionChange = (event: Event) => {
            const customEvent = event as CustomEvent;
            if (customEvent.detail?.enabled !== undefined) {
                isProtectionEnabled.value = customEvent.detail.enabled;
                protectionStatus.value = customEvent.detail.enabled ? 'active' : 'inactive';
            }
        };

        window.addEventListener('screenProtectionChanged', handleProtectionChange);

        // Cleanup
        onUnmounted(() => {
            window.removeEventListener('screenProtectionChanged', handleProtectionChange);
        });
    });

    return {
        isProtectionEnabled,
        isProtectionSupported,
        protectionStatus,
        toggleProtection,
        enableProtection,
        disableProtection,
        enableForCall,
        checkSupport,
        verifyProtection,
    };
}
