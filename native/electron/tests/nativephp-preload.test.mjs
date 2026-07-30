import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm';

const projectRoot = resolve(import.meta.dirname, '../../..');
const preloadPath = resolve(projectRoot, 'native/electron/overrides/preload/index.mjs');

async function loadPreload(invoke) {
    const exposed = {};
    const ipcRenderer = new EventEmitter();
    ipcRenderer.invoke = invoke;
    const context = createContext({
        console,
        process,
        window: {},
    });
    const electron = new SyntheticModule(
        ['contextBridge', 'ipcRenderer'],
        function initialize() {
            this.setExport('contextBridge', {
                exposeInMainWorld: (name, value) => {
                    exposed[name] = value;
                },
            });
            this.setExport('ipcRenderer', ipcRenderer);
        },
        { context, identifier: 'electron' },
    );
    const module = new SourceTextModule(readFileSync(preloadPath, 'utf8'), {
        context,
        identifier: preloadPath,
    });
    await module.link(async (specifier) => {
        assert.equal(specifier, 'electron');
        return electron;
    });
    await module.evaluate();

    return { bridge: exposed.callLifecycle, ipcRenderer };
}

test('preload retains the active generation and binds it to normal release', async () => {
    const invocations = [];
    const { bridge } = await loadPreload(async (channel, payload) => {
        invocations.push([channel, payload]);
        if (payload?.busy === true) {
            return { success: true, busy: true, generationToken: 'generation-1' };
        }
        return { success: true, busy: false };
    });

    await bridge.setBusy(true);
    await bridge.setBusy(false);

    assert.deepEqual(JSON.parse(JSON.stringify(invocations)), [
        ['nativephp:call-lifecycle:set-busy', { busy: true }],
        ['nativephp:call-lifecycle:set-busy', { busy: false, generationToken: 'generation-1' }],
    ]);
});

test('preload forwards only the received one-time close request to completion', async () => {
    const invocations = [];
    const { bridge, ipcRenderer } = await loadPreload(async (channel, payload) => {
        invocations.push([channel, payload]);
        if (payload?.busy === true) {
            return { success: true, busy: true, generationToken: 'generation-1' };
        }
        return { success: true };
    });
    await bridge.setBusy(true);
    let receivedRequest;
    bridge.onCloseRequested((request) => {
        receivedRequest = request;
    });
    const request = {
        generationToken: 'generation-1',
        requestToken: 'request-1',
    };

    ipcRenderer.emit('nativephp:call-lifecycle:close-request', {}, request);
    await bridge.completeClose(receivedRequest);

    assert.deepEqual(JSON.parse(JSON.stringify(receivedRequest)), request);
    assert.deepEqual(JSON.parse(JSON.stringify(invocations.at(-1))), ['nativephp:call-lifecycle:complete-close', request]);
    await assert.rejects(bridge.completeClose(receivedRequest), /stale|invalid/i);
});
