import { SystemAudioCapture } from '@/services/audioCapture';
import { int16ToBase64 } from '@/services/audioEncoding';
import { createSpeechAudioGate } from '@/services/audioSpeechGate';

export interface AudioChunkMetadata {
    level?: number;
}

type AudioHandler = (base64Pcm16: string, metadata: AudioChunkMetadata) => void;
type LocalPermission = 'microphone' | 'screen';

const PERMISSION_LABELS: Record<LocalPermission, string> = {
    microphone: 'Microphone',
    screen: 'Screen Recording',
};

export function useAudioSources() {
    let microphoneContext: AudioContext | null = null;
    let microphoneStream: MediaStream | null = null;
    let microphoneProcessor: AudioWorkletNode | null = null;
    let microphoneSilencer: GainNode | null = null;
    let systemCapture: SystemAudioCapture | null = null;

    const checkPermissions = async () => {
        if (!window.macPermissions || !window.systemAudio) {
            throw new Error('Local capture is only available in the Clueless desktop app.');
        }

        const availability = await window.systemAudio.isAvailable();
        if (!availability.available) {
            throw new Error('System audio capture is unavailable. Rebuild the macOS audio helper, then relaunch Clueless.');
        }

        await requirePermission('microphone');
        await requirePermission('screen');
    };

    const startMicrophone = async (onAudio: AudioHandler) => {
        const emitSpeechAudio = createSpeechAudioGate(onAudio);
        microphoneStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                channelCount: 1,
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            },
        });
        microphoneContext = new AudioContext({ latencyHint: 'interactive' });
        await microphoneContext.audioWorklet.addModule(new URL('/audio-worklets/pcm16-processor.js', window.location.origin).href);

        const source = microphoneContext.createMediaStreamSource(microphoneStream);
        microphoneProcessor = new AudioWorkletNode(microphoneContext, 'pcm16-processor', {
            numberOfInputs: 1,
            numberOfOutputs: 1,
            outputChannelCount: [1],
        });
        microphoneSilencer = microphoneContext.createGain();
        microphoneSilencer.gain.value = 0;
        microphoneProcessor.port.onmessage = (event: MessageEvent<{ pcm: ArrayBuffer; level: number }>) => {
            emitSpeechAudio(int16ToBase64(new Int16Array(event.data.pcm)), { level: event.data.level });
        };

        source.connect(microphoneProcessor);
        microphoneProcessor.connect(microphoneSilencer);
        microphoneSilencer.connect(microphoneContext.destination);
        await microphoneContext.resume();
    };

    const startSystemAudio = async (onAudio: AudioHandler, onError: (message: string) => void) => {
        try {
            const emitSpeechAudio = createSpeechAudioGate(onAudio);
            systemCapture = new SystemAudioCapture();
            systemCapture.on('audio', (audio: string | Int16Array, metadata?: AudioChunkMetadata) => {
                emitSpeechAudio(typeof audio === 'string' ? audio : int16ToBase64(audio), metadata ?? {});
            });
            systemCapture.on('error', (error: Error) => onError(error.message));
            systemCapture.on('status', (state: string) => {
                if (state === 'exited') onError('Customer audio capture stopped unexpectedly');
            });
            await systemCapture.start();
        } catch (error) {
            const message = error instanceof Error ? error.message : 'System audio capture unavailable';
            throw error instanceof Error ? error : new Error(message);
        }
    };

    const stop = async () => {
        microphoneStream?.getTracks().forEach((track) => track.stop());
        microphoneProcessor?.disconnect();
        microphoneSilencer?.disconnect();
        await microphoneContext?.close();
        microphoneStream = null;
        microphoneProcessor = null;
        microphoneSilencer = null;
        microphoneContext = null;

        if (systemCapture) {
            await systemCapture.stop();
            systemCapture.removeAllListeners();
            systemCapture = null;
        }
    };

    return {
        checkPermissions,
        startMicrophone,
        startSystemAudio,
        stop,
    };
}

async function requirePermission(permission: LocalPermission): Promise<void> {
    const bridge = window.macPermissions;
    if (!bridge) {
        throw new Error('Local capture is only available in the Clueless desktop app.');
    }

    const checked = await bridge.checkPermission(permission);
    let status = checked.success ? checked.status : undefined;

    if (status === 'not-determined') {
        const requested = await bridge.requestPermission(permission);
        status = requested.success ? requested.status : undefined;
    }

    if (status === 'authorized') {
        return;
    }

    const label = PERMISSION_LABELS[permission];
    if (status === 'restricted') {
        throw new Error(`${label} access is restricted by macOS policy.`);
    }

    if (status === 'denied') {
        throw new Error(`${label} access is denied. Grant it in System Settings, then try again.`);
    }

    throw new Error(`${label} access was not granted. Grant it in System Settings, then relaunch Clueless.`);
}
