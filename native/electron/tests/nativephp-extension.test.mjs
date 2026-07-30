import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { resolve } from 'node:path';
import test from 'node:test';

import { createNativePhpExtension } from '../../../resources/js/nativephp-extension.js';

function createExtension(overrides = {}) {
    let displayMediaHandler;
    let displayMediaOptions;

    const extension = createNativePhpExtension({
        app: {},
        appPath: resolve(import.meta.dirname, '../../..'),
        platform: 'darwin',
        BrowserWindow: {
            fromWebContents: () => null,
        },
        desktopCapturer: {
            getSources: async () => [{ id: 'screen:0:0', name: 'Entire Screen' }],
        },
        Menu: {
            buildFromTemplate: () => ({ popup: () => undefined }),
        },
        requireModule: () => {
            throw new Error('optional dependency missing');
        },
        session: {
            defaultSession: {
                setDisplayMediaRequestHandler: (handler, options) => {
                    displayMediaHandler = handler;
                    displayMediaOptions = options;
                },
            },
        },
        shell: {
            openExternal: async () => undefined,
        },
        systemPreferences: {
            askForMediaAccess: async () => true,
            getMediaAccessStatus: () => 'granted',
        },
        ...overrides,
    });

    return {
        extension,
        getDisplayMediaHandler: () => displayMediaHandler,
        getDisplayMediaOptions: () => displayMediaOptions,
    };
}

test('permission checks fall back to Electron when node-mac-permissions is missing', async () => {
    const { extension } = createExtension();
    const result = await extension.ipcHandlers['nativephp:permissions:check']({}, 'microphone');

    assert.deepEqual(result, {
        success: true,
        permission: 'microphone',
        status: 'authorized',
    });
});

test('permission requests ignore a silent node-mac-permissions shim', async () => {
    const { extension } = createExtension({
        requireModule: () => ({
            getAuthStatus: () => 'not-determined',
            askForMicrophoneAccess: async () => 'not-determined',
        }),
    });
    const result = await extension.ipcHandlers['nativephp:permissions:request']({}, 'microphone');

    assert.deepEqual(result, {
        success: true,
        permission: 'microphone',
        status: 'authorized',
    });
});

test('display media handler selects a macOS screen source without loopback audio', async () => {
    const harness = createExtension();
    await harness.extension.afterReady();

    assert.deepEqual(harness.getDisplayMediaOptions(), { useSystemPicker: true });

    const selection = await new Promise((resolveSelection) => {
        void harness.getDisplayMediaHandler()(
            {
                securityOrigin: 'http://127.0.0.1:8100',
                userGesture: true,
            },
            resolveSelection,
        );
    });

    assert.equal(selection.video.id, 'screen:0:0');
    assert.equal(selection.audio, undefined);
});

test('display media handler only selects loopback audio on Windows', async () => {
    const harness = createExtension({ platform: 'win32' });
    await harness.extension.afterReady();

    const selection = await new Promise((resolveSelection) => {
        void harness.getDisplayMediaHandler()(
            {
                securityOrigin: 'http://127.0.0.1:8100',
                userGesture: true,
            },
            resolveSelection,
        );
    });

    assert.equal(selection.video.id, 'screen:0:0');
    assert.equal(selection.audio, 'loopback');
});

test('display media handler rejects requests without a trusted origin and user gesture', async () => {
    const harness = createExtension();
    await harness.extension.afterReady();

    const selection = await new Promise((resolveSelection) => {
        void harness.getDisplayMediaHandler()(
            {
                securityOrigin: 'https://untrusted.example',
                userGesture: false,
            },
            resolveSelection,
        );
    });

    assert.equal(selection, undefined);
});

test('permission bridge rejects capabilities outside its allowlist', async () => {
    const { extension } = createExtension();

    await assert.rejects(extension.ipcHandlers['nativephp:permissions:check']({}, 'filesystem'), /Unsupported permission/);
});

function createWindowLifecycleHarness() {
    const sent = [];
    const sender = new EventEmitter();
    sender.id = 41;
    sender.send = (channel, payload) => sent.push({ channel, payload });
    sender.isDestroyed = () => false;

    const window = new EventEmitter();
    window.webContents = sender;
    window.closeCalls = 0;
    window.close = () => {
        window.closeCalls++;
        const event = {
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
        window.emit('close', event);
        if (!event.defaultPrevented) {
            window.emit('closed');
        }
        return event;
    };

    const { extension } = createExtension({
        BrowserWindow: {
            fromWebContents: (candidate) => (candidate === sender ? window : null),
        },
    });

    return {
        extension,
        sender,
        sent,
        window,
        attemptClose: () => window.close(),
    };
}

test('native lifecycle prevents busy close once and completes only after renderer approval', async () => {
    const harness = createWindowLifecycleHarness();
    const event = { sender: harness.sender };

    const active = await harness.extension.ipcHandlers['nativephp:call-lifecycle:set-busy'](event, { busy: true });
    const firstClose = harness.attemptClose();
    const repeatedClose = harness.attemptClose();
    const request = harness.sent[0].payload;

    assert.equal(firstClose.defaultPrevented, true);
    assert.equal(repeatedClose.defaultPrevented, true);
    assert.equal(typeof active.generationToken, 'string');
    assert.deepEqual(harness.sent, [{ channel: 'nativephp:call-lifecycle:close-request', payload: request }]);
    assert.equal(request.generationToken, active.generationToken);
    assert.equal(typeof request.requestToken, 'string');

    await harness.extension.ipcHandlers['nativephp:call-lifecycle:complete-close'](event, request);

    assert.equal(harness.window.closeCalls, 2);
    await new Promise((resolveTimer) => setTimeout(resolveTimer, 0));
    assert.equal(harness.window.closeCalls, 3);
    await assert.rejects(harness.extension.ipcHandlers['nativephp:call-lifecycle:complete-close'](event, request), /pending close request/i);
});

test('native lifecycle cancellation keeps a busy window open and permits a later close request', async () => {
    const harness = createWindowLifecycleHarness();
    const event = { sender: harness.sender };

    await harness.extension.ipcHandlers['nativephp:call-lifecycle:set-busy'](event, { busy: true });
    assert.equal(harness.attemptClose().defaultPrevented, true);
    const firstRequest = harness.sent[0].payload;
    await harness.extension.ipcHandlers['nativephp:call-lifecycle:cancel-close'](event, firstRequest);
    await assert.rejects(harness.extension.ipcHandlers['nativephp:call-lifecycle:cancel-close'](event, firstRequest), /pending close request/i);
    assert.equal(harness.attemptClose().defaultPrevented, true);
    const secondRequest = harness.sent[1].payload;

    assert.notEqual(firstRequest.requestToken, secondRequest.requestToken);
});

test('native lifecycle leaves idle window close behavior untouched', async () => {
    const harness = createWindowLifecycleHarness();

    assert.equal(harness.attemptClose().defaultPrevented, false);
    assert.deepEqual(harness.sent, []);
});

test('native lifecycle normal release requires the current generation and rejects a stale replay', async () => {
    const harness = createWindowLifecycleHarness();
    const event = { sender: harness.sender };
    const first = await harness.extension.ipcHandlers['nativephp:call-lifecycle:set-busy'](event, { busy: true });

    await harness.extension.ipcHandlers['nativephp:call-lifecycle:set-busy'](event, {
        busy: false,
        generationToken: first.generationToken,
    });
    const second = await harness.extension.ipcHandlers['nativephp:call-lifecycle:set-busy'](event, { busy: true });

    await assert.rejects(
        harness.extension.ipcHandlers['nativephp:call-lifecycle:set-busy'](event, {
            busy: false,
            generationToken: first.generationToken,
        }),
        /generation/i,
    );
    assert.notEqual(first.generationToken, second.generationToken);
    assert.equal(harness.attemptClose().defaultPrevented, true);
    assert.equal(harness.sent.at(-1).payload.generationToken, second.generationToken);
});

test('native lifecycle rejects close completion without a pending one-time request', async () => {
    const harness = createWindowLifecycleHarness();
    const event = { sender: harness.sender };
    const active = await harness.extension.ipcHandlers['nativephp:call-lifecycle:set-busy'](event, { busy: true });

    await assert.rejects(
        harness.extension.ipcHandlers['nativephp:call-lifecycle:complete-close'](event, {
            generationToken: active.generationToken,
            requestToken: 'spoofed-request',
        }),
        /pending close request/i,
    );
    assert.equal(harness.window.closeCalls, 0);
});

test('native lifecycle binds a close request token to its sender window', async () => {
    const sentByWindow = new Map();
    const windowsBySender = new Map();
    const makeWindow = (senderId) => {
        const sender = new EventEmitter();
        sender.id = senderId;
        sender.isDestroyed = () => false;
        sender.send = (channel, payload) => sentByWindow.set(senderId, { channel, payload });
        const window = new EventEmitter();
        window.webContents = sender;
        window.close = () => {
            const event = { preventDefault: () => undefined };
            window.emit('close', event);
        };
        windowsBySender.set(sender, window);
        return { sender, window };
    };
    const first = makeWindow(41);
    const second = makeWindow(42);
    const { extension } = createExtension({
        BrowserWindow: {
            fromWebContents: (sender) => windowsBySender.get(sender) ?? null,
        },
    });
    await extension.ipcHandlers['nativephp:call-lifecycle:set-busy']({ sender: first.sender }, { busy: true });
    await extension.ipcHandlers['nativephp:call-lifecycle:set-busy']({ sender: second.sender }, { busy: true });
    first.window.close();
    second.window.close();

    await assert.rejects(
        extension.ipcHandlers['nativephp:call-lifecycle:complete-close'](
            { sender: second.sender },
            sentByWindow.get(41).payload,
        ),
        /stale|invalid/i,
    );
});

test('native lifecycle actively requests guarded close and resolves deferred quit after drain', async () => {
    const harness = createWindowLifecycleHarness();
    const event = { sender: harness.sender };
    await harness.extension.ipcHandlers['nativephp:call-lifecycle:set-busy'](event, { busy: true });

    const decision = harness.extension.beforeQuit();

    assert.equal(decision.deferred, true);
    assert.equal(harness.window.closeCalls, 1);
    assert.equal(harness.sent.length, 1);

    await harness.extension.ipcHandlers['nativephp:call-lifecycle:complete-close'](event, harness.sent[0].payload);
    assert.equal(await decision.completion, true);
});

test('native lifecycle cancels deferred quit when renderer drain fails', async () => {
    const harness = createWindowLifecycleHarness();
    const event = { sender: harness.sender };
    await harness.extension.ipcHandlers['nativephp:call-lifecycle:set-busy'](event, { busy: true });
    const decision = harness.extension.beforeQuit();

    await harness.extension.ipcHandlers['nativephp:call-lifecycle:cancel-close'](event, harness.sent[0].payload);

    assert.equal(await decision.completion, false);
    assert.equal(harness.attemptClose().defaultPrevented, true);
});
