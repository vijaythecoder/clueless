# Testing Audio Loopback Extension

This document explains how to test the audio loopback functionality that's now implemented via the extension system.

## Components Created

### 1. IPC Handlers (in `nativephp-extension.js`)
- `enable-loopback-audio` - Enables audio loopback for a device
- `disable-loopback-audio` - Disables audio loopback

### 2. Window APIs (in `nativephp-preload.js`)
- `window.audioLoopback.enableLoopback(deviceId)` - Enable loopback
- `window.audioLoopback.disableLoopback()` - Disable loopback
- `window.extensionTest.ping()` - Test the extension system
- `window.extensionTest.getInfo()` - Get extension information

## Testing from Browser Console

Once the app is running with `composer native:dev`, open the developer tools in the Electron app and try:

```javascript
// Test if extension system is working
await window.extensionTest.ping();
// Should return: { success: true, message: 'Pong!...', receivedArgs: ['from-preload'] }

// Get extension info
await window.extensionTest.getInfo();
// Should return: { success: true, info: { extensionVersion: '1.0.0', ... } }

// Enable audio loopback
await window.audioLoopback.enableLoopback('default');
// Should return: { success: true, deviceId: 'default', message: 'Audio loopback enabled (mock implementation)' }

// Disable audio loopback
await window.audioLoopback.disableLoopback();
// Should return: { success: true, message: 'Audio loopback disabled (mock implementation)' }
```

## What to Look For in Console Logs

When testing, you should see these logs:

1. **Extension Loading**:
   - `[NativePHP] Loading extensions from app path: /path/to/project`
   - `[NativePHP] Loaded extension from: /path/to/project/resources/js/nativephp-extension.js`
   - `[NativePHP] Exposed preload API: audioLoopback`
   - `[NativePHP] Exposed preload API: extensionTest`

2. **IPC Handler Registration**:
   - `[NativePHP] Registered IPC handler: enable-loopback-audio`
   - `[NativePHP] Registered IPC handler: disable-loopback-audio`

3. **When Calling Functions**:
   - `[Preload Extension] Calling enable-loopback-audio`
   - `[Extension Test] IPC handler enable-loopback-audio called`

## Important Notes

1. **Path Resolution Issue**: Currently, the preload loader uses `process.cwd()` which might not resolve to the correct path. If the preload APIs aren't available, you may need to temporarily copy `nativephp-preload.js` to the package directory.

2. **Mock Implementation**: The current implementation is a mock. The actual audio loopback logic (using electron-audio-loopback) would need to be implemented in the IPC handlers.

3. **Error Handling**: If you see errors about `window.audioLoopback` being undefined, it means the preload extension didn't load properly.

## Next Steps

To implement actual audio loopback functionality:
1. Import and use electron-audio-loopback methods in the IPC handlers
2. Handle device selection and audio routing
3. Add error handling for permission issues
4. Implement proper cleanup in the disable handler