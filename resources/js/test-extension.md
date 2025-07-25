# Testing NativePHP Extension

This document shows how to test the extension system.

## Console Logging

When you run `composer native:dev`, you should see the following logs in the console:

1. `[Extension Test] beforeReady hook called` - Before app initialization
2. `[Extension Test] afterReady hook called` - After app is ready
3. `[Extension Test] beforeQuit hook called` - When closing the app

## Testing IPC Handlers

From the renderer process (in Vue components or browser console), you can test:

```javascript
// Test ping
const result = await window.Native.invoke('test:ping', 'arg1', 'arg2');
console.log(result); // { success: true, message: 'Pong!...', receivedArgs: ['arg1', 'arg2'] }

// Test echo
const echoResult = await window.Native.invoke('test:echo', 'Hello Extension!');
console.log(echoResult); // { success: true, echo: 'Hello Extension!', processedAt: '...' }

// Test get info
const info = await window.Native.invoke('test:get-info');
console.log(info); // { success: true, info: { extensionVersion: '1.0.0', ... } }
```

## Testing API Routes

From Laravel controllers or using curl:

```bash
# Test GET status
curl http://127.0.0.1:4000/api/test/status \
  -H "X-NativePHP-Secret: [secret]"

# Test POST echo
curl -X POST http://127.0.0.1:4000/api/test/echo \
  -H "Content-Type: application/json" \
  -H "X-NativePHP-Secret: [secret]" \
  -d '{"message": "Hello from Laravel!"}'

# Test GET with params
curl http://127.0.0.1:4000/api/test/info/myParam?query=value \
  -H "X-NativePHP-Secret: [secret]"
```

Note: The API port (4000) and secret will be different for each session. Check the console logs for the actual port.

## Verification Steps

1. Run `composer native:dev`
2. Check console for extension loading message: `[NativePHP] Loaded extension from: .../resources/js/nativephp-extension.js`
3. Check for lifecycle hooks being called
4. Open the app and test IPC handlers from browser console
5. Test API routes using curl or from Laravel code