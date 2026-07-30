import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, resolve } from 'node:path';
import test from 'node:test';

const projectRoot = resolve(import.meta.dirname, '../../..');
const installedElectronRoot = resolve(projectRoot, 'vendor/nativephp/electron/resources/js');
const patchScript = resolve(projectRoot, 'scripts/apply-nativephp-electron-patch.mjs');
const patchTargets = [
    'src/main/index.js',
    'src/preload/index.js',
    'electron-plugin/src/index.ts',
    'electron-plugin/dist/index.js',
    'electron-plugin/src/preload/index.mts',
    'electron-plugin/dist/preload/index.mjs',
    'electron-plugin/src/server/api/window.ts',
    'electron-plugin/dist/server/api/window.js',
    'electron-plugin/src/server/api/menuBar.ts',
    'electron-plugin/dist/server/api/menuBar.js',
];

test('NativePHP Electron patch applies cleanly and is idempotent', () => {
    const temporaryRoot = mkdtempSync(resolve(tmpdir(), 'clueless-nativephp-patch-'));

    try {
        for (const relativePath of patchTargets) {
            const destination = resolve(temporaryRoot, relativePath);
            mkdirSync(dirname(destination), { recursive: true });
            copyFileSync(resolve(installedElectronRoot, relativePath), destination);
        }

        const environment = {
            ...process.env,
            NATIVEPHP_ELECTRON_ROOT: temporaryRoot,
        };
        const firstRun = execFileSync(process.execPath, [patchScript], {
            encoding: 'utf8',
            env: environment,
        });
        const secondRun = execFileSync(process.execPath, [patchScript], {
            encoding: 'utf8',
            env: environment,
        });

        assert.match(firstRun, /Applied NativePHP Electron hardening patch to \d+ files|already applied/);
        assert.match(secondRun, /already applied/);

        for (const relativePath of patchTargets) {
            const content = readFileSync(resolve(temporaryRoot, relativePath), 'utf8');
            assert.doesNotMatch(content, /@electron\/remote/);
            assert.doesNotMatch(content, /nodeIntegration:\s*true/);
        }

        const preload = readFileSync(resolve(temporaryRoot, 'electron-plugin/dist/preload/index.mjs'), 'utf8');
        assert.match(preload, /exposeInMainWorld\('systemAudio'/);
        assert.match(preload, /exposeInMainWorld\('callLifecycle'/);
        assert.doesNotMatch(preload, /window\.remote|exposeInMainWorld\('remote'/);

        for (const relativePath of ['electron-plugin/src/server/api/window.ts', 'electron-plugin/dist/server/api/window.js']) {
            const windowApi = readFileSync(resolve(temporaryRoot, relativePath), 'utf8');
            assert.match(windowApi, /window\.on\(['"]closed['"]/);
            assert.doesNotMatch(windowApi, /window\.on\(['"]close['"]/);
        }

        const expectedMain = readFileSync(resolve(projectRoot, 'native/electron/overrides/main/index.js'), 'utf8');
        assert.equal(readFileSync(resolve(temporaryRoot, 'src/main/index.js'), 'utf8'), expectedMain);

        const expectedPreload = readFileSync(resolve(projectRoot, 'native/electron/overrides/preload/index.mjs'), 'utf8');
        for (const relativePath of [
            'src/preload/index.js',
            'electron-plugin/src/preload/index.mts',
            'electron-plugin/dist/preload/index.mjs',
        ]) {
            assert.equal(readFileSync(resolve(temporaryRoot, relativePath), 'utf8'), expectedPreload);
        }
    } finally {
        rmSync(temporaryRoot, { recursive: true, force: true });
    }
});
