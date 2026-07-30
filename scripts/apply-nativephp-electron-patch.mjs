#!/usr/bin/env node

import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import process from 'node:process';

const projectRoot = resolve(import.meta.dirname, '..');
const electronRoot = process.env.NATIVEPHP_ELECTRON_ROOT
    ? resolve(process.env.NATIVEPHP_ELECTRON_ROOT)
    : resolve(projectRoot, 'vendor/nativephp/electron/resources/js');
const checkOnly = process.argv.includes('--check');
const changes = [];

function absolute(relativePath) {
    return resolve(projectRoot, relativePath);
}

function read(relativePath) {
    const path = absolute(relativePath);
    if (!existsSync(path)) {
        throw new Error(`Required NativePHP file is missing: ${relativePath}`);
    }
    return readFileSync(path, 'utf8');
}

function installOverride(sourcePath, destinationFromElectronRoot) {
    const source = read(sourcePath);
    const destination = resolve(electronRoot, destinationFromElectronRoot);
    if (!existsSync(dirname(destination))) {
        throw new Error(`NativePHP destination directory is missing: ${dirname(destination)}`);
    }

    const current = existsSync(destination) ? readFileSync(destination, 'utf8') : '';
    if (current === source) {
        return;
    }

    changes.push(destinationFromElectronRoot);
    if (!checkOnly) {
        writeFileSync(destination, source);
    }
}

function applyReplacements(destinationFromElectronRoot, replacements) {
    const destination = resolve(electronRoot, destinationFromElectronRoot);
    if (!existsSync(destination)) {
        throw new Error(`Required NativePHP file is missing: ${destinationFromElectronRoot}`);
    }

    let content = readFileSync(destination, 'utf8');
    let changed = false;

    for (const { oldText, newText, patchedAnchor } of replacements) {
        if (content.includes(oldText)) {
            content = content.replace(oldText, newText);
            changed = true;
            continue;
        }

        if (!content.includes(patchedAnchor)) {
            throw new Error(`NativePHP source drifted in ${destinationFromElectronRoot}; expected patch anchor was not found.`);
        }
    }

    if (!changed) {
        return;
    }

    changes.push(destinationFromElectronRoot);
    if (!checkOnly) {
        writeFileSync(destination, content);
    }
}

installOverride('native/electron/overrides/main/index.js', 'src/main/index.js');
installOverride('native/electron/overrides/preload/index.mjs', 'src/preload/index.js');
installOverride('native/electron/overrides/preload/index.mjs', 'electron-plugin/src/preload/index.mts');
installOverride('native/electron/overrides/preload/index.mjs', 'electron-plugin/dist/preload/index.mjs');

applyReplacements('electron-plugin/src/index.ts', [
    {
        oldText: 'import { initialize } from "@electron/remote/main/index.js";\n',
        newText: '',
        patchedAnchor: 'import state from "./server/state.js";',
    },
    {
        oldText: '    initialize();\n\n',
        newText: '',
        patchedAnchor: '    state.icon = icon;',
    },
]);

applyReplacements('electron-plugin/dist/index.js', [
    {
        oldText: 'import { initialize } from "@electron/remote/main/index.js";\n',
        newText: '',
        patchedAnchor: 'import state from "./server/state.js";',
    },
    {
        oldText: '        initialize();\n',
        newText: '',
        patchedAnchor: '        state.icon = icon;',
    },
]);

applyReplacements('electron-plugin/src/server/api/window.ts', [
    {
        oldText: 'import {enable} from "@electron/remote/main/index.js";\n',
        newText: '',
        patchedAnchor: 'const router = express.Router();',
    },
    {
        oldText: '        nodeIntegration: true,\n',
        newText: '        nodeIntegration: false,\n',
        patchedAnchor: '        nodeIntegration: false,',
    },
    {
        oldText: '    enable(window.webContents);\n',
        newText: '',
        patchedAnchor: '    if (req.body.rememberState === true) {',
    },
    {
        oldText: '        state.windows[id].close();\n        delete state.windows[id];\n',
        newText: '        state.windows[id].close();\n',
        patchedAnchor: '        state.windows[id].close();\n',
    },
    {
        oldText: "    window.on('close', (evt) => {\n",
        newText: "    window.on('closed', () => {\n",
        patchedAnchor: "    window.on('closed', () => {",
    },
    {
        oldText: '        webPreferences: {\n' + '            ...defaultWebPreferences,\n' + '            ...webPreferences\n' + '        },\n',
        newText:
            '        webPreferences: {\n' +
            '            ...defaultWebPreferences,\n' +
            '            ...webPreferences,\n' +
            '            preload: preloadPath,\n' +
            '            contextIsolation: true,\n' +
            '            nodeIntegration: false,\n' +
            '        },\n',
        patchedAnchor: '            ...webPreferences,\n            preload: preloadPath,',
    },
]);

applyReplacements('electron-plugin/dist/server/api/window.js', [
    {
        oldText: 'import { enable } from "@electron/remote/main/index.js";\n',
        newText: '',
        patchedAnchor: 'const router = express.Router();',
    },
    {
        oldText: '        nodeIntegration: true,\n',
        newText: '        nodeIntegration: false,\n',
        patchedAnchor: '        nodeIntegration: false,',
    },
    {
        oldText: '    enable(window.webContents);\n',
        newText: '',
        patchedAnchor: '    if (req.body.rememberState === true) {',
    },
    {
        oldText: '        state.windows[id].close();\n        delete state.windows[id];\n',
        newText: '        state.windows[id].close();\n',
        patchedAnchor: '        state.windows[id].close();\n',
    },
    {
        oldText: "    window.on('close', (evt) => {\n",
        newText: "    window.on('closed', () => {\n",
        patchedAnchor: "    window.on('closed', () => {",
    },
    {
        oldText: 'webPreferences: Object.assign(Object.assign({}, defaultWebPreferences), webPreferences),',
        newText:
            'webPreferences: Object.assign(Object.assign({}, defaultWebPreferences), webPreferences, { preload: preloadPath, contextIsolation: true, nodeIntegration: false }),',
        patchedAnchor:
            'webPreferences: Object.assign(Object.assign({}, defaultWebPreferences), webPreferences, { preload: preloadPath, contextIsolation: true, nodeIntegration: false }),',
    },
]);

for (const path of ['electron-plugin/src/server/api/menuBar.ts', 'electron-plugin/dist/server/api/menuBar.js']) {
    applyReplacements(path, [
        {
            oldText: 'import { enable } from "@electron/remote/main/index.js";\n',
            newText: '',
            patchedAnchor: 'const router = express.Router();',
        },
        {
            oldText: '                    nodeIntegration: true,\n',
            newText: '                    nodeIntegration: false,\n',
            patchedAnchor: '                    nodeIntegration: false,',
        },
        {
            oldText: '                    contextIsolation: false,\n',
            newText: '                    contextIsolation: true,\n',
            patchedAnchor: '                    contextIsolation: true,',
        },
        {
            oldText:
                '        state.activeMenuBar.on("after-create-window", () => {\n' +
                '            enable(state.activeMenuBar.window.webContents);\n' +
                '        });\n\n',
            newText: '',
            patchedAnchor: '        state.activeMenuBar.on("ready", () => {',
        },
    ]);
}

if (checkOnly) {
    if (changes.length === 0) {
        console.log('NativePHP Electron hardening patch is already applied.');
    } else {
        console.log(`NativePHP Electron hardening patch is applicable (${changes.length} files).`);
    }
} else if (changes.length === 0) {
    console.log('NativePHP Electron hardening patch is already applied.');
} else {
    console.log(`Applied NativePHP Electron hardening patch to ${changes.length} files.`);
}
