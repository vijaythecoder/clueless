import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { constants as fsConstants } from 'node:fs';
import { access } from 'node:fs/promises';
import { join } from 'node:path';

const AUDIO_PACKET_CHANNEL = 'nativephp:system-audio:packet';
const CALL_CLOSE_REQUEST_CHANNEL = 'nativephp:call-lifecycle:close-request';
const CANONICAL_AUDIO_HELPER = 'native/macos-audio-capture/macos-audio-capture';
const SUPPORTED_PERMISSIONS = new Set(['microphone', 'screen']);

function normalizePermissionStatus(status) {
    switch (status) {
        case 'granted':
            return 'authorized';
        case 'notDetermined':
            return 'not-determined';
        default:
            return status || 'not-determined';
    }
}

function errorMessage(error) {
    return error instanceof Error ? error.message : String(error);
}

function isTrustedDisplayMediaRequest(request) {
    if (!request?.userGesture || typeof request.securityOrigin !== 'string') {
        return false;
    }

    try {
        const origin = new URL(request.securityOrigin);
        return (
            (origin.protocol === 'http:' || origin.protocol === 'https:') &&
            (origin.hostname === '127.0.0.1' || origin.hostname === 'localhost' || origin.hostname === '[::1]')
        );
    } catch {
        return false;
    }
}

export function createNativePhpExtension({
    appPath,
    BrowserWindow,
    desktopCapturer,
    Menu,
    platform = process.platform,
    session,
    shell,
    systemPreferences,
}) {
    const helperPath = join(appPath, CANONICAL_AUDIO_HELPER);
    let capture = null;
    let displayMediaHandlerInstalled = false;
    const busyCallWindows = new Set();
    const callLifecycleStates = new WeakMap();
    let quitAttempt = null;

    function validatePermission(permission) {
        if (!SUPPORTED_PERMISSIONS.has(permission)) {
            throw new Error(`Unsupported permission: ${permission}`);
        }
    }

    function checkPermission(permission) {
        validatePermission(permission);

        if (platform !== 'darwin') {
            return 'authorized';
        }

        return normalizePermissionStatus(systemPreferences.getMediaAccessStatus(permission));
    }

    async function requestPermission(permission) {
        validatePermission(permission);

        if (platform !== 'darwin') {
            return 'authorized';
        }

        if (permission === 'microphone') {
            const granted = await systemPreferences.askForMediaAccess('microphone');
            return granted ? 'authorized' : 'denied';
        }

        try {
            await desktopCapturer.getSources({ types: ['screen'] });
        } catch (error) {
            console.warn('[Clueless Native] Screen permission request failed.', errorMessage(error));
        }

        return checkPermission('screen');
    }

    function sendPacket(packet, ownedCapture = capture) {
        if (!ownedCapture || capture !== ownedCapture || ownedCapture.owner.isDestroyed()) {
            return;
        }

        ownedCapture.owner.send(AUDIO_PACKET_CHANNEL, packet);
    }

    function consumeStdout(chunk, ownedCapture) {
        ownedCapture.buffer += chunk.toString('utf8');
        const lines = ownedCapture.buffer.split('\n');
        ownedCapture.buffer = lines.pop() || '';

        for (const line of lines) {
            if (!line.trim()) {
                continue;
            }

            try {
                sendPacket(JSON.parse(line), ownedCapture);
            } catch {
                sendPacket(
                    {
                        type: 'error',
                        message: 'The system audio helper returned an invalid packet.',
                        code: 1101,
                    },
                    ownedCapture,
                );
            }
        }
    }

    async function helperIsAvailable() {
        if (process.platform !== 'darwin') {
            return false;
        }

        try {
            await access(helperPath, fsConstants.X_OK);
            return true;
        } catch {
            return false;
        }
    }

    async function stopCapture({ force = false } = {}) {
        const ownedCapture = capture;
        if (!ownedCapture) {
            return { stopped: true, wasRunning: false };
        }

        capture = null;
        ownedCapture.owner.removeListener('destroyed', ownedCapture.onOwnerDestroyed);

        if (ownedCapture.child.exitCode !== null || ownedCapture.child.signalCode !== null) {
            return { stopped: true, wasRunning: true };
        }

        if (!force && ownedCapture.child.stdin.writable) {
            ownedCapture.child.stdin.write('{"command":"shutdown"}\n');
        } else {
            ownedCapture.child.kill('SIGTERM');
        }

        const exited = await new Promise((resolve) => {
            const timeout = setTimeout(() => resolve(false), force ? 250 : 1000);
            ownedCapture.child.once('exit', () => {
                clearTimeout(timeout);
                resolve(true);
            });
        });

        if (!exited && ownedCapture.child.exitCode === null) {
            ownedCapture.child.kill('SIGTERM');
        }

        return { stopped: true, wasRunning: true };
    }

    async function startCapture(event, options = {}) {
        if (capture) {
            if (capture.owner.id === event.sender.id) {
                return { started: true, alreadyRunning: true };
            }

            throw new Error('System audio capture is already owned by another window.');
        }

        if (!(await helperIsAvailable())) {
            throw new Error(`System audio helper is unavailable at ${CANONICAL_AUDIO_HELPER}.`);
        }

        const sampleRate = Number.isInteger(options.sampleRate) ? options.sampleRate : 24000;
        if (sampleRate < 8000 || sampleRate > 48000) {
            throw new Error('System audio sample rate must be between 8000 and 48000 Hz.');
        }

        const child = spawn(helperPath, [], {
            cwd: appPath,
            stdio: ['pipe', 'pipe', 'pipe'],
        });
        const ownedCapture = {
            buffer: '',
            child,
            owner: event.sender,
            onOwnerDestroyed: () => {
                void stopCapture();
            },
        };
        capture = ownedCapture;

        event.sender.once('destroyed', ownedCapture.onOwnerDestroyed);
        child.stdout.on('data', (chunk) => consumeStdout(chunk, ownedCapture));
        child.stderr.on('data', (chunk) => {
            console.error('[Clueless Native] System audio helper:', chunk.toString('utf8').trim());
        });
        child.on('error', (error) => {
            if (capture === ownedCapture) {
                sendPacket({ type: 'error', message: errorMessage(error), code: 1102 }, ownedCapture);
                capture = null;
            }
        });
        child.on('exit', (code, signal) => {
            if (capture === ownedCapture) {
                sendPacket({ type: 'status', state: 'exited', code, signal }, ownedCapture);
                capture = null;
            }
        });

        child.stdin.write(`${JSON.stringify({ command: 'start', sampleRate })}\n`);

        return { started: true, alreadyRunning: false };
    }

    function windowForEvent(event) {
        return BrowserWindow.fromWebContents(event.sender);
    }

    function callLifecycleState(window) {
        let state = callLifecycleStates.get(window);
        if (state) return state;

        state = {
            allowClose: false,
            busy: false,
            generationToken: null,
            pendingCloseRequest: null,
        };
        callLifecycleStates.set(window, state);

        window.on('close', (event) => {
            if (!state.busy || state.allowClose) return;

            event.preventDefault();
            if (state.pendingCloseRequest) return;

            state.pendingCloseRequest = {
                generationToken: state.generationToken,
                requestToken: randomUUID(),
            };
            if (!window.webContents.isDestroyed()) {
                window.webContents.send(CALL_CLOSE_REQUEST_CHANNEL, state.pendingCloseRequest);
            }
        });
        window.once('closed', () => {
            busyCallWindows.delete(window);
            callLifecycleStates.delete(window);
            resolveQuitWhenDrained();
        });

        return state;
    }

    function requireLifecycleWindow(event) {
        const window = windowForEvent(event);
        if (!window) {
            throw new Error('No window found for call lifecycle request.');
        }

        return window;
    }

    function requireLifecyclePayload(payload) {
        if (!payload || typeof payload !== 'object' || typeof payload.busy !== 'boolean') {
            throw new Error('Call lifecycle busy state must be provided.');
        }

        return payload;
    }

    function requirePendingCloseRequest(state, request) {
        if (!state.pendingCloseRequest) {
            throw new Error('No pending close request exists for this window.');
        }
        if (
            !request ||
            typeof request !== 'object' ||
            request.generationToken !== state.pendingCloseRequest.generationToken ||
            request.requestToken !== state.pendingCloseRequest.requestToken
        ) {
            throw new Error('Call lifecycle close request token is stale or invalid.');
        }

        const pending = state.pendingCloseRequest;
        state.pendingCloseRequest = null;
        return pending;
    }

    function resolveQuitWhenDrained() {
        if (!quitAttempt || busyCallWindows.size > 0) return;

        const attempt = quitAttempt;
        quitAttempt = null;
        void stopCapture({ force: true });
        attempt.resolve(true);
    }

    function cancelDeferredQuit() {
        if (!quitAttempt) return;

        const attempt = quitAttempt;
        quitAttempt = null;
        attempt.resolve(false);
    }

    return {
        async afterReady() {
            if (displayMediaHandlerInstalled) {
                return;
            }

            session.defaultSession.setDisplayMediaRequestHandler(
                async (request, callback) => {
                    if (!isTrustedDisplayMediaRequest(request)) {
                        console.warn('[Clueless Native] Rejected an untrusted display media request.');
                        callback();
                        return;
                    }

                    try {
                        const sources = await desktopCapturer.getSources({ types: ['screen', 'window'] });
                        const source = sources.find((item) => item.id.startsWith('screen:')) || sources[0];
                        if (source) {
                            callback({
                                video: source,
                                ...(platform === 'win32' ? { audio: 'loopback' } : {}),
                            });
                        } else {
                            callback();
                        }
                    } catch (error) {
                        console.error('[Clueless Native] Unable to select display media.', errorMessage(error));
                        callback();
                    }
                },
                { useSystemPicker: true },
            );
            displayMediaHandlerInstalled = true;
        },

        beforeQuit() {
            if (busyCallWindows.size === 0) {
                void stopCapture({ force: true });
                return { deferred: false };
            }

            if (!quitAttempt) {
                let resolveCompletion;
                const completion = new Promise((resolve) => {
                    resolveCompletion = resolve;
                });
                quitAttempt = {
                    completion,
                    resolve: resolveCompletion,
                };
            }

            for (const window of busyCallWindows) {
                window.close();
            }

            return { deferred: true, completion: quitAttempt.completion };
        },

        ipcHandlers: {
            'nativephp:permissions:check': async (_event, permission) => ({
                success: true,
                permission,
                status: checkPermission(permission),
            }),

            'nativephp:permissions:request': async (_event, permission) => ({
                success: true,
                permission,
                status: await requestPermission(permission),
            }),

            'nativephp:permissions:get-all': async () => ({
                success: true,
                permissions: {
                    microphone: checkPermission('microphone'),
                    screen: checkPermission('screen'),
                },
            }),

            'nativephp:permissions:open-settings': async (_event, permission) => {
                validatePermission(permission);
                const pane = permission === 'microphone' ? 'Privacy_Microphone' : 'Privacy_ScreenCapture';
                await shell.openExternal(`x-apple.systempreferences:com.apple.preference.security?${pane}`);
                return { success: true };
            },

            'nativephp:system-audio:is-available': async () => ({
                available: await helperIsAvailable(),
            }),

            'nativephp:system-audio:start': startCapture,

            'nativephp:system-audio:stop': async (event) => {
                if (capture && capture.owner.id !== event.sender.id) {
                    throw new Error('System audio capture is owned by another window.');
                }
                return stopCapture();
            },

            'nativephp:call-lifecycle:set-busy': async (event, rawPayload) => {
                const payload = requireLifecyclePayload(rawPayload);
                const window = requireLifecycleWindow(event);
                const state = callLifecycleState(window);

                if (payload.busy) {
                    if (state.busy) {
                        return { success: true, busy: true, generationToken: state.generationToken };
                    }
                    if (quitAttempt) {
                        throw new Error('A call cannot start while application shutdown is pending.');
                    }

                    state.allowClose = false;
                    state.busy = true;
                    state.generationToken = randomUUID();
                    busyCallWindows.add(window);
                    return { success: true, busy: true, generationToken: state.generationToken };
                }

                if (!state.busy || payload.generationToken !== state.generationToken) {
                    throw new Error('Call lifecycle generation is stale or invalid.');
                }
                if (state.pendingCloseRequest) {
                    throw new Error('Call lifecycle generation cannot be released while a close request is pending.');
                }

                state.busy = false;
                state.generationToken = null;
                busyCallWindows.delete(window);
                resolveQuitWhenDrained();
                return { success: true, busy: false };
            },

            'nativephp:call-lifecycle:complete-close': async (event, request) => {
                const window = requireLifecycleWindow(event);
                const state = callLifecycleState(window);
                requirePendingCloseRequest(state, request);
                state.busy = false;
                state.generationToken = null;
                state.allowClose = true;
                busyCallWindows.delete(window);
                setTimeout(() => window.close(), 0);
                return { success: true };
            },

            'nativephp:call-lifecycle:cancel-close': async (event, request) => {
                const state = callLifecycleState(requireLifecycleWindow(event));
                requirePendingCloseRequest(state, request);
                state.allowClose = false;
                cancelDeferredQuit();
                return { success: true, busy: state.busy };
            },

            'screen-protection:check-support': async (event) => {
                const window = windowForEvent(event);
                return {
                    supported: Boolean(window && typeof window.setContentProtection === 'function'),
                    platform: process.platform,
                };
            },

            'screen-protection:set': async (event, enabled) => {
                const window = windowForEvent(event);
                if (!window) {
                    return { success: false, error: 'No window found.' };
                }
                window.setContentProtection(Boolean(enabled));
                return { success: true, enabled: Boolean(enabled) };
            },

            'screen-protection:get-status': async () => ({
                status: 'unknown',
                reason: 'Electron does not expose content-protection state.',
            }),

            'overlay-mode:check-support': async (event) => ({
                supported: Boolean(windowForEvent(event)),
                platform: process.platform,
            }),

            'overlay-mode:set-always-on-top': async (event, enabled, level) => {
                const window = windowForEvent(event);
                if (!window) {
                    return { success: false, error: 'No window found.' };
                }
                window.setAlwaysOnTop(Boolean(enabled), level);
                return { success: true, alwaysOnTop: Boolean(enabled) };
            },

            'overlay-mode:set-opacity': async (event, opacity) => {
                const window = windowForEvent(event);
                if (!window) {
                    return { success: false, error: 'No window found.' };
                }
                const value = Math.max(0.2, Math.min(1, Number(opacity)));
                window.setOpacity(value);
                return { success: true, opacity: value };
            },

            'overlay-mode:get-opacity': async (event) => {
                const window = windowForEvent(event);
                return window ? { success: true, opacity: window.getOpacity() } : { success: false, error: 'No window found.' };
            },

            'overlay-mode:set-background-color': async (event, color) => {
                const window = windowForEvent(event);
                if (!window || typeof color !== 'string') {
                    return { success: false, error: 'Invalid window or color.' };
                }
                window.setBackgroundColor(color);
                return { success: true, color };
            },

            'nativephp:context-menu': async (event, template) => {
                const window = windowForEvent(event);
                const safeTemplate = Array.isArray(template) ? template.map(({ label, type, enabled }) => ({ label, type, enabled })) : [];
                Menu.buildFromTemplate(safeTemplate).popup({ window });
                return { success: true };
            },
        },
    };
}

export default createNativePhpExtension;
