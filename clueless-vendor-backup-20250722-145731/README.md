# Clueless Vendor Files Backup

This backup was created on 2025-07-22 at 14:57:31

## Modified Files

### 1. entitlements.mac.plist
- **Location**: `vendor/nativephp/electron/resources/js/build/entitlements.mac.plist`
- **Changes**: Added `com.apple.security.device.audio-input` entitlement for microphone access

### 2. system.ts
- **Location**: `vendor/nativephp/electron/resources/js/electron-plugin/src/server/api/system.ts`
- **Changes**: Added two new endpoints:
  - `GET /api/system/media-access-status/:mediaType` - Check permission status
  - `POST /api/system/ask-for-media-access` - Request permission programmatically

### 3. index.ts
- **Location**: `vendor/nativephp/electron/resources/js/electron-plugin/src/index.ts` 
- **Changes**: Added electron-audio-loopback initialization

### 4. index.mts
- **Location**: `vendor/nativephp/electron/resources/js/electron-plugin/src/preload/index.mts`
- **Changes**: Exposed audio loopback functions to renderer process

## Purpose
These changes were made to:
1. Fix microphone permission issues in the macOS build
2. Integrate electron-audio-loopback for system audio capture
3. Replace Swift-based audio capture with electron-audio-loopback

## Restoration
To restore these files after a composer update:
```bash
# 1. Copy all the backup files to their respective locations
cp entitlements.mac.plist /path/to/project/vendor/nativephp/electron/resources/js/build/
cp system.ts /path/to/project/vendor/nativephp/electron/resources/js/electron-plugin/src/server/api/
cp index.ts /path/to/project/vendor/nativephp/electron/resources/js/electron-plugin/src/
cp index.mts /path/to/project/vendor/nativephp/electron/resources/js/electron-plugin/src/preload/
cp electron-builder.js /path/to/project/vendor/nativephp/electron/resources/js/

# 2. IMPORTANT: Build the TypeScript files to JavaScript
cd /path/to/project/vendor/nativephp/electron/resources/js
npm run plugin:build

# The build command compiles the TypeScript source files (.ts) to JavaScript (.js) files
# that the Electron app actually uses. Without this step, your changes won't take effect!
```

## Notes
- The `electron-builder.js` file contains macOS permission descriptions for microphone, camera, and screen recording
- After copying the files, you MUST run `npm run plugin:build` to compile the TypeScript changes
- The build may show TypeScript warnings about electron-audio-loopback - these can be safely ignored