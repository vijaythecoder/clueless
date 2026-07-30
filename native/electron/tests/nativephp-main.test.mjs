import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm';

const projectRoot = resolve(import.meta.dirname, '../../..');
const mainPath = resolve(projectRoot, 'native/electron/overrides/main/index.js');

function deferred() {
    let resolvePromise;
    const promise = new Promise((resolve) => {
        resolvePromise = resolve;
    });
    return { promise, resolve: resolvePromise };
}

function syntheticModule(context, identifier, exports) {
    return new SyntheticModule(
        Object.keys(exports),
        function initialize() {
            for (const [name, value] of Object.entries(exports)) {
                this.setExport(name, value);
            }
        },
        { context, identifier },
    );
}

async function evaluateMain({ app, extension, runtimeConsole = console }) {
    const testProcess = Object.create(process);
    testProcess.env = {
        ...process.env,
        APP_PATH: projectRoot,
        NODE_ENV: 'development',
    };
    const context = createContext({
        console: runtimeConsole,
        process: testProcess,
        URL,
        setTimeout,
        clearTimeout,
    });
    const modules = new Map();
    const moduleFor = async (specifier) => {
        if (modules.has(specifier)) return modules.get(specifier);

        let exports;
        if (specifier === '#plugin') {
            exports = { default: { bootstrap: () => undefined } };
        } else if (specifier === 'electron') {
            exports = {
                app,
                BrowserWindow: {},
                desktopCapturer: {},
                ipcMain: { handle: () => undefined },
                Menu: {},
                session: {},
                shell: {},
                systemPreferences: {},
            };
        } else if (specifier.includes('?asset&asarUnpack')) {
            exports = { default: '/tmp/asset' };
        } else {
            exports = await import(specifier);
        }

        const module = syntheticModule(context, specifier, exports);
        modules.set(specifier, module);
        return module;
    };
    const extensionModule = syntheticModule(context, 'project-extension', {
        createNativePhpExtension: () => extension,
        default: () => extension,
    });
    await extensionModule.link(() => undefined);
    await extensionModule.evaluate();

    const module = new SourceTextModule(readFileSync(mainPath, 'utf8'), {
        context,
        identifier: mainPath,
        initializeImportMeta(meta) {
            meta.dirname = dirname(mainPath);
            meta.url = `file://${mainPath}`;
        },
        importModuleDynamically: async () => extensionModule,
    });
    await module.link(moduleFor);
    await module.evaluate();
}

test('main defers Cmd+Q, prevents the first quit, and re-quits once renderer drains complete', async () => {
    const completion = deferred();
    const app = new EventEmitter();
    let quitCalls = 0;
    let reentryPrevented = null;
    app.whenReady = () => Promise.resolve();
    app.quit = () => {
        quitCalls++;
        const reentry = {
            preventDefault() {
                reentryPrevented = true;
            },
        };
        reentryPrevented = false;
        app.emit('before-quit', reentry);
    };
    const extension = {
        beforeQuitCalls: 0,
        beforeQuit() {
            this.beforeQuitCalls++;
            return { deferred: true, completion: completion.promise };
        },
        ipcHandlers: {},
    };
    await evaluateMain({ app, extension });

    let firstPrevented = false;
    app.emit('before-quit', {
        preventDefault() {
            firstPrevented = true;
        },
    });
    let repeatedPrevented = false;
    app.emit('before-quit', {
        preventDefault() {
            repeatedPrevented = true;
        },
    });

    assert.equal(firstPrevented, true);
    assert.equal(repeatedPrevented, true);
    assert.equal(quitCalls, 0);

    completion.resolve(true);
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(quitCalls, 1);
    assert.equal(reentryPrevented, false);
    assert.equal(extension.beforeQuitCalls, 2);
});

test('main keeps the application open when renderer drain cancels deferred quit', async () => {
    const app = new EventEmitter();
    let quitCalls = 0;
    app.whenReady = () => Promise.resolve();
    app.quit = () => {
        quitCalls++;
    };
    const extension = {
        beforeQuit: () => ({ deferred: true, completion: Promise.resolve(false) }),
        ipcHandlers: {},
    };
    await evaluateMain({ app, extension });

    let prevented = false;
    app.emit('before-quit', {
        preventDefault() {
            prevented = true;
        },
    });
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(prevented, true);
    assert.equal(quitCalls, 0);
});

test('main keeps the application open and handles a rejected renderer drain', async () => {
    const app = new EventEmitter();
    const errors = [];
    let quitCalls = 0;
    app.whenReady = () => Promise.resolve();
    app.quit = () => {
        quitCalls++;
    };
    const extension = {
        beforeQuit: () => ({ deferred: true, completion: Promise.reject(new Error('renderer unavailable')) }),
        ipcHandlers: {},
    };
    await evaluateMain({
        app,
        extension,
        runtimeConsole: {
            ...console,
            error: (...args) => errors.push(args),
        },
    });

    let prevented = false;
    app.emit('before-quit', {
        preventDefault() {
            prevented = true;
        },
    });
    await new Promise((resolveTimer) => setTimeout(resolveTimer, 0));

    assert.equal(prevented, true);
    assert.equal(quitCalls, 0);
    assert.match(errors[0][0], /deferred application shutdown failed/i);
});
