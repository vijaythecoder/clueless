import remote from "@electron/remote";
import {ipcRenderer} from "electron";

const Native = {
    on: (event, callback) => {
        ipcRenderer.on('native-event', (_, data) => {
            // Strip leading slashes
            event = event.replace(/^(\\)+/, '');
            data.event = data.event.replace(/^(\\)+/, '');

            if (event === data.event) {
                return callback(data.payload, event);
            }
        })
    },
    contextMenu: (template) => {
        let menu = remote.Menu.buildFromTemplate(template);
        menu.popup({ window: remote.getCurrentWindow() });
    }
};

// @ts-ignore
window.Native = Native;

// @ts-ignore
window.remote = remote;

// Expose audio loopback functions
// Since contextIsolation is false, we attach directly to window
// @ts-ignore
window.audioLoopback = {
  enableLoopback: () => ipcRenderer.invoke('enable-loopback-audio'),
  disableLoopback: () => ipcRenderer.invoke('disable-loopback-audio')
};

ipcRenderer.on('log', (event, {level, message, context}) => {
    if (level === 'error') {
      console.error(`[${level}] ${message}`, context)
    } else if (level === 'warn') {
      console.warn(`[${level}] ${message}`, context)
    } else {
      console.log(`[${level}] ${message}`, context)
    }
});

// Add Livewire event listeners
ipcRenderer.on('native-event', (event, data) => {

  // Strip leading slashes
  data.event = data.event.replace(/^(\\)+/, '');

  // add support for livewire 3
  // @ts-ignore
  if (window.Livewire) {
    // @ts-ignore
    window.Livewire.dispatch('native:' + data.event, data.payload);
  }

  // add support for livewire 2
  // @ts-ignore
  if (window.livewire) {
    // @ts-ignore
    window.livewire.components.components().forEach(component => {
      if (Array.isArray(component.listeners)) {
        component.listeners.forEach(event => {
          if (event.startsWith('native')) {
            let event_parts = event.split(/(native:|native-)|:|,/)

            if (event_parts[1] == 'native:') {
              event_parts.splice(2, 0, 'private', undefined, 'nativephp', undefined)
            }

            let [
              s1,
              signature,
              channel_type,
              s2,
              channel,
              s3,
              event_name,
            ] = event_parts

            if (data.event === event_name) {
              // @ts-ignore
              window.livewire.emit(event, data.payload)
            }
          }
        })
      }
    })
  }
})
