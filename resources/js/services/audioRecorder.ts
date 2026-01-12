// Audio recording service using Electron's fs module
interface AudioRecorderOptions {
    sampleRate: number;
    channels: number;
    bitDepth: number;
}

// Constants for better maintainability
const CONSTANTS = {
    MAX_WRITE_SIZE: 10 * 1024 * 1024, // 10MB max per write
    BUFFER_FLUSH_THRESHOLD: 4096, // Flush when buffer reaches 4KB
    AUTO_SAVE_INTERVAL: 30000, // 30 seconds default
    SAMPLE_RATE: 24000, // Default 24kHz
    CHANNELS: 2, // Stereo
    BIT_DEPTH: 16, // 16-bit
};

export class AudioRecorderService {
    private options: AudioRecorderOptions;
    private recordingPath: string = '';
    private fileDescriptor: number | null = null;
    private audioBuffers: {
        left: Int16Array[];
        right: Int16Array[];
    } = { left: [], right: [] };
    private bytesWritten: number = 0;
    private autoSaveTimer: NodeJS.Timeout | null = null;
    private autoSaveInterval: number = CONSTANTS.AUTO_SAVE_INTERVAL;
    private startTime: number = 0;
    
    constructor(options: Partial<AudioRecorderOptions> = {}) {
        this.options = {
            sampleRate: options.sampleRate || CONSTANTS.SAMPLE_RATE,
            channels: options.channels || CONSTANTS.CHANNELS,
            bitDepth: options.bitDepth || CONSTANTS.BIT_DEPTH,
        };
        
        // Load auto-save interval from settings
        const recordingSettings = localStorage.getItem('recordingSettings');
        if (recordingSettings) {
            const settings = JSON.parse(recordingSettings);
            this.autoSaveInterval = (settings.autoSaveInterval || 30) * 1000;
        }
    }
    
    /**
     * Start recording
     * @returns The path where the recording will be saved
     */
    async start(): Promise<string> {
        if (this.fileDescriptor !== null) {
            throw new Error('Recording already in progress');
        }
        
        this.startTime = Date.now();
        this.bytesWritten = 0;
        this.audioBuffers = { left: [], right: [] };
        
        // Generate filename with timestamp
        const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, -5);
        const baseFilename = `conversation_${timestamp}.wav`;
        
        // Get recordings directory
        const { app } = window.remote;
        const fs = window.remote.require('fs');
        const path = window.remote.require('path');
        
        // Sanitize filename to prevent path traversal
        const sanitizedFilename = path.basename(baseFilename).replace(/[^a-zA-Z0-9._-]/g, '_');
        if (!sanitizedFilename.endsWith('.wav')) {
            throw new Error('Invalid filename format');
        }
        
        const recordingsDir = path.join(app.getPath('userData'), 'recordings');
        
        // Create directory if it doesn't exist
        if (!fs.existsSync(recordingsDir)) {
            fs.mkdirSync(recordingsDir, { recursive: true });
        }
        
        // Check available disk space
        try {
            const diskSpace = await this.checkDiskSpace(recordingsDir);
            const minSpaceRequired = 100 * 1024 * 1024; // 100MB minimum
            
            if (diskSpace.free < minSpaceRequired) {
                throw new Error(`Not enough disk space. Available: ${Math.floor(diskSpace.free / 1024 / 1024)}MB, Required: 100MB`);
            }
        } catch (error) {
            // If we can't check disk space, log warning but continue
            console.warn('Could not check disk space:', error);
        }
        
        this.recordingPath = path.join(recordingsDir, sanitizedFilename);
        
        // Verify the final path is within the recordings directory
        const resolvedPath = path.resolve(this.recordingPath);
        const resolvedDir = path.resolve(recordingsDir);
        if (!resolvedPath.startsWith(resolvedDir)) {
            throw new Error('Invalid recording path');
        }
        
        // Create WAV file
        this.createWavFile();
        
        // Start auto-save timer
        this.startAutoSave();
        
        return this.recordingPath;
    }
    
    /**
     * Stop recording
     * @returns Recording metadata
     */
    async stop(): Promise<{ path: string; duration: number; size: number }> {
        if (this.fileDescriptor === null) {
            throw new Error('No recording in progress');
        }
        
        // Stop auto-save timer
        this.stopAutoSave();
        
        // Flush remaining buffers
        this.flushBuffers();
        
        // Finalize WAV file
        this.finalizeWavFile();
        
        const fs = window.remote.require('fs');
        const stats = fs.statSync(this.recordingPath);
        const duration = this.getDuration();
        
        return {
            path: this.recordingPath,
            duration,
            size: stats.size,
        };
    }
    
    /**
     * Append audio data to the recording
     * @param audioData PCM16 audio data
     * @param channel 'left' or 'right' channel
     */
    appendAudio(audioData: Int16Array, channel: 'left' | 'right'): void {
        if (this.fileDescriptor === null) {
            return;
        }
        
        // Add to buffer
        this.audioBuffers[channel].push(new Int16Array(audioData));
    }
    
    /**
     * Get current recording status
     */
    getStatus(): { isRecording: boolean; duration: number; path: string } {
        return {
            isRecording: this.fileDescriptor !== null,
            duration: this.getDuration(),
            path: this.recordingPath,
        };
    }
    
    /**
     * Get recording duration in seconds
     */
    private getDuration(): number {
        if (this.startTime === 0) {
            return 0;
        }
        const duration = Math.floor((Date.now() - this.startTime) / 1000);
        return duration;
    }
    
    /**
     * Create WAV file with header
     */
    private createWavFile(): void {
        const fs = window.remote.require('fs');
        
        // Create file
        this.fileDescriptor = fs.openSync(this.recordingPath, 'w');
        
        // Write WAV header (we'll update sizes later)
        const header = this.createWavHeader(0);
        fs.writeSync(this.fileDescriptor, header);
        this.bytesWritten = header.length;
    }
    
    /**
     * Create WAV header
     */
    private createWavHeader(dataSize: number): Uint8Array {
        const buffer = new Uint8Array(44);
        const view = new DataView(buffer.buffer);
        
        // RIFF chunk
        this.writeString(buffer, 'RIFF', 0);
        view.setUint32(4, 36 + dataSize, true); // File size - 8 (little endian)
        this.writeString(buffer, 'WAVE', 8);
        
        // fmt chunk
        this.writeString(buffer, 'fmt ', 12);
        view.setUint32(16, 16, true); // Subchunk size (little endian)
        view.setUint16(20, 1, true); // Audio format (PCM) (little endian)
        view.setUint16(22, this.options.channels, true); // Channels (little endian)
        view.setUint32(24, this.options.sampleRate, true); // Sample rate (little endian)
        view.setUint32(28, this.options.sampleRate * this.options.channels * 2, true); // Byte rate (little endian)
        view.setUint16(32, this.options.channels * 2, true); // Block align (little endian)
        view.setUint16(34, this.options.bitDepth, true); // Bits per sample (little endian)
        
        // data chunk
        this.writeString(buffer, 'data', 36);
        view.setUint32(40, dataSize, true); // Data size (little endian)
        
        return buffer;
    }
    
    /**
     * Write string to Uint8Array buffer
     */
    private writeString(buffer: Uint8Array, str: string, offset: number): void {
        for (let i = 0; i < str.length; i++) {
            buffer[offset + i] = str.charCodeAt(i);
        }
    }
    
    /**
     * Flush audio buffers to file
     */
    private flushBuffers(): void {
        if (this.fileDescriptor === null) {
            return;
        }
        
        const fs = window.remote.require('fs');
        
        // Determine the maximum number of samples
        const leftSamples = this.audioBuffers.left.reduce((sum, chunk) => sum + chunk.length, 0);
        const rightSamples = this.audioBuffers.right.reduce((sum, chunk) => sum + chunk.length, 0);
        const maxSamples = Math.max(leftSamples, rightSamples);
        
        if (maxSamples === 0) {
            return;
        }
        
        // Create interleaved stereo buffer
        const stereoBuffer = new Uint8Array(maxSamples * 4); // 2 channels * 2 bytes per sample
        const stereoView = new DataView(stereoBuffer.buffer);
        
        let bufferOffset = 0;
        let leftChunkIndex = 0;
        let rightChunkIndex = 0;
        let leftChunkOffset = 0;
        let rightChunkOffset = 0;
        
        // Interleave samples
        for (let i = 0; i < maxSamples; i++) {
            // Get left sample
            let leftSample = 0;
            if (leftChunkIndex < this.audioBuffers.left.length) {
                const leftChunk = this.audioBuffers.left[leftChunkIndex];
                if (leftChunkOffset < leftChunk.length) {
                    leftSample = leftChunk[leftChunkOffset];
                    leftChunkOffset++;
                    if (leftChunkOffset >= leftChunk.length) {
                        leftChunkIndex++;
                        leftChunkOffset = 0;
                    }
                }
            }
            
            // Get right sample
            let rightSample = 0;
            if (rightChunkIndex < this.audioBuffers.right.length) {
                const rightChunk = this.audioBuffers.right[rightChunkIndex];
                if (rightChunkOffset < rightChunk.length) {
                    rightSample = rightChunk[rightChunkOffset];
                    rightChunkOffset++;
                    if (rightChunkOffset >= rightChunk.length) {
                        rightChunkIndex++;
                        rightChunkOffset = 0;
                    }
                }
            }
            
            // Write interleaved samples (little endian)
            stereoView.setInt16(bufferOffset, leftSample, true);
            stereoView.setInt16(bufferOffset + 2, rightSample, true);
            bufferOffset += 4;
        }
        
        // Write to file with bounds checking
        if (bufferOffset > 0) {
            // Check for reasonable buffer size
            if (bufferOffset > CONSTANTS.MAX_WRITE_SIZE) {
                throw new Error(`Buffer size exceeds maximum write size: ${bufferOffset} bytes`);
            }
            
            const dataToWrite = stereoBuffer.slice(0, bufferOffset);
            fs.writeSync(this.fileDescriptor, dataToWrite, 0, bufferOffset, this.bytesWritten);
            this.bytesWritten += bufferOffset;
        }
        
        // Clear buffers
        this.audioBuffers.left = [];
        this.audioBuffers.right = [];
    }
    
    /**
     * Finalize WAV file by updating header sizes
     */
    private finalizeWavFile(): void {
        if (this.fileDescriptor === null) {
            return;
        }
        
        const fs = window.remote.require('fs');
        
        // Update header with actual sizes
        const dataSize = this.bytesWritten - 44;
        const fileSize = this.bytesWritten - 8;
        
        // Update file size
        const fileSizeBuffer = new Uint8Array(4);
        const fileSizeView = new DataView(fileSizeBuffer.buffer);
        fileSizeView.setUint32(0, fileSize, true); // little endian
        fs.writeSync(this.fileDescriptor, fileSizeBuffer, 0, 4, 4);
        
        // Update data size
        const dataSizeBuffer = new Uint8Array(4);
        const dataSizeView = new DataView(dataSizeBuffer.buffer);
        dataSizeView.setUint32(0, dataSize, true); // little endian
        fs.writeSync(this.fileDescriptor, dataSizeBuffer, 0, 4, 40);
        
        // Close file
        fs.closeSync(this.fileDescriptor);
        this.fileDescriptor = null;
    }
    
    /**
     * Start auto-save timer
     */
    private startAutoSave(): void {
        this.autoSaveTimer = setInterval(() => {
            this.flushBuffers();
        }, this.autoSaveInterval);
    }
    
    /**
     * Stop auto-save timer
     */
    private stopAutoSave(): void {
        if (this.autoSaveTimer) {
            clearInterval(this.autoSaveTimer);
            this.autoSaveTimer = null;
        }
    }
    
    /**
     * Check available disk space
     */
    private async checkDiskSpace(directory: string): Promise<{ free: number; size: number }> {
        try {
            // Try to use disk-space module if available
            const diskSpace = window.remote.require('diskusage');
            const info = await new Promise<{ free: number; total: number }>((resolve, reject) => {
                diskSpace.check(directory, (err: any, info: any) => {
                    if (err) reject(err);
                    else resolve(info);
                });
            });
            return { free: info.free, size: info.total };
        } catch (error) {
            // Fallback: Try to use fs.statfs (macOS/Linux) or native methods
            const fs = window.remote.require('fs').promises;
            const os = window.remote.require('os');
            
            if (os.platform() === 'darwin' || os.platform() === 'linux') {
                // On Unix-like systems, we can try statfs
                try {
                    const stats = await fs.statfs(directory);
                    return {
                        free: stats.bavail * stats.bsize,
                        size: stats.blocks * stats.bsize
                    };
                } catch (e) {
                    // statfs might not be available
                }
            }
            
            // If all else fails, throw error
            throw new Error('Cannot determine disk space');
        }
    }
}