<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class AudioCapturePermissionService
{
    private const AUDIO_CAPTURE_BINARY = 'build/native/macos-audio-capture';
    private const EXTRAS_BINARY = 'extras/macos-audio-capture';

    /**
     * Check if screen recording permission is granted by attempting to start capture
     * This reuses the same mechanism as "Start Call" which already works
     */
    public function checkScreenRecordingPermission(): array
    {
        try {
            // Use the Swift binary to check permission using the same "start" command
            $binaryPath = $this->getAudioCaptureBinaryPath();
            
            if (!$binaryPath) {
                return [
                    'granted' => false,
                    'error' => 'Audio capture binary not found. Please rebuild the application.',
                    'needs_rebuild' => true
                ];
            }

            // Use the same "start" command that the working "Start Call" uses
            $process = Process::input('{"command": "start"}' . PHP_EOL)
                ->timeout(5)
                ->start([$binaryPath]);
            
            // Wait briefly for the process to start and check permission
            $startTime = time();
            $timeout = 5;
            
            while (time() - $startTime < $timeout && $process->running()) {
                $output = $process->latestOutput();
                $errorOutput = $process->latestErrorOutput();
                
                if (!empty($output)) {
                    $lines = explode("\n", $output);
                    
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;
                        
                        // Try to parse JSON response
                        if ($decoded = json_decode($line, true)) {
                            if (isset($decoded['type'])) {
                                if ($decoded['type'] === 'status' && $decoded['state'] === 'capturing') {
                                    // Permission granted! Stop the process immediately
                                    $process->signal(15); // SIGTERM
                                    return [
                                        'granted' => true,
                                        'error' => null,
                                        'needs_rebuild' => false
                                    ];
                                } elseif ($decoded['type'] === 'error') {
                                    $process->signal(15); // SIGTERM
                                    // Check if it's a permission error
                                    if (isset($decoded['code']) && $decoded['code'] === 1001) {
                                        return [
                                            'granted' => false,
                                            'error' => null,
                                            'needs_rebuild' => false
                                        ];
                                    }
                                    return [
                                        'granted' => false,
                                        'error' => $decoded['message'] ?? 'Unknown error',
                                        'needs_rebuild' => false
                                    ];
                                }
                            }
                        }
                    }
                }
                
                usleep(200000); // 200ms sleep
            }
            
            // Kill the process if it's still running
            if ($process->running()) {
                $process->signal(15); // SIGTERM
            }
            
            $result = $process->wait();
            
            // Check final output if we didn't find status during loop
            $allOutput = $result->output();
            $lines = explode("\n", $allOutput);
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                if ($decoded = json_decode($line, true)) {
                    if (isset($decoded['type'])) {
                        if ($decoded['type'] === 'status' && $decoded['state'] === 'capturing') {
                            return [
                                'granted' => true,
                                'error' => null,
                                'needs_rebuild' => false
                            ];
                        } elseif ($decoded['type'] === 'error' && isset($decoded['code']) && $decoded['code'] === 1001) {
                            return [
                                'granted' => false,
                                'error' => null,
                                'needs_rebuild' => false
                            ];
                        }
                    }
                }
            }
            
            // If we reach here, no clear permission status was found
            return [
                'granted' => false,
                'error' => 'Unable to determine permission status',
                'needs_rebuild' => false
            ];
            
        } catch (\Exception $e) {
            Log::error('Screen recording permission check failed: ' . $e->getMessage());
            
            return [
                'granted' => false,
                'error' => 'Permission check failed: ' . $e->getMessage(),
                'needs_rebuild' => false
            ];
        }
    }

    /**
     * Request screen recording permission by attempting to start capture
     * This triggers the same permission dialog as "Start Call" and opens System Preferences
     */
    public function requestScreenRecordingPermission(): array
    {
        try {
            // Use the Swift binary to trigger permission request using the same "start" command
            $binaryPath = $this->getAudioCaptureBinaryPath();
            
            if (!$binaryPath) {
                return [
                    'success' => false,
                    'error' => 'Audio capture binary not found. Please rebuild the application.'
                ];
            }

            // Use the same "start" command that triggers permission request
            $process = Process::input('{"command": "start"}' . PHP_EOL)
                ->timeout(15)
                ->start([$binaryPath]);
            
            // Wait for the process to trigger permission request
            $startTime = time();
            $timeout = 15;
            $permissionRequested = false;
            
            while (time() - $startTime < $timeout && $process->running()) {
                $output = $process->latestOutput();
                
                if (!empty($output)) {
                    $lines = explode("\n", $output);
                    
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;
                        
                        if ($decoded = json_decode($line, true)) {
                            if (isset($decoded['type'])) {
                                if ($decoded['type'] === 'status' && $decoded['state'] === 'capturing') {
                                    // Permission was already granted
                                    $process->signal(15); // SIGTERM
                                    return [
                                        'success' => true,
                                        'error' => null,
                                        'message' => 'Permission was already granted.'
                                    ];
                                } elseif ($decoded['type'] === 'error' && isset($decoded['code']) && $decoded['code'] === 1001) {
                                    // Permission denied, system preferences should open
                                    $permissionRequested = true;
                                    break;
                                }
                            }
                        }
                    }
                }
                
                if ($permissionRequested) {
                    break;
                }
                
                usleep(200000); // 200ms sleep
            }
            
            // Kill the process
            if ($process->running()) {
                $process->signal(15); // SIGTERM
            }
            
            $process->wait();
            
            return [
                'success' => true,
                'error' => null,
                'message' => 'Permission request initiated. Please grant screen recording permission in System Preferences.'
            ];
            
        } catch (\Exception $e) {
            Log::error('Screen recording permission request failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => 'Permission request failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get the path to the audio capture binary
     */
    private function getAudioCaptureBinaryPath(): ?string
    {
        $basePath = base_path();
        
        // Try the build directory first
        $buildPath = $basePath . '/' . self::AUDIO_CAPTURE_BINARY;
        if (file_exists($buildPath) && is_executable($buildPath)) {
            return $buildPath;
        }
        
        // Try the extras directory
        $extrasPath = $basePath . '/' . self::EXTRAS_BINARY;
        if (file_exists($extrasPath) && is_executable($extrasPath)) {
            return $extrasPath;
        }
        
        return null;
    }

    /**
     * Get permission status with user-friendly message
     */
    public function getPermissionStatus(): array
    {
        $result = $this->checkScreenRecordingPermission();
        
        if ($result['needs_rebuild']) {
            return [
                'status' => 'needs_rebuild',
                'message' => 'Audio capture needs to be rebuilt. Please run the build script.',
                'action' => 'rebuild'
            ];
        }
        
        if ($result['error']) {
            return [
                'status' => 'error',
                'message' => $result['error'],
                'action' => 'retry'
            ];
        }
        
        if ($result['granted']) {
            return [
                'status' => 'granted',
                'message' => 'Screen recording permission is granted. You can start capturing audio.',
                'action' => 'none'
            ];
        }
        
        return [
            'status' => 'denied',
            'message' => 'Screen recording permission is required for audio capture. Please grant permission in System Preferences.',
            'action' => 'request'
        ];
    }
}