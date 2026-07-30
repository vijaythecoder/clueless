import { onMounted, onUnmounted, ref } from 'vue';

export function useScreenProtection() {
    const isProtectionEnabled = ref(false);
    const isProtectionSupported = ref(false);
    const protectionStatus = ref<'active' | 'inactive' | 'unsupported'>('inactive');

    const checkSupport = async () => {
        try {
            const result = await window.macPermissions?.screenProtection.checkSupport();
            isProtectionSupported.value = Boolean(result?.supported);
        } catch (error) {
            console.error('Unable to check screen protection support:', error);
            isProtectionSupported.value = false;
        }

        protectionStatus.value = isProtectionSupported.value ? 'inactive' : 'unsupported';
        return isProtectionSupported.value;
    };

    const setProtection = async (enabled: boolean) => {
        if (!isProtectionSupported.value || !window.macPermissions) return false;
        try {
            const result = await window.macPermissions.screenProtection.set(enabled);
            if (result.success === false) return false;

            isProtectionEnabled.value = enabled;
            protectionStatus.value = enabled ? 'active' : 'inactive';
            localStorage.setItem('screenProtectionEnabled', String(enabled));
            window.dispatchEvent(new CustomEvent('screenProtectionChanged', { detail: { enabled } }));
            return true;
        } catch (error) {
            console.error('Unable to update screen protection:', error);
            return false;
        }
    };

    const toggleProtection = () => setProtection(!isProtectionEnabled.value);
    const enableProtection = () => (isProtectionEnabled.value ? Promise.resolve(true) : setProtection(true));
    const disableProtection = () => (!isProtectionEnabled.value ? Promise.resolve(true) : setProtection(false));

    const verifyProtection = async () => {
        if (!isProtectionSupported.value || !window.macPermissions) return false;
        try {
            const result = await window.macPermissions.screenProtection.getStatus();
            if (result.status === 'active') return true;
            if (result.status === 'inactive') return false;
        } catch (error) {
            console.error('Unable to verify screen protection:', error);
        }
        return isProtectionEnabled.value;
    };

    const handleProtectionChange = (event: Event) => {
        const enabled = Boolean((event as CustomEvent<{ enabled?: boolean }>).detail?.enabled);
        isProtectionEnabled.value = enabled;
        protectionStatus.value = enabled ? 'active' : 'inactive';
    };

    onMounted(() => {
        window.addEventListener('screenProtectionChanged', handleProtectionChange);
        globalThis.setTimeout(async () => {
            if ((await checkSupport()) && localStorage.getItem('screenProtectionEnabled') === 'true') {
                await enableProtection();
            }
        }, 100);
    });

    onUnmounted(() => {
        window.removeEventListener('screenProtectionChanged', handleProtectionChange);
    });

    return {
        isProtectionEnabled,
        isProtectionSupported,
        protectionStatus,
        toggleProtection,
        enableProtection,
        disableProtection,
        enableForCall: enableProtection,
        checkSupport,
        verifyProtection,
    };
}
