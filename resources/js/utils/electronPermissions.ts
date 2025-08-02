// Electron-specific permission handling
export async function requestMicrophonePermission(): Promise<boolean> {
    // In Electron, we need to check if we're in the main process context
    if (typeof window !== 'undefined' && (window as any).electron) {
        try {
            // Request permission through Electron IPC
            const result = await (window as any).electron.invoke('request-microphone-permission');
            return result;
        } catch (error) {
            console.error('Error requesting microphone permission through Electron:', error);
        }
    }

    // Fallback to browser API
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        // Immediately stop the stream after getting permission
        stream.getTracks().forEach((track) => track.stop());
        return true;
    } catch (error) {
        console.error('Microphone permission denied:', error);
        return false;
    }
}

export function isElectron(): boolean {
    return typeof window !== 'undefined' && navigator.userAgent.includes('Electron');
}

export async function checkMicrophoneAvailability(): Promise<{ available: boolean; message?: string; devices?: MediaDeviceInfo[] }> {
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const audioInputs = devices.filter((device) => device.kind === 'audioinput');


        if (audioInputs.length === 0) {
            return { available: false, message: 'No microphone devices found' };
        }

        // Check if we have permission to see device labels
        const hasPermission = audioInputs.some((device) => device.label !== '');

        return {
            available: true,
            message: hasPermission ? `Found ${audioInputs.length} microphone(s)` : 'Microphone found but permission needed',
            devices: audioInputs,
        };
    } catch (error) {
        console.error('Error checking microphone availability:', error);
        return { available: false, message: 'Error checking microphone devices' };
    }
}

export async function selectBestMicrophone(devices: MediaDeviceInfo[]): Promise<string | undefined> {
    // Filter out default/communications devices which might be virtual
    const nonDefaultDevices = devices.filter((d) => !d.label.toLowerCase().includes('default') && !d.label.toLowerCase().includes('communications'));

    // Prefer USB/external microphones
    const externalMic = nonDefaultDevices.find((d) => d.label.toLowerCase().includes('usb') || d.label.toLowerCase().includes('external'));

    if (externalMic) {
        return externalMic.deviceId;
    }

    // Use first non-default device
    if (nonDefaultDevices.length > 0) {
        return nonDefaultDevices[0].deviceId;
    }

    // Fallback to first available
    if (devices.length > 0) {
        return devices[0].deviceId;
    }

    return undefined;
}
