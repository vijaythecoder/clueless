interface NativePermissionResult {
    success: boolean;
    permission?: 'microphone' | 'screen';
    status?: 'authorized' | 'denied' | 'not-determined' | 'restricted';
    error?: string;
}

interface NativeWindowResult {
    success?: boolean;
    supported?: boolean;
    enabled?: boolean;
    opacity?: number;
    status?: string;
    error?: string;
}

interface SystemAudioPacket {
    type: 'audio' | 'error' | 'status' | 'permission' | 'heartbeat';
    data?: string;
    level?: number;
    message?: string;
    state?: string;
    granted?: boolean;
    code?: number;
}

interface Window {
    Native?: {
        on: (event: string, callback: (payload: unknown, event: string) => void) => void;
        contextMenu: (template: Array<Record<string, unknown>>) => Promise<unknown>;
    };
    electron?: {
        platform: string;
    };
    macPermissions?: {
        checkPermission: (permission: 'microphone' | 'screen') => Promise<NativePermissionResult>;
        requestPermission: (permission: 'microphone' | 'screen') => Promise<NativePermissionResult>;
        getAllPermissions: () => Promise<NativePermissionResult & { permissions?: Record<string, string> }>;
        openSettings: (permission: 'microphone' | 'screen') => Promise<{ success: boolean }>;
        screenProtection: {
            checkSupport: () => Promise<NativeWindowResult>;
            set: (enabled: boolean) => Promise<NativeWindowResult>;
            getStatus: () => Promise<NativeWindowResult>;
        };
        overlayMode: {
            checkSupport: () => Promise<NativeWindowResult>;
            setAlwaysOnTop: (enabled: boolean, level?: string) => Promise<NativeWindowResult>;
            setOpacity: (opacity: number) => Promise<NativeWindowResult>;
            getOpacity: () => Promise<NativeWindowResult>;
            setBackgroundColor: (color: string) => Promise<NativeWindowResult>;
        };
    };
    systemAudio?: {
        isAvailable: () => Promise<{ available: boolean }>;
        start: (options?: { sampleRate?: number }) => Promise<{ started: boolean; alreadyRunning?: boolean }>;
        stop: () => Promise<{ stopped: boolean; wasRunning?: boolean }>;
        onPacket: (listener: (packet: SystemAudioPacket) => void) => () => void;
    };
    callLifecycle?: {
        setBusy: (busy: boolean) => Promise<{ success: boolean; busy: boolean }>;
        completeClose: (request: LifecycleCloseRequest) => Promise<{ success: boolean }>;
        cancelClose: (request: LifecycleCloseRequest) => Promise<{ success: boolean; busy: boolean }>;
        onCloseRequested: (listener: (request: LifecycleCloseRequest) => void | Promise<void>) => () => void;
    };
}

interface LifecycleCloseRequest {
    generationToken: string;
    requestToken: string;
}
