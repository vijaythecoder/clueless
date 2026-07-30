import NativePHP from '#plugin';
import { app, BrowserWindow, desktopCapturer, ipcMain, Menu, session, shell, systemPreferences } from 'electron';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import certificate from '../../resources/cacert.pem?asset&asarUnpack';
import defaultIcon from '../../resources/icon.png?asset&asarUnpack';

let phpBinary = process.platform === 'win32' ? 'php.exe' : 'php';
phpBinary = path.join(import.meta.dirname, '../../resources/php', phpBinary).replace('app.asar', 'app.asar.unpacked');

const appPath =
    process.env.NODE_ENV === 'development'
        ? process.env.APP_PATH
        : path.join(import.meta.dirname, '../../resources/app').replace('app.asar', 'app.asar.unpacked');

async function loadProjectExtension() {
    if (!appPath) {
        throw new Error('NativePHP did not provide APP_PATH for the development app.');
    }

    const extensionPath = path.join(appPath, 'resources/js/nativephp-extension.js');
    if (!existsSync(extensionPath)) {
        throw new Error(`Clueless NativePHP extension is missing: ${extensionPath}`);
    }

    const module = await import(pathToFileURL(extensionPath).href);
    const factory = module.createNativePhpExtension || module.default;
    if (typeof factory !== 'function') {
        throw new Error('Clueless NativePHP extension does not export a factory.');
    }

    return factory({
        app,
        appPath,
        BrowserWindow,
        desktopCapturer,
        Menu,
        platform: process.platform,
        session,
        shell,
        systemPreferences,
    });
}

const extension = await loadProjectExtension();
await extension.beforeReady?.();

for (const [channel, handler] of Object.entries(extension.ipcHandlers || {})) {
    ipcMain.handle(channel, handler);
}

app.whenReady().then(() => extension.afterReady?.());

let deferredQuitCompletion = null;
let allowDeferredQuitReentry = false;

app.on('before-quit', (event) => {
    if (allowDeferredQuitReentry) {
        allowDeferredQuitReentry = false;
        return;
    }

    const decision = extension.beforeQuit?.();
    if (!decision?.deferred) return;

    event.preventDefault();
    if (deferredQuitCompletion === decision.completion) return;

    const completion = decision.completion;
    deferredQuitCompletion = completion;
    void completion
        .then((readyToQuit) => {
            if (deferredQuitCompletion !== completion) return;

            deferredQuitCompletion = null;
            if (!readyToQuit) return;

            allowDeferredQuitReentry = true;
            app.quit();
        })
        .catch((error) => {
            if (deferredQuitCompletion !== completion) return;

            deferredQuitCompletion = null;
            allowDeferredQuitReentry = false;
            console.error('[Clueless Native] Deferred application shutdown failed.', error);
        });
});

NativePHP.bootstrap(app, defaultIcon, phpBinary, certificate);
