#!/usr/bin/env node

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import process from 'node:process';

const projectRoot = resolve(import.meta.dirname, '..');
const canonicalBinary = 'native/macos-audio-capture/macos-audio-capture';
const requiredArchitectures = new Set(['arm64', 'x86_64']);
const failures = [];

function read(relativePath) {
    return readFileSync(resolve(projectRoot, relativePath), 'utf8');
}

function requireMatch(relativePath, pattern, message) {
    if (!pattern.test(read(relativePath))) {
        failures.push(`${relativePath}: ${message}`);
    }
}

function rejectMatch(relativePath, pattern, message) {
    if (pattern.test(read(relativePath))) {
        failures.push(`${relativePath}: ${message}`);
    }
}

requireMatch('config/nativephp.php', /'app_id'\s*=>\s*env\(/, 'app_id must use the NativePHP app_id key');
requireMatch('build-swift-audio.sh', new RegExp(canonicalBinary), `must build ${canonicalBinary}`);
requireMatch('native/macos-audio-capture/build.sh', /build-swift-audio\.sh/, 'must delegate to the canonical root build script');
requireMatch('native/macos-audio-capture/AudioCapture.swift', /"level":\s*rmsLevel/, 'audio packets must include RMS level');
requireMatch('resources/js/nativephp-extension.js', new RegExp(canonicalBinary), `must launch ${canonicalBinary}`);
requireMatch('config/nativephp.php', /apply-nativephp-electron-patch\.mjs/, 'prebuild must apply the tracked Electron patch');

for (const relativePath of ['build-swift-audio.sh', 'native/macos-audio-capture/build.sh', 'resources/js/nativephp-extension.js']) {
    rejectMatch(relativePath, /build\/native\/macos-audio-capture|extras\/macos-audio-capture/, 'contains a stale helper path');
}

rejectMatch('resources/js/nativephp-extension.js', /@electron\/remote|pkill/, 'must not expose remote modules or kill by process name');

const patchScriptPath = resolve(projectRoot, 'scripts/apply-nativephp-electron-patch.mjs');
const preloadOverridePath = resolve(projectRoot, 'native/electron/overrides/preload/index.mjs');
const mainOverridePath = resolve(projectRoot, 'native/electron/overrides/main/index.js');
if (!existsSync(patchScriptPath) || !existsSync(preloadOverridePath) || !existsSync(mainOverridePath)) {
    failures.push('NativePHP Electron patch script or source overrides are missing');
} else {
    const patchScript = readFileSync(patchScriptPath, 'utf8');
    const preloadOverride = readFileSync(preloadOverridePath, 'utf8');
    const mainOverride = readFileSync(mainOverridePath, 'utf8');

    if (!preloadOverride.includes("contextBridge.exposeInMainWorld('systemAudio'")) {
        failures.push('native/electron/overrides/preload/index.mjs: systemAudio bridge is missing');
    }
    if (preloadOverride.includes('@electron/remote') || preloadOverride.includes('window.remote')) {
        failures.push('native/electron/overrides/preload/index.mjs: renderer remote exposure remains');
    }
    if (!patchScript.includes('nodeIntegration: false')) {
        failures.push('scripts/apply-nativephp-electron-patch.mjs: renderer Node integration is not disabled');
    }
    if (!patchScript.includes('@electron/remote/main/index.js')) {
        failures.push('scripts/apply-nativephp-electron-patch.mjs: @electron/remote main removal is missing');
    }
    if (!mainOverride.includes('createNativePhpExtension')) {
        failures.push('native/electron/overrides/main/index.js: project extension loader is missing');
    }
}

const binaryPath = resolve(projectRoot, canonicalBinary);
if (!existsSync(binaryPath)) {
    failures.push(`${canonicalBinary}: canonical helper binary is missing`);
} else {
    if ((statSync(binaryPath).mode & 0o111) === 0) {
        failures.push(`${canonicalBinary}: helper is not executable`);
    }

    try {
        const architectures = execFileSync('lipo', ['-archs', binaryPath], { encoding: 'utf8' }).trim().split(/\s+/);

        for (const architecture of requiredArchitectures) {
            if (!architectures.includes(architecture)) {
                failures.push(`${canonicalBinary}: missing ${architecture} architecture (found: ${architectures.join(', ')})`);
            }
        }
    } catch (error) {
        failures.push(`${canonicalBinary}: unable to inspect architectures (${error.message})`);
    }
}

const artifactArgument = process.argv.indexOf('--app');
if (artifactArgument !== -1) {
    const appBundle = process.argv[artifactArgument + 1];
    if (!appBundle) {
        failures.push('--app requires a path to an exported .app bundle');
    } else {
        const packagedBinary = resolve(appBundle, 'Contents/Resources/app.asar.unpacked/resources/app', canonicalBinary);

        if (!existsSync(packagedBinary)) {
            failures.push(`${packagedBinary}: packaged helper is missing`);
        } else {
            const packagedArchitectures = execFileSync('lipo', ['-archs', packagedBinary], { encoding: 'utf8' }).trim().split(/\s+/);

            for (const architecture of requiredArchitectures) {
                if (!packagedArchitectures.includes(architecture)) {
                    failures.push(`${packagedBinary}: packaged helper is missing ${architecture}`);
                }
            }
        }
    }
}

if (failures.length > 0) {
    console.error('NativePHP packaging verification failed:');
    for (const failure of failures) {
        console.error(`- ${failure}`);
    }
    process.exit(1);
}

console.log(`NativePHP packaging verification passed (${canonicalBinary}: arm64, x86_64).`);
