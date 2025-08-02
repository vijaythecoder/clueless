# Electron Audio Loopback

An Electron plugin for capturing system audio loopback on macOS 12.3+, Windows 10+ and Linux without any third-party loopback drivers or dependencies.

To play around with a full example, check out the [mic-speaker-streamer](https://github.com/alectrocute/mic-speaker-streamer) repo. It's a simple app that allows you to simultaneously stream your microphone and system audio to a third-party transcription API while also recording both streams into a WAV file.

## Real-World Usage

If your app is using Electron Audio Loopback, [make a PR](https://github.com/alectrocute/electron-audio-loopback/pulls) to add it to the list below!

- [mic-speaker-streamer](https://github.com/alectrocute/mic-speaker-streamer): An example microphone/system audio transcription app using OpenAI's Realtime API.

## Installation

```bash
npm install electron-audio-loopback
```

## Usage

### Main Process Setup

```javascript
const { app } = require('electron');
const { initMain } = require('electron-audio-loopback');

// Initialize this plugin in your main process
// before the app is ready. Simple!
initMain();

app.whenReady().then(() => {
  // Your app initialization...
});
```

### Renderer Process Usage

#### Automatic Mode

If `nodeIntegration` is enabled in your renderer process, then you can import the renderer helper function directly. This will take care of everything for you in one line of code.

```javascript
const { getLoopbackAudioMediaStream } = require('electron-audio-loopback');

// Get a MediaStream with system audio loopback
const stream = await getLoopbackAudioMediaStream();

// The stream contains only audio tracks
const audioTracks = stream.getAudioTracks();
console.log('Audio tracks:', audioTracks);

// Use the stream with an audio element or Web Audio API
const audioElement = document.getElementById('audio');
audioElement.srcObject = stream;
audioElement.play();
```

If you don't want to remove the video tracks, you can pass `removeVideo: false` to the `getLoopbackAudioMediaStream` function.

#### Manual Mode

If you do not have `nodeIntegration` enabled in your renderer process, then you'll need to manually initialize the plugin via IPC. See the example below:

```javascript
// preload.js
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  enableLoopbackAudio: () => ipcRenderer.invoke('enable-loopback-audio'),
  disableLoopbackAudio: () => ipcRenderer.invoke('disable-loopback-audio')
});

// renderer.js
async function getLoopbackAudioMediaStream() {
    // Tell the main process to enable system audio loopback.
    // This will override the default `getDisplayMedia` behavior.
    await window.electronAPI.enableLoopbackAudio();

    // Get a MediaStream with system audio loopback.
    // `getDisplayMedia` will fail if you don't request `video: true`.
    const stream = await navigator.mediaDevices.getDisplayMedia({ 
      video: true,
      audio: true,
    });
    
    // Remove video tracks that we don't need.
    // Note: You may find bugs if you don't remove video tracks.
    const videoTracks = stream.getVideoTracks();

    videoTracks.forEach(track => {
        track.stop();
        stream.removeTrack(track);
    });

    // Tell the main process to disable system audio loopback.
    // This will restore full `getDisplayMedia` functionality.
    await window.electronAPI.disableLoopbackAudio();
    
    // Boom! You've got a MediaStream with system audio loopback.
    // Use it with an audio element or Web Audio API.
    return stream;
}
```

## API Reference

### Main Process Functions

- `initMain(options?: InitMainOptions)`: Initialize the plugin in the main process. Must be called before the app is ready.
  - `sourcesOptions`: The options to pass to the `desktopCapturer.getSources` method.
  - `forceCoreAudioTap`: Whether to force the use of the Core Audio API on macOS (can be used to bypass bugs for certain macOS versions).

### Renderer Process Functions

- `getLoopbackAudioMediaStream(options?: GetLoopbackAudioMediaStreamOptions)`: Helper function that returns a Promise, resolves to a `MediaStream` containing system audio loopback. Video tracks are automatically removed from the stream.
  - `removeVideo`: Whether to remove the video tracks from the stream. Defaults to `true`.

### IPC Handlers

The plugin registers these IPC handlers automatically, ensure you don't override them!

- `enable-loopback-audio`: Enables system audio loopback capture
- `disable-loopback-audio`: Disables system audio loopback capture

## Requirements

- Electron >= 31.0.1
- macOS 12.3+
- Windows 10+
- Most Linux distros

## Development

### Prerequisites

- Node.js 18+
- npm or yarn

### Setup

```bash
# Install dependencies
npm install

# Build the project
npm run build

# Development mode with watch
npm run dev

# Lint code
npm run lint

# Run example
npm test
```

PR's welcome!

### Project Structure

```bash
src/
├── index.ts          # Main entry point with conditional exports
├── main.ts           # Main process initialization
├── config.ts         # Configuration
├── types.d.ts        # Type definitions
└── renderer.ts       # Renderer process helper function
```

## License

MIT © Alec Armbruster [@alectrocute](https://github.com/alectrocute)