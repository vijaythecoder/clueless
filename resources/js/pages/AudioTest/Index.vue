<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
        <!-- Navigation Bar -->
        <div class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Audio Test</h1>
                    <nav class="flex space-x-4">
                        <a
                            href="/audio-test"
                            class="text-sm font-medium text-blue-600 dark:text-blue-400"
                        >
                            Audio Test
                        </a>
                        <a
                            href="/realtime-agent"
                            class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            Agent V1
                        </a>
                        <a
                            href="/realtime-agent-v2"
                            class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            Agent V2
                        </a>
                    </nav>
                </div>
            </div>
        </div>
        
        <div class="max-w-4xl mx-auto p-8">
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-8">Mic & System Audio Test</h2>
            
            <!-- Controls -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 mb-6">
                <div class="flex gap-4 mb-4">
                    <button
                        @click="startStreaming"
                        :disabled="isStreaming"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Start Streaming
                    </button>
                    <button
                        @click="stopStreaming"
                        :disabled="!isStreaming"
                        class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Stop Streaming
                    </button>
                    
                    <select v-model="selectedModel" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md dark:bg-gray-700 dark:text-white">
                        <option value="whisper-1">whisper-1</option>
                        <option value="gpt-4o-transcribe">gpt-4o-transcribe</option>
                        <option value="gpt-4o-mini-transcribe">gpt-4o-mini-transcribe</option>
                    </select>
                </div>
                
                <!-- Status Indicators -->
                <div class="flex gap-4">
                    <div class="flex items-center">
                        <div :class="['w-3 h-3 rounded-full mr-2', micStatus ? 'bg-green-500' : 'bg-red-500']"></div>
                        <span class="text-sm text-gray-700 dark:text-gray-300">Microphone</span>
                    </div>
                    <div class="flex items-center">
                        <div :class="['w-3 h-3 rounded-full mr-2', systemStatus ? 'bg-green-500' : 'bg-red-500']"></div>
                        <span class="text-sm text-gray-700 dark:text-gray-300">System Audio</span>
                    </div>
                </div>
            </div>
            
            <!-- Transcription Results -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Microphone Transcription -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Microphone Transcription</h2>
                    <div class="h-64 overflow-y-auto bg-gray-50 dark:bg-gray-900 rounded p-4">
                        <pre class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ micTranscript || 'Waiting for microphone input...' }}</pre>
                    </div>
                </div>
                
                <!-- System Audio Transcription -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">System Audio Transcription</h2>
                    <div class="h-64 overflow-y-auto bg-gray-50 dark:bg-gray-900 rounded p-4">
                        <pre class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ systemTranscript || 'Waiting for system audio...' }}</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onUnmounted } from 'vue'

// State
const isStreaming = ref(false)
const micStatus = ref(false)
const systemStatus = ref(false)
const selectedModel = ref('gpt-4o-mini-transcribe')
const micTranscript = ref('')
const systemTranscript = ref('')

// WebSocket connections
let micSession: any = null
let systemSession: any = null
let micStream: MediaStream | null = null
let systemStream: MediaStream | null = null

// Get API key from env
const apiKey = import.meta.env.VITE_OPENAI_API_KEY || ''

// Check if we have an API key
if (!apiKey) {
    console.error('No OpenAI API key found. Please set VITE_OPENAI_API_KEY in your .env file')
}

// Session class (simplified from mic-speaker-streamer)
class Session {
    private ws: WebSocket | null = null
    private pc: RTCPeerConnection | null = null
    private dc: RTCDataChannel | null = null
    
    constructor(
        private apiKey: string,
        private streamType: string,
        private onMessage: (data: any) => void,
        private onStatusChange: (connected: boolean) => void
    ) {}
    
    async startTranscription(stream: MediaStream, model: string) {
        this.pc = new RTCPeerConnection()
        this.pc.addTrack(stream.getTracks()[0])
        
        this.pc.onconnectionstatechange = () => {
            this.onStatusChange(this.pc?.connectionState === 'connected')
        }
        
        this.dc = this.pc.createDataChannel('')
        this.dc.onmessage = (e) => this.onMessage(JSON.parse(e.data))
        
        const offer = await this.pc.createOffer()
        await this.pc.setLocalDescription(offer)
        
        // Get ephemeral token
        const tokenResponse = await fetch('https://api.openai.com/v1/realtime/transcription_sessions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.apiKey}`,
                'openai-beta': 'realtime-v1',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                input_audio_transcription: {
                    model: model,
                    prompt: ""
                },
                turn_detection: {
                    type: "server_vad",
                    silence_duration_ms: 10
                }
            })
        })
        
        if (!tokenResponse.ok) {
            throw new Error('Failed to get session token')
        }
        
        const sessionData = await tokenResponse.json()
        const clientSecret = sessionData.client_secret.value
        
        // Exchange SDP
        const sdpResponse = await fetch('https://api.openai.com/v1/realtime', {
            method: 'POST',
            body: offer.sdp,
            headers: {
                'Authorization': `Bearer ${clientSecret}`,
                'Content-Type': 'application/sdp'
            }
        })
        
        if (!sdpResponse.ok) {
            throw new Error('Failed to exchange SDP')
        }
        
        const answer = {
            type: 'answer' as RTCSdpType,
            sdp: await sdpResponse.text()
        }
        
        await this.pc.setRemoteDescription(answer)
    }
    
    stop() {
        this.dc?.close()
        this.pc?.close()
        this.ws?.close()
    }
}

// Start streaming
const startStreaming = async () => {
    try {
        // Check API key first
        if (!apiKey) {
            throw new Error('No OpenAI API key configured. Please add VITE_OPENAI_API_KEY to your .env file')
        }
        
        isStreaming.value = true
        
        // Get microphone stream
        micStream = await navigator.mediaDevices.getUserMedia({ audio: true })
        // Check if audio loopback is available
        
        // Enable audio loopback for system audio if available
        if ((window as any).audioLoopback) {
            try {
                await (window as any).audioLoopback.enableLoopback()
            } catch {
            }
        }
        
        // Get system audio stream (screen share with audio)
        const displayStream = await navigator.mediaDevices.getDisplayMedia({
            audio: true,
            video: true
        })
        
        // Disable audio loopback if it was enabled
        if ((window as any).audioLoopback) {
            try {
                await (window as any).audioLoopback.disableLoopback()
            } catch {
            }
        }
        
        // Remove video tracks, keep only audio
        const videoTracks = displayStream.getTracks().filter(t => t.kind === 'video')
        videoTracks.forEach(t => {
            t.stop()
            displayStream.removeTrack(t)
        })
        
        systemStream = displayStream
        
        // Create sessions
        micSession = new Session(
            apiKey,
            'microphone',
            (data) => handleMicMessage(data),
            (connected) => { micStatus.value = connected }
        )
        
        systemSession = new Session(
            apiKey,
            'system',
            (data) => handleSystemMessage(data),
            (connected) => { systemStatus.value = connected }
        )
        
        // Start transcription
        await Promise.all([
            micSession.startTranscription(micStream, selectedModel.value),
            systemSession.startTranscription(systemStream, selectedModel.value)
        ])
        
    } catch (error) {
        console.error('Error starting streaming:', error)
        alert('Error: ' + (error as Error).message)
        stopStreaming()
    }
}

// Stop streaming
const stopStreaming = () => {
    isStreaming.value = false
    micStatus.value = false
    systemStatus.value = false
    
    micSession?.stop()
    systemSession?.stop()
    
    micStream?.getTracks().forEach(t => t.stop())
    systemStream?.getTracks().forEach(t => t.stop())
    
    micSession = null
    systemSession = null
    micStream = null
    systemStream = null
}

// Handle messages
const handleMicMessage = (data: any) => {
    if (data.type === 'conversation.item.input_audio_transcription.completed') {
        const timestamp = new Date().toLocaleTimeString()
        micTranscript.value += `[${timestamp}] ${data.transcript}\n`
    }
}

const handleSystemMessage = (data: any) => {
    if (data.type === 'conversation.item.input_audio_transcription.completed') {
        const timestamp = new Date().toLocaleTimeString()
        systemTranscript.value += `[${timestamp}] ${data.transcript}\n`
    }
}

// Cleanup
onUnmounted(() => {
    stopStreaming()
})
</script>