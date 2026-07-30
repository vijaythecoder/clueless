#!/usr/bin/env swift

import Foundation
import ScreenCaptureKit
import CoreAudio
import AVFoundation
import AppKit
import Darwin

// MARK: - Packet Writer
final class PacketWriter {
    static let shared = PacketWriter()

    private let queue = DispatchQueue(label: "com.clueless.audio-capture.output", qos: .userInitiated)

    private init() {}

    func send(_ packet: [String: Any]) {
        queue.async {
            guard let data = try? JSONSerialization.data(withJSONObject: packet) else {
                return
            }

            FileHandle.standardOutput.write(data)
            FileHandle.standardOutput.write(Data([0x0A]))
        }
    }

    func flush() {
        queue.sync {}
    }
}

// MARK: - Process Lock Manager
final class ProcessLockManager {
    private static let lockFilePath = "/tmp/macos-audio-capture.lock"
    private static var lockDescriptor: Int32 = -1

    static func acquireLock() -> Bool {
        let descriptor = open(lockFilePath, O_CREAT | O_RDWR, S_IRUSR | S_IWUSR)
        guard descriptor >= 0 else {
            return false
        }

        guard flock(descriptor, LOCK_EX | LOCK_NB) == 0 else {
            close(descriptor)
            return false
        }

        lockDescriptor = descriptor
        let pid = "\(ProcessInfo.processInfo.processIdentifier)\n"
        ftruncate(descriptor, 0)
        _ = pid.withCString { pointer in
            Darwin.write(descriptor, pointer, strlen(pointer))
        }

        return true
    }

    static func releaseLock() {
        guard lockDescriptor >= 0 else {
            return
        }

        flock(lockDescriptor, LOCK_UN)
        close(lockDescriptor)
        lockDescriptor = -1
    }
}

// MARK: - Audio Capture Manager
@available(macOS 13.0, *)
final class AudioCaptureManager: NSObject {
    private var stream: SCStream?
    private var audioOutput: AudioOutput?
    private let sampleHandlerQueue = DispatchQueue(
        label: "com.clueless.audio-capture.samples",
        qos: .userInitiated
    )
    private let heartbeatQueue = DispatchQueue(
        label: "com.clueless.audio-capture.heartbeat",
        qos: .utility
    )
    private let sampleRate: Double = 24000
    private let channelCount: Int = 1
    private var isCapturing = false
    private var retryCount = 0
    private let maxRetries = 3
    private let retryDelay: TimeInterval = 2.0
    private var heartbeatTimer: DispatchSourceTimer?
    
    // MARK: - Initialize
    override init() {
        super.init()
        self.audioOutput = AudioOutput(sampleRate: sampleRate)
    }
    
    deinit {
        stopHeartbeat()
        ProcessLockManager.releaseLock()
    }
    
    // MARK: - Start Capture
    func startCapture() async throws {
        isCapturing = true
        retryCount = 0
        
        // Try to start capture with retries
        while retryCount < maxRetries {
            do {
                try await attemptStartCapture()
                return // Success!
            } catch {
                retryCount += 1
                
                if retryCount >= maxRetries {
                    isCapturing = false
                    sendError("Failed to start audio capture after \(maxRetries) attempts: \(error.localizedDescription)")
                    throw error
                }
                
                // Send retry status
                sendStatus("retrying")
                sendError("Audio capture failed, retrying in \(Int(retryDelay)) seconds... (attempt \(retryCount)/\(maxRetries))")
                
                // Wait before retry
                try await Task.sleep(nanoseconds: UInt64(retryDelay * 1_000_000_000))
                
                // Force cleanup before retry
                await forceCleanup()
            }
        }
    }
    
    private func attemptStartCapture() async throws {
        // First check if we have screen recording permission
        // Request permission by trying to get shareable content
        // This will trigger the permission dialog if not already granted
        do {
            _ = try await SCShareableContent.current
        } catch {
            // If we get an error here, it's likely due to missing permissions
            sendError("Screen recording permission required. Please grant permission in System Preferences > Privacy & Security > Screen Recording")
            
            // Open System Preferences to the Screen Recording section
            if let url = URL(string: "x-apple.systempreferences:com.apple.preference.security?Privacy_ScreenCapture") {
                NSWorkspace.shared.open(url)
            }
            
            throw CaptureError.permissionDenied
        }
        
        // Get shareable content
        let content = try await SCShareableContent.current
        
        // Create stream configuration for audio only
        let config = SCStreamConfiguration()
        config.capturesAudio = true
        config.sampleRate = Int(sampleRate)
        config.channelCount = channelCount
        
        // Create content filter to capture all system audio
        // We'll use a display filter to capture everything
        guard let display = content.displays.first else {
            throw CaptureError.captureError("No display found")
        }
        
        let filter = SCContentFilter(display: display, excludingApplications: [], exceptingWindows: [])
        
        // Create stream
        stream = SCStream(filter: filter, configuration: config, delegate: nil)
        
        // Add audio output
        if let audioOutput = audioOutput {
            try stream?.addStreamOutput(
                audioOutput,
                type: .audio,
                sampleHandlerQueue: sampleHandlerQueue
            )
        }
        
        // Start capture
        do {
            try await stream?.startCapture()
            sendStatus("capturing")
            startHeartbeat()
        } catch {
            // Check if error is due to resource conflict
            let errorDescription = error.localizedDescription.lowercased()
            if errorDescription.contains("resource") || errorDescription.contains("busy") || errorDescription.contains("in use") {
                throw CaptureError.resourceBusy
            }
            throw error
        }
    }
    
    // MARK: - Heartbeat Management
    private func startHeartbeat() {
        stopHeartbeat()

        let timer = DispatchSource.makeTimerSource(queue: heartbeatQueue)
        timer.schedule(deadline: .now() + 5, repeating: 5)
        timer.setEventHandler { [weak self] in
            self?.sendHeartbeat()
        }
        timer.resume()
        heartbeatTimer = timer
    }
    
    private func stopHeartbeat() {
        heartbeatTimer?.cancel()
        heartbeatTimer = nil
    }
    
    private func sendHeartbeat() {
        let now = Date()
        PacketWriter.shared.send([
            "type": "heartbeat",
            "timestamp": ISO8601DateFormatter().string(from: now),
            "capturing": isCapturing
        ])
    }
    
    // MARK: - Stop Capture
    func stopCapture() async throws {
        isCapturing = false
        stopHeartbeat()
        try await stream?.stopCapture()
        stream = nil
        sendStatus("stopped")
    }
    
    // MARK: - Force Cleanup
    private func forceCleanup() async {
        // Force cleanup of existing stream
        if let existingStream = stream {
            do {
                try await existingStream.stopCapture()
            } catch {
                // Ignore errors during cleanup
            }
        }
        stream = nil
        
        // Small delay to ensure resources are released
        try? await Task.sleep(nanoseconds: 500_000_000) // 0.5 seconds
    }
    
    // MARK: - Restart Capture
    func restartCapture() async throws {
        sendStatus("restarting")
        
        // Stop existing capture
        if isCapturing {
            try await stopCapture()
        }
        
        // Wait a bit to ensure resources are released
        try await Task.sleep(nanoseconds: 1_000_000_000) // 1 second
        
        // Start capture again
        try await startCapture()
    }
    
    // MARK: - Send Status
    private func sendStatus(_ state: String) {
        PacketWriter.shared.send(["type": "status", "state": state])
    }
    
    // MARK: - Send Error
    private func sendError(_ message: String, code: Int = 0) {
        PacketWriter.shared.send([
            "type": "error",
            "message": message,
            "code": code
        ])
    }
}

// MARK: - Audio Output Handler
@available(macOS 13.0, *)
final class AudioOutput: NSObject, SCStreamOutput {
    private let outputFormat: AVAudioFormat
    
    init(sampleRate: Double) {
        // Create output format (PCM16, mono, 24kHz)
        self.outputFormat = AVAudioFormat(
            commonFormat: .pcmFormatInt16,
            sampleRate: sampleRate,
            channels: 1,
            interleaved: false
        )!
        
        super.init()
    }
    
    func stream(_ stream: SCStream, didOutputSampleBuffer sampleBuffer: CMSampleBuffer, of type: SCStreamOutputType) {
        guard type == .audio else { return }
        
        // Process audio buffer
        processAudioBuffer(sampleBuffer)
    }
    
    private func processAudioBuffer(_ sampleBuffer: CMSampleBuffer) {
        guard let blockBuffer = CMSampleBufferGetDataBuffer(sampleBuffer) else { return }
        
        var length = 0
        var dataPointer: UnsafeMutablePointer<Int8>?
        CMBlockBufferGetDataPointer(blockBuffer, atOffset: 0, lengthAtOffsetOut: nil, totalLengthOut: &length, dataPointerOut: &dataPointer)
        
        guard let data = dataPointer else { return }
        
        // Convert to PCM16 format
        let samples = length / MemoryLayout<Float32>.size
        let floatPointer = UnsafeRawPointer(data).bindMemory(to: Float32.self, capacity: samples)
        
        var pcm16Data = Data(capacity: samples * 2)
        var sumOfSquares = 0.0
        
        for i in 0..<samples {
            let sample = floatPointer[i]
            let clamped = max(-1.0, min(1.0, sample))
            let normalizedSample = Double(clamped)
            sumOfSquares += normalizedSample * normalizedSample
            let int16Value = Int16(clamped * 32767)
            withUnsafeBytes(of: int16Value) { bytes in
                pcm16Data.append(contentsOf: bytes)
            }
        }
        
        // Send audio data as base64
        let base64Audio = pcm16Data.base64EncodedString()
        let rmsLevel = samples > 0 ? sqrt(sumOfSquares / Double(samples)) : 0.0
        PacketWriter.shared.send([
            "type": "audio",
            "data": base64Audio,
            "level": rmsLevel
        ])
    }
}

// MARK: - Error Types
enum CaptureError: Error, LocalizedError {
    case permissionDenied
    case captureError(String)
    case processLocked
    case resourceBusy
    
    var errorDescription: String? {
        switch self {
        case .permissionDenied:
            return "Screen recording permission denied. Please grant permission in System Preferences."
        case .captureError(let message):
            return "Capture error: \(message)"
        case .processLocked:
            return "Another audio capture process is already running"
        case .resourceBusy:
            return "Audio capture resource is busy. Another app may be using it."
        }
    }
    
    var errorCode: Int {
        switch self {
        case .permissionDenied: return 1001
        case .captureError: return 1002
        case .processLocked: return 1003
        case .resourceBusy: return 1004
        }
    }
}

// MARK: - Command Handler
@available(macOS 13.0, *)
final class CommandHandler {
    private let captureManager = AudioCaptureManager()
    private let inputQueue = DispatchQueue(
        label: "com.clueless.audio-capture.commands",
        qos: .userInitiated
    )
    private var commandTask: Task<Void, Never>?
    private var signalSources: [DispatchSourceSignal] = []
    
    func start() {
        configureSignalHandling()

        inputQueue.async { [weak self] in
            while let line = readLine() {
                self?.enqueueCommand(line)
            }

            self?.enqueueShutdown()
        }
        
        // Keep the run loop alive
        RunLoop.main.run()
    }
    
    private func enqueueCommand(_ line: String) {
        guard let data = line.data(using: .utf8),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let command = json["command"] as? String else {
            sendError("Invalid command format")
            return
        }

        let previousTask = commandTask
        commandTask = Task { [weak self] in
            _ = await previousTask?.result
            guard let self else {
                return
            }

            do {
                switch command {
                case "check_permission":
                    await checkPermission()
                case "start":
                    try await captureManager.startCapture()
                case "stop":
                    try await captureManager.stopCapture()
                case "restart":
                    try await captureManager.restartCapture()
                case "shutdown":
                    await shutdown()
                default:
                    sendError("Unknown command: \(command)")
                }
            } catch let captureError as CaptureError {
                sendError(captureError.localizedDescription, code: captureError.errorCode)
            } catch {
                sendError(error.localizedDescription)
            }
        }
    }

    private func configureSignalHandling() {
        for signalNumber in [SIGINT, SIGTERM] {
            signal(signalNumber, SIG_IGN)
            let source = DispatchSource.makeSignalSource(
                signal: signalNumber,
                queue: inputQueue
            )
            source.setEventHandler { [weak self] in
                self?.enqueueShutdown()
            }
            source.resume()
            signalSources.append(source)
        }
    }

    private func enqueueShutdown() {
        let previousTask = commandTask
        commandTask = Task { [weak self] in
            _ = await previousTask?.result
            await self?.shutdown()
        }
    }

    private func shutdown() async {
        try? await captureManager.stopCapture()
        ProcessLockManager.releaseLock()
        PacketWriter.shared.flush()
        exit(0)
    }
    
    private func checkPermission() async {
        do {
            // Try to get shareable content to check permission
            _ = try await SCShareableContent.current
            // If successful, we have permission
            PacketWriter.shared.send(["type": "permission", "granted": true])
        } catch {
            // No permission
            PacketWriter.shared.send(["type": "permission", "granted": false])
        }
    }
    
    private func sendError(_ message: String, code: Int = 0) {
        PacketWriter.shared.send([
            "type": "error",
            "message": message,
            "code": code
        ])
    }
}

// MARK: - Main
if #available(macOS 13.0, *) {
    // Check for process lock first
    if !ProcessLockManager.acquireLock() {
        PacketWriter.shared.send([
            "type": "error",
            "message": "Another audio capture process is already running",
            "code": 1003
        ])
        PacketWriter.shared.flush()
        exit(1)
    }
    
    let handler = CommandHandler()
    handler.start()
} else {
    PacketWriter.shared.send([
        "type": "error",
        "message": "macOS 13.0 or later required",
        "code": 1000
    ])
    PacketWriter.shared.flush()
    exit(1)
}
