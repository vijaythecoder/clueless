// NativePHP Extension for Audio Loopback Support
// This extension provides system audio capture functionality for Electron apps

import { systemPreferences, ipcMain, shell } from 'electron';

    
// Store audio loopback state
const audioLoopbackState = {
  initialized: false,
  handlerSet: false
};

export default {
  // Hook into Electron lifecycle - called before app is ready
  beforeReady: async () => {
    console.log('[Extension] beforeReady hook called');
    
    // Method 1: Try to use electron-audio-loopback package if available
    try {
      const audioLoopbackModule = await import('electron-audio-loopback');
      const initAudioLoopback = audioLoopbackModule.initMain;
      
      if (initAudioLoopback) {
        console.log('[Extension] Initializing electron-audio-loopback package...');
        initAudioLoopback();
        audioLoopbackState.initialized = true;
        console.log('[Extension] electron-audio-loopback initialized successfully');
        
        // The package should register its own IPC handlers, but let's verify
        setTimeout(() => {
          const hasEnableHandler = ipcMain.listenerCount('enable-loopback-audio') > 0;
          const hasDisableHandler = ipcMain.listenerCount('disable-loopback-audio') > 0;
          console.log('[Extension] IPC handler check:', {
            'enable-loopback-audio': hasEnableHandler,
            'disable-loopback-audio': hasDisableHandler
          });
        }, 100);
        
        return; // Package handles everything, no need for manual implementation
      }
    } catch (error) {
      console.log('[Extension] electron-audio-loopback not available:', error.message);
      console.log('[Extension] Falling back to manual implementation...');
    }
  },
  
  // Hook into Electron lifecycle - called after app is ready
  afterReady: async (app, mainWindow) => {
    console.log('[Extension Test] afterReady hook called', {
      hasApp: !!app,
      hasMainWindow: !!mainWindow
    });
    // Add any setup that needs to happen after app is ready
  },
  
  // Hook into Electron lifecycle - called before app quits
  beforeQuit: async () => {
    console.log('[Extension Test] beforeQuit hook called');
    // Add any cleanup that needs to happen before app quits
  },
  
  // Custom IPC handlers for renderer communication
  ipcHandlers: {
    // Audio loopback handlers are now provided by the electron-audio-loopback package
    // We don't need to register them here to avoid conflicts
    
    // Microphone permission handlers
    'check-microphone-permission': async () => {
      if (process.platform !== 'darwin') {
        return { status: 'authorized' };
      }
      
      try {
        const permissions = await import('node-mac-permissions');
        const status = permissions.default.getAuthStatus('microphone');
        return { status };
      } catch (error) {
        console.error('[Extension] Error checking microphone permission:', error);
        return { status: 'not-determined', error: error.message };
      }
    },
    
    'request-microphone-permission': async () => {
      if (process.platform !== 'darwin') {
        return { granted: true };
      }
      
      try {
        const permissions = await import('node-mac-permissions');
        const status = await permissions.default.askForMicrophoneAccess();
        return { granted: status === 'authorized' };
      } catch (error) {
        console.error('[Extension] Error requesting microphone permission:', error);
        return { granted: false, error: error.message };
      }
    },
    
    // Screen capture permission handlers
    'check-screen-capture-permission': async () => {
      if (process.platform !== 'darwin') {
        return { status: 'authorized' };
      }
      
      try {
        const permissions = await import('node-mac-permissions');
        const status = permissions.default.getAuthStatus('screen');
        return { status };
      } catch (error) {
        console.error('[Extension] Error checking screen capture permission:', error);
        return { status: 'not-determined', error: error.message };
      }
    },
    
    'request-screen-capture-permission': async () => {
      if (process.platform !== 'darwin') {
        return { granted: true };
      }
      
      try {
        const permissions = await import('node-mac-permissions');
        // askForScreenCaptureAccess returns a boolean directly
        const granted = await permissions.default.askForScreenCaptureAccess();
        return { granted };
      } catch (error) {
        console.error('[Extension] Error requesting screen capture permission:', error);
        return { granted: false, error: error.message };
      }
    },
    
    // Open privacy settings handler
    'open-privacy-settings': async () => {
      console.log('[Extension] IPC handler open-privacy-settings called');
      
      if (process.platform !== 'darwin') {
        return { success: true };
      }
      
      try {
        await shell.openExternal('x-apple.systempreferences:com.apple.preference.security?Privacy');
        return { success: true };
      } catch (error) {
        console.error('[Extension] Error opening privacy settings:', error);
        return { success: false, error: error.message };
      }
    },
    
    // Test handler to verify IPC is working
    'test:ping': async (_event, ...args) => {
      console.log('[Extension Test] IPC handler test:ping called', { args });
      return { 
        success: true, 
        message: 'Pong! Extension IPC handlers are working!',
        timestamp: new Date().toISOString(),
        receivedArgs: args
      };
    },
    
    'test:echo': async (_event, message) => {
      console.log('[Extension Test] IPC handler test:echo called', { message });
      return { 
        success: true, 
        echo: message,
        processedAt: new Date().toISOString()
      };
    },
    
    'test:get-info': async () => {
      console.log('[Extension Test] IPC handler test:get-info called');
      return {
        success: true,
        info: {
          extensionVersion: '1.0.0',
          nodeVersion: process.version,
          platform: process.platform,
          arch: process.arch
        }
      };
    }
  },

  // Preload script extensions - APIs to expose to the renderer  
  // NOTE: The NativePHP preload already exposes window.audioLoopback, so we don't need to do it here
  // The frontend should use window.Native.ipcRendererInvoke['enable-loopback-audio']() which is already available
  
  // Custom API endpoints accessible from Laravel
  apiRoutes: (router) => {
    // Screen recording permission check for macOS
    router.get('/api/system/screen-recording-access', (req, res) => {
      console.log('[Extension] API route GET /api/system/screen-recording-access called');
      
      if (process.platform === 'darwin') {
        // On macOS, check if we have screen recording permission
        const status = systemPreferences.getMediaAccessStatus('screen');
        res.json({ 
          status,
          platform: 'darwin',
          hasAccess: status === 'granted'
        });
      } else {
        // Non-macOS platforms don't need this permission
        res.json({ 
          status: 'granted',
          platform: process.platform,
          hasAccess: true
        });
      }
    });
    
    // Test endpoint - GET
    router.get('/api/test/status', (req, res) => {
      console.log('[Extension Test] API route GET /api/test/status called');
      res.json({ 
        status: 'ok',
        message: 'Extension API routes are working!',
        timestamp: new Date().toISOString()
      });
    });
    
    // Test endpoint - POST
    router.post('/api/test/echo', (req, res) => {
      const { message } = req.body;
      console.log('[Extension Test] API route POST /api/test/echo called', { message });
      res.json({ 
        success: true,
        echo: message,
        processedAt: new Date().toISOString()
      });
    });
    
    // Test endpoint - GET with params
    router.get('/api/test/info/:param', (req, res) => {
      const { param } = req.params;
      console.log('[Extension Test] API route GET /api/test/info/:param called', { param });
      res.json({
        success: true,
        receivedParam: param,
        query: req.query,
        headers: req.headers
      });
    });
    
    // Media access status endpoint
    router.get('/api/system/media-access-status/:mediaType', (req, res) => {
      const { mediaType } = req.params;
      console.log('[Extension Test] API route GET /api/system/media-access-status/:mediaType called', { mediaType });
      
      if (process.platform === 'darwin') {
        const status = systemPreferences.getMediaAccessStatus(mediaType);
        res.json({ status });
      } else {
        res.json({ status: 'granted' }); // Non-macOS platforms don't have this API
      }
    });
    
    // Ask for media access endpoint
    router.post('/api/system/ask-for-media-access', async (req, res) => {
      const { mediaType } = req.body;
      console.log('[Extension Test] API route POST /api/system/ask-for-media-access called', { mediaType });
      
      if (process.platform === 'darwin') {
        try {
          const granted = await systemPreferences.askForMediaAccess(mediaType);
          res.json({ granted });
        } catch (e) {
          res.status(400).json({
            error: e.message,
          });
        }
      } else {
        res.json({ granted: true }); // Non-macOS platforms don't need this
      }
    });
  }
};