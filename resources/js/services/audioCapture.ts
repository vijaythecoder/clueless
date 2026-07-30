interface AudioPacket {
    type: 'audio' | 'error' | 'status' | 'permission' | 'heartbeat';
    data?: string;
    level?: number;
    message?: string;
    state?: string;
    granted?: boolean;
    code?: number;
}

class SimpleEventEmitter {
    private events = new Map<string, Set<(...args: any[]) => void>>();

    on(event: string, listener: (...args: any[]) => void): void {
        const listeners = this.events.get(event) ?? new Set();
        listeners.add(listener);
        this.events.set(event, listeners);
    }

    emit(event: string, ...args: any[]): void {
        this.events.get(event)?.forEach((listener) => listener(...args));
    }

    removeAllListeners(): void {
        this.events.clear();
    }
}

export class SystemAudioCapture extends SimpleEventEmitter {
    private running = false;
    private unsubscribe: (() => void) | null = null;
    private heartbeatInterval: ReturnType<typeof setInterval> | null = null;
    private lastHeartbeat = 0;

    constructor() {
        super();
        if (!window.systemAudio) {
            throw new Error('System audio capture is unavailable outside the NativePHP desktop app');
        }
    }

    async checkPermission(): Promise<boolean> {
        const result = await window.macPermissions?.checkPermission('screen');
        return result?.status === 'authorized';
    }

    async start(): Promise<void> {
        if (this.running) throw new Error('System audio capture is already running');
        if (!window.systemAudio) throw new Error('System audio bridge is unavailable');

        const availability = await window.systemAudio.isAvailable();
        if (!availability.available) {
            throw new Error('System audio capture requires macOS 13 or later and the packaged audio helper');
        }

        this.unsubscribe = window.systemAudio.onPacket((packet) => this.handlePacket(packet));
        try {
            await window.systemAudio.start({ sampleRate: 24_000 });
            this.running = true;
            this.lastHeartbeat = Date.now();
            this.startHeartbeatMonitor();
        } catch (error) {
            this.unsubscribe?.();
            this.unsubscribe = null;
            throw error;
        }
    }

    async stop(): Promise<void> {
        this.stopHeartbeatMonitor();
        try {
            if (this.running) await window.systemAudio?.stop();
        } finally {
            this.running = false;
            this.unsubscribe?.();
            this.unsubscribe = null;
        }
    }

    async restart(): Promise<void> {
        await this.stop();
        await this.start();
    }

    setAutoRestart(enabled: boolean): void {
        // Process ownership and cleanup are enforced in the main process.
        void enabled;
    }

    isRunning(): boolean {
        return this.running;
    }

    private handlePacket(packet: AudioPacket): void {
        switch (packet.type) {
            case 'audio':
                if (packet.data) this.emit('audio', packet.data, { level: packet.level });
                break;
            case 'status':
                if (packet.state === 'exited' || packet.state === 'stopped') {
                    this.running = false;
                    this.stopHeartbeatMonitor();
                }
                this.emit('status', packet.state);
                break;
            case 'heartbeat':
                this.lastHeartbeat = Date.now();
                this.emit('heartbeat');
                break;
            case 'permission':
                this.emit('permission', Boolean(packet.granted));
                break;
            case 'error':
                this.emit('error', new Error(packet.message || 'System audio capture failed'));
                break;
        }
    }

    private startHeartbeatMonitor() {
        this.stopHeartbeatMonitor();
        this.heartbeatInterval = globalThis.setInterval(() => {
            if (this.running && Date.now() - this.lastHeartbeat > 15_000) {
                this.emit('error', new Error('System audio capture stopped responding'));
            }
        }, 5_000);
    }

    private stopHeartbeatMonitor() {
        if (this.heartbeatInterval) globalThis.clearInterval(this.heartbeatInterval);
        this.heartbeatInterval = null;
    }
}

export async function isSystemAudioAvailable(): Promise<boolean> {
    if (!window.systemAudio) return false;
    try {
        return (await window.systemAudio.isAvailable()).available;
    } catch {
        return false;
    }
}
