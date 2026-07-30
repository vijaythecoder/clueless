import { contextBridge, ipcRenderer } from 'electron';

const Native = {
    on: (event, callback) => {
        ipcRenderer.on('native-event', (_, data) => {
            const normalizedEvent = event.replace(/^(\\)+/, '');
            data.event = data.event.replace(/^(\\)+/, '');

            if (normalizedEvent === data.event) {
                callback(data.payload, normalizedEvent);
            }
        });
    },
    contextMenu: (template) => ipcRenderer.invoke('nativephp:context-menu', template),
};

contextBridge.exposeInMainWorld('Native', Native);
contextBridge.exposeInMainWorld('electron', {
    platform: process.platform,
});

contextBridge.exposeInMainWorld('macPermissions', {
    checkPermission: (permission) => ipcRenderer.invoke('nativephp:permissions:check', permission),
    requestPermission: (permission) => ipcRenderer.invoke('nativephp:permissions:request', permission),
    getAllPermissions: () => ipcRenderer.invoke('nativephp:permissions:get-all'),
    openSettings: (permission) => ipcRenderer.invoke('nativephp:permissions:open-settings', permission),
    PERMISSION_TYPES: {
        MICROPHONE: 'microphone',
        SCREEN: 'screen',
    },
    PERMISSION_STATUS: {
        NOT_DETERMINED: 'not-determined',
        DENIED: 'denied',
        AUTHORIZED: 'authorized',
        RESTRICTED: 'restricted',
    },
    screenProtection: {
        checkSupport: () => ipcRenderer.invoke('screen-protection:check-support'),
        set: (enabled) => ipcRenderer.invoke('screen-protection:set', enabled),
        getStatus: () => ipcRenderer.invoke('screen-protection:get-status'),
    },
    overlayMode: {
        checkSupport: () => ipcRenderer.invoke('overlay-mode:check-support'),
        setAlwaysOnTop: (enabled, level) => ipcRenderer.invoke('overlay-mode:set-always-on-top', enabled, level),
        setOpacity: (opacity) => ipcRenderer.invoke('overlay-mode:set-opacity', opacity),
        getOpacity: () => ipcRenderer.invoke('overlay-mode:get-opacity'),
        setBackgroundColor: (color) => ipcRenderer.invoke('overlay-mode:set-background-color', color),
    },
});

contextBridge.exposeInMainWorld('systemAudio', {
    isAvailable: () => ipcRenderer.invoke('nativephp:system-audio:is-available'),
    start: (options = {}) => ipcRenderer.invoke('nativephp:system-audio:start', options),
    stop: () => ipcRenderer.invoke('nativephp:system-audio:stop'),
    onPacket: (listener) => {
        if (typeof listener !== 'function') {
            throw new TypeError('systemAudio.onPacket requires a function.');
        }

        const handler = (_event, packet) => listener(packet);
        ipcRenderer.on('nativephp:system-audio:packet', handler);
        return () => ipcRenderer.removeListener('nativephp:system-audio:packet', handler);
    },
});

let activeLifecycleGeneration = null;
let pendingLifecycleCloseRequest = null;

function sameLifecycleRequest(left, right) {
    return (
        left &&
        right &&
        left.generationToken === right.generationToken &&
        left.requestToken === right.requestToken
    );
}

function requirePendingLifecycleRequest(request) {
    if (!sameLifecycleRequest(request, pendingLifecycleCloseRequest)) {
        throw new Error('Call lifecycle close request is stale or invalid.');
    }

    return pendingLifecycleCloseRequest;
}

contextBridge.exposeInMainWorld('callLifecycle', {
    setBusy: async (busy) => {
        if (typeof busy !== 'boolean') {
            throw new TypeError('callLifecycle.setBusy requires a boolean.');
        }

        if (busy) {
            if (activeLifecycleGeneration) {
                return { success: true, busy: true };
            }

            const result = await ipcRenderer.invoke('nativephp:call-lifecycle:set-busy', { busy: true });
            if (typeof result?.generationToken !== 'string') {
                throw new Error('Native call lifecycle did not return a generation token.');
            }
            activeLifecycleGeneration = result.generationToken;
            return { success: true, busy: true };
        }

        if (!activeLifecycleGeneration) {
            return { success: true, busy: false };
        }

        const result = await ipcRenderer.invoke('nativephp:call-lifecycle:set-busy', {
            busy: false,
            generationToken: activeLifecycleGeneration,
        });
        activeLifecycleGeneration = null;
        return result;
    },
    completeClose: async (request) => {
        const pending = requirePendingLifecycleRequest(request);
        const result = await ipcRenderer.invoke('nativephp:call-lifecycle:complete-close', pending);
        pendingLifecycleCloseRequest = null;
        activeLifecycleGeneration = null;
        return result;
    },
    cancelClose: async (request) => {
        const pending = requirePendingLifecycleRequest(request);
        const result = await ipcRenderer.invoke('nativephp:call-lifecycle:cancel-close', pending);
        pendingLifecycleCloseRequest = null;
        return result;
    },
    onCloseRequested: (listener) => {
        if (typeof listener !== 'function') {
            throw new TypeError('callLifecycle.onCloseRequested requires a function.');
        }

        const handler = (_event, request) => {
            if (
                !request ||
                typeof request.generationToken !== 'string' ||
                typeof request.requestToken !== 'string' ||
                request.generationToken !== activeLifecycleGeneration ||
                pendingLifecycleCloseRequest
            ) {
                return;
            }

            pendingLifecycleCloseRequest = Object.freeze({
                generationToken: request.generationToken,
                requestToken: request.requestToken,
            });
            void listener(pendingLifecycleCloseRequest);
        };
        ipcRenderer.on('nativephp:call-lifecycle:close-request', handler);
        return () => ipcRenderer.removeListener('nativephp:call-lifecycle:close-request', handler);
    },
});

ipcRenderer.on('log', (_event, { level, message, context }) => {
    if (level === 'error') {
        console.error(`[${level}] ${message}`, context);
    } else if (level === 'warn') {
        console.warn(`[${level}] ${message}`, context);
    } else {
        console.log(`[${level}] ${message}`, context);
    }
});

ipcRenderer.on('native-event', (_event, data) => {
    data.event = data.event.replace(/^(\\)+/, '');

    if (window.Livewire) {
        window.Livewire.dispatch(`native:${data.event}`, data.payload);
    }

    if (window.livewire) {
        window.livewire.components.components().forEach((component) => {
            if (!Array.isArray(component.listeners)) {
                return;
            }

            component.listeners.forEach((event) => {
                if (!event.startsWith('native')) {
                    return;
                }

                const eventParts = event.split(/(native:|native-)|:|,/);
                if (eventParts[1] === 'native:') {
                    eventParts.splice(2, 0, 'private', undefined, 'nativephp', undefined);
                }

                if (data.event === eventParts[6]) {
                    window.livewire.emit(event, data.payload);
                }
            });
        });
    }
});
