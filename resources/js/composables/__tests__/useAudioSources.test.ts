// @vitest-environment happy-dom

import { useAudioSources } from '@/composables/useAudioSources';
import { afterEach, describe, expect, it, vi } from 'vitest';

function permissionResult(permission: 'microphone' | 'screen', status: NativePermissionResult['status']): NativePermissionResult {
    return {
        success: true,
        permission,
        status,
    };
}

afterEach(() => {
    delete window.macPermissions;
    delete window.systemAudio;
    vi.restoreAllMocks();
});

describe('useAudioSources permission preflight', () => {
    it('reports an unavailable native bridge instead of not-determined', async () => {
        const audio = useAudioSources();

        await expect(audio.checkPermissions()).rejects.toThrow('Local capture is only available in the Clueless desktop app.');
    });

    it('requests undetermined microphone and screen permissions', async () => {
        const checkPermission = vi
            .fn()
            .mockResolvedValueOnce(permissionResult('microphone', 'not-determined'))
            .mockResolvedValueOnce(permissionResult('screen', 'not-determined'));
        const requestPermission = vi
            .fn()
            .mockResolvedValueOnce(permissionResult('microphone', 'authorized'))
            .mockResolvedValueOnce(permissionResult('screen', 'authorized'));
        window.macPermissions = {
            checkPermission,
            requestPermission,
            getAllPermissions: vi.fn(),
            openSettings: vi.fn(),
            screenProtection: {
                checkSupport: vi.fn(),
                set: vi.fn(),
                getStatus: vi.fn(),
            },
            overlayMode: {
                checkSupport: vi.fn(),
                setAlwaysOnTop: vi.fn(),
                setOpacity: vi.fn(),
                getOpacity: vi.fn(),
                setBackgroundColor: vi.fn(),
            },
        };
        window.systemAudio = {
            isAvailable: vi.fn(async () => ({ available: true })),
            start: vi.fn(),
            stop: vi.fn(),
            onPacket: vi.fn(),
        };

        await expect(useAudioSources().checkPermissions()).resolves.toBeUndefined();
        expect(requestPermission).toHaveBeenNthCalledWith(1, 'microphone');
        expect(requestPermission).toHaveBeenNthCalledWith(2, 'screen');
    });

    it('stops before network startup when microphone permission is denied', async () => {
        window.macPermissions = {
            checkPermission: vi.fn(async (permission) => permissionResult(permission, permission === 'microphone' ? 'denied' : 'authorized')),
            requestPermission: vi.fn(),
            getAllPermissions: vi.fn(),
            openSettings: vi.fn(),
            screenProtection: {
                checkSupport: vi.fn(),
                set: vi.fn(),
                getStatus: vi.fn(),
            },
            overlayMode: {
                checkSupport: vi.fn(),
                setAlwaysOnTop: vi.fn(),
                setOpacity: vi.fn(),
                getOpacity: vi.fn(),
                setBackgroundColor: vi.fn(),
            },
        };
        window.systemAudio = {
            isAvailable: vi.fn(async () => ({ available: true })),
            start: vi.fn(),
            stop: vi.fn(),
            onPacket: vi.fn(),
        };

        await expect(useAudioSources().checkPermissions()).rejects.toThrow(
            'Microphone access is denied. Grant it in System Settings, then try again.',
        );
    });

    it('distinguishes a missing system audio helper from permission denial', async () => {
        window.macPermissions = {
            checkPermission: vi.fn(async (permission) => permissionResult(permission, 'authorized')),
            requestPermission: vi.fn(),
            getAllPermissions: vi.fn(),
            openSettings: vi.fn(),
            screenProtection: {
                checkSupport: vi.fn(),
                set: vi.fn(),
                getStatus: vi.fn(),
            },
            overlayMode: {
                checkSupport: vi.fn(),
                setAlwaysOnTop: vi.fn(),
                setOpacity: vi.fn(),
                getOpacity: vi.fn(),
                setBackgroundColor: vi.fn(),
            },
        };
        window.systemAudio = {
            isAvailable: vi.fn(async () => ({ available: false })),
            start: vi.fn(),
            stop: vi.fn(),
            onPacket: vi.fn(),
        };

        await expect(useAudioSources().checkPermissions()).rejects.toThrow(
            'System audio capture is unavailable. Rebuild the macOS audio helper, then relaunch Clueless.',
        );
    });
});
