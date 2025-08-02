// This module requires Electron environment with @electron/remote
interface AudioPacket {
    type: 'audio' | 'error' | 'status' | 'permission' | 'heartbeat';
    data?: string;
    message?: string;
    state?: string;
    granted?: boolean;
    code?: number;
    timestamp?: string;
    capturing?: boolean;
}

// Simple EventEmitter implementation for browser
class SimpleEventEmitter {
    private events: { [key: string]: ((...args: any[]) => void)[] } = {};

    on(event: string, listener: (...args: any[]) => void): void {
        if (!this.events[event]) {
            this.events[event] = [];
        }
        this.events[event].push(listener);
    }

    emit(event: string, ...args: any[]): void {
        if (this.events[event]) {
            this.events[event].forEach((listener) => listener(...args));
        }
    }

    removeAllListeners(): void {
        this.events = {};
    }
}

export class SystemAudioCapture extends SimpleEventEmitter {
    private process: any = null;
    private buffer: string = '';
    private isRestarting: boolean = false;
    private autoRestartEnabled: boolean = true;
    private lastErrorTime: number = 0;
    private errorCount: number = 0;
    private heartbeatCheckInterval: NodeJS.Timeout | null = null;
    private lastHeartbeat: Date | null = null;
    private readonly heartbeatTimeout = 15000; // 15 seconds

    constructor() {
        super();
        if (!window.remote) {
            throw new Error('SystemAudioCapture requires Electron environment with remote module');
        }
    }

    async checkPermission(): Promise<boolean> {
        return new Promise((resolve) => {
            // Access Node.js modules through remote
            const { spawn } = window.remote.require('child_process');
            const path = window.remote.require('path');
            const { app } = window.remote;

            // Path to the compiled Swift executable
            const appPath = app.getAppPath();
            let execPath: string;

            // In NativePHP, the app path includes vendor directory in development
            if (appPath.includes('vendor/nativephp/electron')) {
                // Development path - extract project root from app path
                const pathParts = appPath.split('/vendor/nativephp/electron');
                const projectRoot = pathParts[0];
                execPath = path.join(projectRoot, 'build', 'native', 'macos-audio-capture');
            } else {
                // Production path - executable is in the unpacked resources
                // app.getAppPath() returns something like /path/to/Resources/app.asar
                // We need to go to the unpacked directory
                const resourcesDir = path.dirname(appPath);
                execPath = path.join(resourcesDir, 'app.asar.unpacked', 'resources', 'app', 'native', 'macos-audio-capture', 'macos-audio-capture');
            }

            const tempProcess = spawn(execPath, [], {
                stdio: ['pipe', 'pipe', 'pipe'],
            });

            let buffer = '';
            let resolved = false;

            const handleData = (data: Buffer) => {
                buffer += data.toString();
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';

                for (const line of lines) {
                    if (!line.trim()) continue;
                    try {
                        const packet: AudioPacket = JSON.parse(line);
                        if (packet.type === 'permission' && !resolved) {
                            resolved = true;
                            tempProcess.kill();
                            resolve(packet.granted || false);
                        }
                    } catch {
                        // Ignore parse errors
                    }
                }
            };

            tempProcess.stdout?.on('data', handleData);

            // Send check permission command
            const json = JSON.stringify({ command: 'check_permission' }) + '\n';
            tempProcess.stdin.write(json);

            // Timeout after 5 seconds
            setTimeout(() => {
                if (!resolved) {
                    resolved = true;
                    tempProcess.kill();
                    resolve(false);
                }
            }, 5000);
        });
    }

    private async killExistingProcesses(): Promise<void> {
        try {
            const { execSync } = window.remote.require('child_process');
            // Kill any existing macos-audio-capture processes
            try {
                execSync('pkill -f macos-audio-capture', { encoding: 'utf8' });
                // Wait a bit for processes to die
                await new Promise((resolve) => setTimeout(resolve, 500));
            } catch {
                // pkill returns non-zero if no processes found, which is fine
            }
        } catch (error) {
            console.error('Failed to kill existing processes:', error);
        }
    }

    async start(): Promise<void> {
        if (this.process) {
            throw new Error('Audio capture already running');
        }

        // Kill any existing processes first
        await this.killExistingProcesses();

        // Access Node.js modules through remote
        const { spawn } = window.remote.require('child_process');
        const path = window.remote.require('path');
        const { app } = window.remote;

        // Path to the compiled Swift executable
        const appPath = app.getAppPath();
        let execPath: string;

        // In NativePHP, the app path includes vendor directory in development
        if (appPath.includes('vendor/nativephp/electron')) {
            // Development path - extract project root from app path
            // appPath is like: /Users/.../clueless/vendor/nativephp/electron/resources/js
            const pathParts = appPath.split('/vendor/nativephp/electron');
            const projectRoot = pathParts[0];
            execPath = path.join(projectRoot, 'build', 'native', 'macos-audio-capture');
        } else {
            // Production path - executable is in the unpacked resources
            // app.getAppPath() returns something like /path/to/Resources/app.asar
            // We need to go to the unpacked directory
            const resourcesDir = path.dirname(appPath);
            execPath = path.join(resourcesDir, 'app.asar.unpacked', 'resources', 'app', 'native', 'macos-audio-capture', 'macos-audio-capture');
        }


        // Check if executable exists
        const fs = window.remote.require('fs');
        if (!fs.existsSync(execPath)) {
            // Try to list what's in the build directory
            const buildDir = path.dirname(execPath);
            console.error('Build directory:', buildDir);
            try {
                const files = fs.readdirSync(path.dirname(buildDir));
                console.error('Parent directory contents:', files);
            } catch (e) {
                console.error('Cannot list directory:', e);
            }

            const error = new Error(`Audio capture executable not found at: ${execPath}`);
            this.emit('error', error);
            throw error;
        }


        // Spawn the Swift process
        this.process = spawn(execPath, [], {
            stdio: ['pipe', 'pipe', 'pipe'],
        });

        // Handle stdout (audio data and status)
        this.process.stdout?.on('data', (data: Buffer) => {
            this.buffer += data.toString();
            this.processBuffer();
        });

        // Handle stderr (debug info)
        this.process.stderr?.on('data', (data: Buffer) => {
            console.error('Audio capture stderr:', data.toString());
        });

        // Handle process exit
        this.process.on('exit', (code: number) => {
            this.emit('exit', code);
            this.process = null;
        });

        // Handle process error
        this.process.on('error', (error: Error) => {
            console.error('Audio capture process error:', error);
            this.emit('error', error);
            this.process = null;
        });

        // Send start command
        this.sendCommand({ command: 'start', sampleRate: 24000 });

        // Start heartbeat monitoring
        this.startHeartbeatMonitoring();
    }

    private startHeartbeatMonitoring(): void {
        this.stopHeartbeatMonitoring();

        this.heartbeatCheckInterval = setInterval(() => {
            if (this.lastHeartbeat) {
                const timeSinceLastHeartbeat = Date.now() - this.lastHeartbeat.getTime();
                if (timeSinceLastHeartbeat > this.heartbeatTimeout) {
                    console.error('❌ Audio capture heartbeat timeout');
                    this.emit('error', new Error('Audio capture process stopped responding'));

                    // Attempt to restart if auto-restart is enabled
                    if (this.autoRestartEnabled && !this.isRestarting) {
                            setTimeout(() => this.restart(), 1000);
                    }
                }
            }
        }, 5000); // Check every 5 seconds
    }

    private stopHeartbeatMonitoring(): void {
        if (this.heartbeatCheckInterval) {
            clearInterval(this.heartbeatCheckInterval);
            this.heartbeatCheckInterval = null;
        }
        this.lastHeartbeat = null;
    }

    async stop(): Promise<void> {
        if (!this.process) {
            return;
        }

        this.stopHeartbeatMonitoring();
        this.sendCommand({ command: 'stop' });

        // Give it time to stop gracefully
        setTimeout(() => {
            if (this.process) {
                this.process.kill();
                this.process = null;
            }
        }, 1000);
    }

    async restart(): Promise<void> {
        if (this.isRestarting) {
            return;
        }

        this.isRestarting = true;
        this.emit('status', 'restarting');

        try {
            // First try to send restart command to existing process
            if (this.process) {
                this.sendCommand({ command: 'restart' });
                // Wait for restart to complete
                await new Promise((resolve) => setTimeout(resolve, 2000));
            } else {
                // If no process, stop and start
                await this.stop();
                await new Promise((resolve) => setTimeout(resolve, 1000));
                await this.start();
            }
        } catch (error) {
            console.error('Failed to restart audio capture:', error);
            this.emit('error', error as Error);

            // Fallback: force stop and start
            try {
                if (this.process) {
                    this.process.kill();
                    this.process = null;
                }
                await new Promise((resolve) => setTimeout(resolve, 1000));
                await this.start();
            } catch (fallbackError) {
                console.error('Fallback restart also failed:', fallbackError);
                this.emit('error', fallbackError as Error);
            }
        } finally {
            this.isRestarting = false;
        }
    }

    setAutoRestart(enabled: boolean): void {
        this.autoRestartEnabled = enabled;
    }

    private sendCommand(command: any): void {
        if (!this.process?.stdin) {
            throw new Error('Process not running');
        }

        const json = JSON.stringify(command) + '\n';
        this.process.stdin.write(json);
    }

    private processBuffer(): void {
        const lines = this.buffer.split('\n');
        this.buffer = lines.pop() || '';

        for (const line of lines) {
            if (!line.trim()) continue;

            try {
                const packet: AudioPacket = JSON.parse(line);

                switch (packet.type) {
                    case 'audio':
                        if (packet.data) {
                            // Convert base64 to Uint8Array
                            const binaryString = atob(packet.data);
                            const bytes = new Uint8Array(binaryString.length);
                            for (let i = 0; i < binaryString.length; i++) {
                                bytes[i] = binaryString.charCodeAt(i);
                            }

                            // Emit audio data as Int16Array
                            const int16Array = new Int16Array(bytes.buffer);
                            this.emit('audio', int16Array);
                        }
                        break;

                    case 'status':
                        this.emit('status', packet.state);
                        break;

                    case 'heartbeat':
                        this.lastHeartbeat = new Date();
                        this.emit('heartbeat');
                        break;

                    case 'error':
                        const error = new Error(packet.message || 'Unknown error');
                        this.emit('error', error);

                        // Check for specific error codes
                        if (packet.code === 1003) {
                            // Process locked
                            console.error('❌ Another audio capture process is already running');
                            // Don't auto-restart for process lock errors
                            return;
                        }

                        // Track error frequency
                        const now = Date.now();
                        if (now - this.lastErrorTime < 5000) {
                            // Errors within 5 seconds
                            this.errorCount++;
                        } else {
                            this.errorCount = 1;
                        }
                        this.lastErrorTime = now;

                        // Auto-restart on certain errors if enabled
                        if (this.autoRestartEnabled && !this.isRestarting) {
                            const shouldRestart =
                                packet.code === 1004 || // Resource busy
                                packet.message?.includes('resource') ||
                                packet.message?.includes('busy') ||
                                packet.message?.includes('Audio capture failed') ||
                                this.errorCount >= 3;

                            if (shouldRestart) {
                                    setTimeout(() => this.restart(), 1000);
                            }
                        }
                        break;
                }
            } catch (e) {
                console.error('Failed to parse audio capture output:', e);
            }
        }
    }

    isRunning(): boolean {
        return this.process !== null;
    }
}

// Helper function to check if system audio capture is available
export async function isSystemAudioAvailable(): Promise<boolean> {
    try {
        // Check if we're in Electron environment with remote
        if (!window.remote) {
            return false;
        }

        const fs = window.remote.require('fs');
        const path = window.remote.require('path');
        const os = window.remote.require('os');
        const { app } = window.remote;

        // Path to the compiled Swift executable
        const appPath = app.getAppPath();
        let execPath: string;

        // In NativePHP, the app path includes vendor directory in development
        if (appPath.includes('vendor/nativephp/electron')) {
            // Development path - extract project root from app path
            const pathParts = appPath.split('/vendor/nativephp/electron');
            const projectRoot = pathParts[0];
            execPath = path.join(projectRoot, 'build', 'native', 'macos-audio-capture');
        } else {
            // Production path - executable is in the unpacked resources
            // app.getAppPath() returns something like /path/to/Resources/app.asar
            // We need to go to the unpacked directory
            const resourcesDir = path.dirname(appPath);
            execPath = path.join(resourcesDir, 'app.asar.unpacked', 'resources', 'app', 'native', 'macos-audio-capture', 'macos-audio-capture');
        }

        // Check if executable exists
        try {
            fs.accessSync(execPath);
        } catch {
            return false;
        }

        // Check macOS version (13.0+)
        const release = os.release();
        const majorVersion = parseInt(release.split('.')[0]);

        const isSupported = majorVersion >= 22; // macOS 13.0 corresponds to Darwin 22.x

        return isSupported;
    } catch (error) {
        console.error('Error checking system audio availability:', error);
        return false;
    }
}

// Add TypeScript declarations for window.remote
declare global {
    interface Window {
        remote?: any;
    }
}
