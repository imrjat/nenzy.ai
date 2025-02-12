<template>
    <div class="voice-chat-container p-4">
        <div v-if="state.audioContextNotStarted" class="text-center p-4">
            <button @click="initializeAudioSystem"
                class="px-6 py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                Start Voice Chat
            </button>
        </div>

        <div v-else>
            <div class="controls-section mb-4 p-4 bg-gray-100 rounded-lg">
                <div class="status-container flex items-center justify-between mb-2">
                    <span class="status-message text-lg" :class="{
                        'text-green-600': state.isListening && !state.isProcessing,
                        'text-blue-600': state.isProcessing,
                        'text-red-600': errorMessage
                    }">
                        {{ statusMessage }}
                    </span>
                    <div v-if="state.isListening"
                        class="volume-meter h-4 w-32 bg-gray-200 rounded-full overflow-hidden">
                        <div class="h-full bg-green-500 transition-all duration-100"
                            :style="{ width: `${Math.min(state.volumeLevel * 100, 100)}%` }">
                        </div>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button @click="toggleMute" class="px-4 py-2 rounded-lg"
                        :class="state.isMuted ? 'bg-yellow-500 text-white' : 'bg-blue-500 text-white'">
                        {{ state.isMuted ? 'Unmute' : 'Mute' }}
                    </button>
                </div>

                <div v-if="errorMessage" class="error-message mt-2 text-red-600">
                    {{ errorMessage }}
                </div>
            </div>

            <div ref="chatContainer" class="chat-container h-96 overflow-y-auto p-4 bg-white rounded-lg shadow">
                <div v-for="(message, index) in messages" :key="index" class="message-container mb-4"
                    :class="message.role === 'user' ? 'text-right' : 'text-left'">
                    <div class="inline-block max-w-3/4 p-3 rounded-lg"
                        :class="message.role === 'user' ? 'bg-blue-100' : 'bg-gray-100'">
                        <div class="message-content">{{ message.content }}</div>
                        <div class="message-timestamp text-xs text-gray-500 mt-1">
                            {{ message.timestamp }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';

const state = ref({
    audioContextNotStarted: true,
    isMuted: false,
    isListening: false,
    isProcessing: false,
    isSpeaking: false,
    volumeLevel: 0,
    webrtcSupported: typeof window !== 'undefined' && 'RTCPeerConnection' in window
});

const messages = ref([]);
const statusMessage = ref('Click Start Voice Chat to begin');
const chatContainer = ref(null);
const errorMessage = ref('');

let audioContext = null;
let audioStream = null;
let mediaRecorder = null;
let audioChunks = [];
let analyser = null;
let source = null;
let animationFrame = null;
let silenceTimeout = null;
let peerConnection = null;

const initializeAudioSystem = async () => {
    try {
        if (!state.value.webrtcSupported) {
            throw new Error('WebRTC is not supported in this browser');
        }

        // Create RTCPeerConnection
        const configuration = {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' }
            ]
        };
        peerConnection = new RTCPeerConnection(configuration);

        // Request microphone access with WebRTC constraints
        audioStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: { ideal: true },
                noiseSuppression: { ideal: true },
                autoGainControl: { ideal: true },
                channelCount: { ideal: 1 },
                sampleRate: { ideal: 48000 },
                sampleSize: { ideal: 16 }
            }
        });

        // Add tracks to peer connection
        audioStream.getTracks().forEach(track => {
            peerConnection.addTrack(track, audioStream);
        });

        // Set up audio context
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        await audioContext.resume();

        // Set up audio processing nodes
        source = audioContext.createMediaStreamSource(audioStream);
        analyser = audioContext.createAnalyser();
        analyser.fftSize = 2048;
        analyser.smoothingTimeConstant = 0.8;
        source.connect(analyser);

        // Initialize MediaRecorder with WebRTC stream
        const options = {
            mimeType: getSupportedMimeType(),
            audioBitsPerSecond: 128000
        };
        mediaRecorder = new MediaRecorder(audioStream, options);

        setupRecorder();
        setupPeerConnectionListeners();

        state.value.audioContextNotStarted = false;
        statusMessage.value = 'Ready! Voice chat is active';
        startVoiceActivityDetection();
    } catch (error) {
        console.error('Error initializing:', error);
        errorMessage.value = `Initialization error: ${error.message}`;
    }
};

const getSupportedMimeType = () => {
    const types = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/ogg;codecs=opus',
        'audio/wav'
    ];
    return types.find(type => MediaRecorder.isTypeSupported(type)) || 'audio/webm';
};

const setupPeerConnectionListeners = () => {
    peerConnection.onicecandidate = event => {
        if (event.candidate) {
            console.log('New ICE candidate:', event.candidate);
        }
    };

    peerConnection.onconnectionstatechange = () => {
        console.log('Connection state:', peerConnection.connectionState);
    };

    peerConnection.oniceconnectionstatechange = () => {
        console.log('ICE connection state:', peerConnection.iceConnectionState);
    };
};

const setupRecorder = () => {
    if (!mediaRecorder) return;

    mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
            audioChunks.push(event.data);
        }
    };

    mediaRecorder.onstop = async () => {
        if (audioChunks.length === 0) return;

        try {
            state.value.isProcessing = true;
            statusMessage.value = 'Processing recording...';

            const audioBlob = new Blob(audioChunks, { type: mediaRecorder.mimeType });
            await processAudio(audioBlob);

            audioChunks = [];
        } catch (error) {
            console.error('Processing error:', error);
            errorMessage.value = `Processing error: ${error.message}`;
        } finally {
            state.value.isProcessing = false;
        }
    };
};

const startVoiceActivityDetection = () => {
    const dataArray = new Uint8Array(analyser.frequencyBinCount);
    const vadThreshold = 25; // Adjustable threshold for voice detection

    const detectVoice = () => {
        analyser.getByteFrequencyData(dataArray);

        // Improved voice activity detection using frequency analysis
        const voiceFreqRange = dataArray.slice(85, 255); // Focus on voice frequency range
        const average = voiceFreqRange.reduce((sum, value) => sum + value, 0) / voiceFreqRange.length;
        state.value.volumeLevel = average / 255;

        if (average > vadThreshold) {
            if (!state.value.isListening && !state.value.isProcessing && !state.value.isSpeaking) {
                startRecording();
            }
            clearTimeout(silenceTimeout);
            silenceTimeout = setTimeout(() => {
                if (state.value.isListening) {
                    stopRecording();
                }
            }, 1500); // 1.5 seconds of silence
        }

        animationFrame = requestAnimationFrame(detectVoice);
    };

    detectVoice();
};

const startRecording = () => {
    if (state.value.isListening || state.value.isProcessing || state.value.isSpeaking) return;

    audioChunks = [];
    mediaRecorder.start();
    state.value.isListening = true;
    statusMessage.value = 'Recording...';
};

const stopRecording = () => {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
        cancelAnimationFrame(animationFrame);
    }
    state.value.isListening = false;
    statusMessage.value = 'Recording stopped';
};

const processAudio = async (audioBlob) => {
    try {
        const formData = new FormData();
        formData.append('file', audioBlob, 'audio.webm');
        formData.append('model', 'whisper-1');

        const response = await fetch('https://api.openai.com/v1/audio/transcriptions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${import.meta.env.VITE_OPENAI_API_KEY}`
            },
            body: formData
        });

        if (!response.ok) throw new Error('Transcription failed');

        const { text } = await response.json();

        if (text && text.trim()) {
            messages.value.push({
                role: 'user',
                content: text,
                timestamp: new Date().toLocaleTimeString()
            });

            stopRecording();
            await getAIResponse();
        }
    } catch (error) {
        errorMessage.value = `Processing failed: ${error.message}`;
        stopRecording();
    }
};

const getAIResponse = async () => {
    try {
        const response = await fetch('https://api.openai.com/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${import.meta.env.VITE_OPENAI_API_KEY}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                model: 'gpt-4',
                messages: messages.value.map(msg => ({
                    role: msg.role,
                    content: msg.content
                })),
                temperature: 0.7,
                max_tokens: 150
            })
        });

        if (!response.ok) throw new Error('AI response failed');

        const { choices } = await response.json();
        const aiMessage = choices[0].message.content;

        messages.value.push({
            role: 'assistant',
            content: aiMessage,
            timestamp: new Date().toLocaleTimeString()
        });

        if (!state.value.isMuted) {
            await speakResponse(aiMessage);
        }

        state.value.isSpeaking = false;
        startVoiceActivityDetection();
    } catch (error) {
        errorMessage.value = `AI response failed: ${error.message}`;
    }
};

const speakResponse = async (text) => {
    try {
        state.value.isSpeaking = true;
        const response = await fetch("https://api.openai.com/v1/audio/speech", {
            method: "POST",
            headers: {
                'Authorization': `Bearer ${import.meta.env.VITE_OPENAI_API_KEY}`,
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                model: "tts-1",
                input: text,
                voice: "shimmer"
            })
        });

        if (!response.ok) {
            throw new Error("Speech synthesis failed");
        }

        const audioBlob = await response.blob();
        const audioUrl = URL.createObjectURL(audioBlob);
        const audio = new Audio(audioUrl);

        audio.onended = () => {
            state.value.isSpeaking = false;
            URL.revokeObjectURL(audioUrl);
        };

        await audio.play();
    } catch (error) {
        console.error("Speech synthesis error:", error);
        state.value.isSpeaking = false;
        errorMessage.value = `Speech synthesis failed: ${error.message}`;
    }
};

const toggleMute = () => {
    state.value.isMuted = !state.value.isMuted;
    statusMessage.value = state.value.isMuted ? 'Muted' : 'Ready';
};

onBeforeUnmount(() => {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
    }
    if (audioStream) {
        audioStream.getTracks().forEach(track => track.stop());
    }
    if (audioContext) {
        audioContext.close();
    }
    if (animationFrame) {
        cancelAnimationFrame(animationFrame);
    }
    if (peerConnection) {
        peerConnection.close();
    }
});

watch(messages, () => {
    if (chatContainer.value) {
        setTimeout(() => {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }, 100);
    }
});
</script>