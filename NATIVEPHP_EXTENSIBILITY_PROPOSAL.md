# NativePHP Extensibility System Proposal

## Executive Summary

This proposal introduces a plugin/extension system for NativePHP that allows developers to extend Electron functionality without modifying vendor files. This solves a critical pain point where developers currently must fork NativePHP or lose customizations on updates.

## Problem Statement

Currently, developers who need to extend NativePHP's Electron functionality must:
- ❌ Modify vendor files (lost on composer update)
- ❌ Fork the entire package (maintenance burden)
- ❌ Use hacky workarounds
- ❌ Give up on advanced Electron features

**Real examples from the community:**
- "How do I add custom IPC handlers?"
- "I need to initialize electron-audio-loopback before app ready"
- "How can I add a system tray without forking?"
- "I want to register a custom protocol handler"

## Proposed Solution

### Extension File Structure

Developers create extension files in their project (not vendor):

```javascript
// resources/js/nativephp-extension.js
export default {
    // Hook into Electron lifecycle
    beforeReady: (app) => {
        // Initialize things before app is ready
        // e.g., protocol registration, early IPC handlers
    },
    
    afterReady: (app, mainWindow) => {
        // Setup after app is ready
        // e.g., tray icons, global shortcuts, window customization
    },
    
    beforeQuit: () => {
        // Cleanup before app quits
    },
    
    // Custom IPC handlers
    ipcHandlers: {
        'my-app:custom-action': async (event, ...args) => {
            // Handle custom IPC calls from renderer
            return { success: true, data: 'result' };
        }
    },
    
    // Custom API endpoints
    apiRoutes: (router) => {
        router.post('/api/my-app/custom-endpoint', (req, res) => {
            // Handle HTTP requests from Laravel
            res.json({ status: 'ok' });
        });
    },
    
    // Extend preload script
    preload: {
        exposeAPIs: {
            myApp: {
                doSomething: () => ipcRenderer.invoke('my-app:custom-action'),
                getSomething: () => ipcRenderer.invoke('my-app:get-data')
            }
        }
    }
};
```

### Implementation in NativePHP Core

Only ~100 lines of changes needed across 4 files:

#### 1. New Extension Loader (50 lines)
```typescript
// electron-plugin/src/extensions/loader.ts
export async function loadUserExtensions() {
    const extensions = [];
    
    // Load single extension file
    const singleExtPath = path.join(process.cwd(), 'resources/js/nativephp-extension.js');
    if (fs.existsSync(singleExtPath)) {
        extensions.push(require(singleExtPath).default);
    }
    
    // Load from extensions directory
    const extensionsDir = path.join(process.cwd(), 'resources/js/nativephp-extensions');
    if (fs.existsSync(extensionsDir)) {
        // Load all .js files as extensions
    }
    
    return extensions;
}
```

#### 2. Hook into Main Process (15 lines added)
```typescript
// electron-plugin/src/index.ts
const extensions = await loadUserExtensions();

// Before app ready
for (const ext of extensions) {
    if (ext.beforeReady) await ext.beforeReady(app);
}

// After app ready
app.whenReady().then(() => {
    for (const ext of extensions) {
        if (ext.afterReady) await ext.afterReady(app, mainWindow);
        
        // Register IPC handlers
        if (ext.ipcHandlers) {
            Object.entries(ext.ipcHandlers).forEach(([channel, handler]) => {
                ipcMain.handle(channel, handler);
            });
        }
    }
});
```

#### 3. API Routes (10 lines added)
```typescript
// electron-plugin/src/server/index.ts
const extensions = await loadUserExtensions();
for (const ext of extensions) {
    if (ext.apiRoutes) {
        const router = Router();
        ext.apiRoutes(router);
        app.use(router);
    }
}
```

#### 4. Preload Extensions (20 lines added)
```typescript
// electron-plugin/src/preload/index.mts
const preloadPath = path.join(__dirname, '../../../resources/js/nativephp-preload.js');
if (fs.existsSync(preloadPath)) {
    const userPreload = require(preloadPath);
    if (userPreload.exposeAPIs) {
        Object.entries(userPreload.exposeAPIs).forEach(([name, api]) => {
            contextBridge.exposeInMainWorld(name, api);
        });
    }
}
```

## Real-World Examples

### Example 1: Audio Capture Extension
```javascript
import { initMain as initAudioLoopback } from "electron-audio-loopback";

export default {
    beforeReady: (app) => {
        initAudioLoopback(); // Initialize before app ready
    },
    
    ipcHandlers: {
        'audio:enable-loopback': async () => {
            // Implementation
            return { enabled: true };
        }
    },
    
    apiRoutes: (router) => {
        router.post('/api/audio/enable', (req, res) => {
            // Enable system audio capture
            res.json({ status: 'enabled' });
        });
    }
};
```

### Example 2: System Tray Extension
```javascript
import { Tray, Menu } from 'electron';

let tray = null;

export default {
    afterReady: (app, mainWindow) => {
        tray = new Tray('/path/to/icon.png');
        tray.setToolTip('My App');
        
        const menu = Menu.buildFromTemplate([
            { label: 'Show', click: () => mainWindow.show() },
            { label: 'Quit', click: () => app.quit() }
        ]);
        
        tray.setContextMenu(menu);
    }
};
```

### Example 3: Global Shortcuts
```javascript
import { globalShortcut } from 'electron';

export default {
    afterReady: (app, mainWindow) => {
        globalShortcut.register('CommandOrControl+Shift+Y', () => {
            mainWindow.webContents.send('shortcut:triggered');
        });
    },
    
    beforeQuit: () => {
        globalShortcut.unregisterAll();
    }
};
```

## Benefits

### For Developers
- ✅ **No vendor modifications** - Extensions live in your codebase
- ✅ **Survives updates** - Composer update won't break customizations
- ✅ **Full Electron access** - Use any Electron API
- ✅ **Shareable** - Package and share extensions

### For NativePHP
- ✅ **Reduces fork pressure** - Fewer reasons to fork
- ✅ **Enables ecosystem** - Community can build extensions
- ✅ **Keeps core lean** - Features as extensions, not core
- ✅ **Innovation platform** - Developers can experiment

## Security Considerations

- Extensions run in main process (full access like app code)
- No additional security risks (developers already have full access)
- Optional: Add permission system in future versions

## Backwards Compatibility

- ✅ Fully backwards compatible
- ✅ No breaking changes
- ✅ Extensions are opt-in
- ✅ Existing apps work unchanged

## Implementation Plan

1. **Week 1**: Core implementation
   - Extension loader
   - Lifecycle hooks
   - IPC handler registration

2. **Week 2**: API integration
   - API route registration
   - Preload extensions
   - Error handling

3. **Week 3**: Documentation
   - Extension guide
   - API reference
   - Example extensions

4. **Week 4**: Community
   - Example repository
   - Video tutorials
   - Community feedback

## Future Enhancements

- Extension dependencies management
- Extension marketplace/registry
- GUI for managing extensions
- Hot-reload during development
- TypeScript definitions

## Summary

This minimal change (~100 lines) would:
- Solve a major developer pain point
- Enable unlimited extensibility
- Build a thriving ecosystem
- Position NativePHP as the most flexible Electron framework

The implementation is simple, backwards compatible, and opens up endless possibilities for the community.