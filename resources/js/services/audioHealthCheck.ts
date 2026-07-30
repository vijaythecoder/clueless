import type { SystemAudioCapture } from './audioCapture';

export interface AudioHealthStatus {
    isHealthy: boolean;
    status: 'active' | 'inactive' | 'error' | 'restarting' | 'initializing';
    lastHeartbeat: Date | null;
    errorCount: number;
    lastError: string | null;
    uptimeSeconds: number;
    processRunning: boolean;
    recommendations: string[];
}

export class AudioHealthMonitor {
    private audioCapture: SystemAudioCapture | null = null;
    private startTime: Date | null = null;
    private status: AudioHealthStatus = {
        isHealthy: false,
        status: 'inactive',
        lastHeartbeat: null,
        errorCount: 0,
        lastError: null,
        uptimeSeconds: 0,
        processRunning: false,
        recommendations: [],
    };
    private updateInterval: ReturnType<typeof setInterval> | null = null;
    private listeners: Array<(status: AudioHealthStatus) => void> = [];

    constructor() {
        // Start monitoring
        this.startMonitoring();
    }

    attach(audioCapture: SystemAudioCapture): void {
        this.audioCapture = audioCapture;
        this.startTime = new Date();

        // Listen to audio capture events
        audioCapture.on('status', (state: string) => {
            this.updateStatus(state);
        });

        audioCapture.on('error', (error: Error) => {
            this.handleError(error);
        });

        audioCapture.on('heartbeat', () => {
            this.status.lastHeartbeat = new Date();
        });
    }

    private startMonitoring(): void {
        this.updateInterval = setInterval(() => {
            this.checkHealth();
        }, 2000); // Check every 2 seconds
    }

    private updateStatus(state: string): void {
        switch (state) {
            case 'capturing':
                this.status.status = 'active';
                this.status.processRunning = true;
                break;
            case 'stopped':
                this.status.status = 'inactive';
                this.status.processRunning = false;
                break;
            case 'restarting':
                this.status.status = 'restarting';
                break;
            case 'retrying':
                this.status.status = 'error';
                break;
            default:
                this.status.status = 'inactive';
        }

        this.notifyListeners();
    }

    private handleError(error: Error): void {
        this.status.errorCount++;
        this.status.lastError = error.message;
        this.status.isHealthy = false;

        // Generate recommendations based on error
        this.generateRecommendations();
        this.notifyListeners();
    }

    private checkHealth(): void {
        // Update uptime
        if (this.startTime && this.status.processRunning) {
            this.status.uptimeSeconds = Math.floor((Date.now() - this.startTime.getTime()) / 1000);
        }

        // Check heartbeat health
        const heartbeatHealthy = this.checkHeartbeatHealth();

        // Overall health check
        this.status.isHealthy = this.status.processRunning && this.status.status === 'active' && heartbeatHealthy && this.status.errorCount < 5;

        this.generateRecommendations();
        this.notifyListeners();
    }

    private checkHeartbeatHealth(): boolean {
        if (!this.status.lastHeartbeat) return true; // No heartbeat yet is OK

        const timeSinceHeartbeat = Date.now() - this.status.lastHeartbeat.getTime();
        return timeSinceHeartbeat < 20000; // 20 seconds
    }

    private generateRecommendations(): void {
        this.status.recommendations = [];

        if (this.status.errorCount > 3) {
            this.status.recommendations.push('High error rate detected. Consider restarting the session.');
        }

        if (this.status.lastError?.includes('permission')) {
            this.status.recommendations.push('Grant Screen Recording permission in System Preferences.');
        }

        if (this.status.lastError?.includes('resource')) {
            this.status.recommendations.push('Close other screen recording apps and restart audio capture.');
        }

        if (!this.checkHeartbeatHealth() && this.status.processRunning) {
            this.status.recommendations.push('Audio capture process may be unresponsive. Click "Restart Audio".');
        }

        if (this.status.status === 'error' && this.status.errorCount > 5) {
            this.status.recommendations.push('Persistent errors detected. Try restarting the entire application.');
        }
    }

    getStatus(): AudioHealthStatus {
        return { ...this.status };
    }

    subscribe(listener: (status: AudioHealthStatus) => void): () => void {
        this.listeners.push(listener);

        // Return unsubscribe function
        return () => {
            const index = this.listeners.indexOf(listener);
            if (index !== -1) {
                this.listeners.splice(index, 1);
            }
        };
    }

    private notifyListeners(): void {
        const status = this.getStatus();
        this.listeners.forEach((listener) => listener(status));
    }

    destroy(): void {
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
            this.updateInterval = null;
        }
        this.listeners = [];
    }
}

// Singleton instance
export const audioHealthMonitor = new AudioHealthMonitor();
