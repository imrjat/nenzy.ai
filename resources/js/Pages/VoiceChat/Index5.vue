<template>
    <div class="voice-chat-app">
        <!-- Error overlay -->
        <div v-if="errorMessage" class="error-overlay">
            <div class="error-content">
                <p>{{ errorMessage }}</p>
                <button @click="retrySetup" class="retry-button">Retry</button>
            </div>
        </div>

        <!-- Gesture overlay -->
        <div v-else-if="audioContextNotStarted" class="gesture-overlay" @click="startAudioSystem">
            <p>Click to start the chat</p>
            <p class="subtitle">Microphone access required</p>
        </div>

        <!-- Main app content -->
        <div v-else class="main-content">
            <div class="status-bar" role="status" aria-live="polite">
                <div class="status-indicator" :class="{ active: isListening }"></div>
                <span>{{ statusMessage }}</span>
            </div>

            <div class="chat-container" ref="chatContainer">
                <div v-for="(msg, index) in messages" :key="index" :class="['message', msg.role]">
                    <div class="message-content">
                        <p>{{ msg.content }}</p>
                        <span class="timestamp">{{ msg.timestamp }}</span>
                    </div>
                </div>
                <div v-if="isLoading" class="message assistant loading">
                    <div class="typing-indicator">
                        <span></span><span></span><span></span>
                    </div>
                </div>
            </div>

            <div class="controls">
                <button @click="toggleMute" :class="['control-button', { muted: isMuted }]"
                    :disabled="!isAudioSystemReady">
                    {{ isMuted ? 'Unmute' : 'Mute' }}
                </button>
                <button @click="toggleListening" :class="['control-button', { active: isListening }]"
                    :disabled="!isAudioSystemReady || isMuted">
                    {{ isListening ? 'Stop' : 'Start' }} Listening
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';

// State management
const audioContextNotStarted = ref(true);
const isMuted = ref(false);
const isLoading = ref(false);
const isListening = ref(false);
const isAudioSystemReady = ref(false);
const statusMessage = ref('Click to start the chat');
const errorMessage = ref('');
const messages = ref([]);
const chatContainer = ref(null);

// Audio system variables
let audioContext = null;
let analyser = null;
let audioStream = null;
let mediaRecorder = null;
let audioChunks = [];
let silenceTimeout = null;
let audioMonitoringFrame = null;

// Constants
const SILENCE_THRESHOLD = 0.05;
const SILENCE_DURATION = 1500;
const MIN_RECORDING_LENGTH = 500;
let recordingStartTime = null;

// Initialize the audio system
const startAudioSystem = async () => {
    try {
        if (!isBrowserSupported()) {
            throw new Error('Your browser does not support required features.');
        }

        await initializeAudioContext();
        await setupMicrophone();
        setupAudioAnalyser();
        setupVoiceDetection();
        startAudioMonitoring();

        audioContextNotStarted.value = false;
        isAudioSystemReady.value = true;
        statusMessage.value = 'Ready to chat!';
    } catch (error) {
        handleError(error);
    }
};

// Check browser compatibility
const isBrowserSupported = () => {
    return (
        window.AudioContext ||
        window.webkitAudioContext ||
        navigator.mediaDevices?.getUserMedia ||
        window.MediaRecorder
    );
};

const initializeAudioContext = async () => {
    try {
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        if (audioContext.state === 'suspended') {
            await audioContext.resume();
        }
    } catch (error) {
        throw new Error('Failed to initialize audio context.');
    }
};

const setupMicrophone = async () => {
    try {
        audioStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
            }
        });
    } catch (error) {
        throw new Error('Microphone access denied or unavailable.');
    }
};

const setupAudioAnalyser = () => {
    analyser = audioContext.createAnalyser();
    const source = audioContext.createMediaStreamSource(audioStream);
    source.connect(analyser);
    analyser.fftSize = 2048;
    analyser.smoothingTimeConstant = 0.8;
};

const setupVoiceDetection = () => {
    mediaRecorder = new MediaRecorder(audioStream, {
        mimeType: 'audio/webm;codecs=opus',
        audioBitsPerSecond: 128000
    });

    mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
            audioChunks.push(event.data);
        }
    };

    mediaRecorder.onstop = async () => {
        const recordingDuration = Date.now() - recordingStartTime;
        if (audioChunks.length && !isMuted.value && recordingDuration >= MIN_RECORDING_LENGTH) {
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            audioChunks = [];
            if (audioBlob.size > 0) {
                await processAudio(audioBlob);
            }
        }
        audioChunks = [];
    };
};

const startAudioMonitoring = () => {
    const dataArray = new Uint8Array(analyser.frequencyBinCount);

    const checkAudioLevel = () => {
        if (!isListening.value) return;

        analyser.getByteFrequencyData(dataArray);
        const average = dataArray.reduce((a, b) => a + b) / dataArray.length;
        const audioLevel = average / 255;

        if (audioLevel > SILENCE_THRESHOLD) {
            handleSpeechDetected();
        }

        audioMonitoringFrame = requestAnimationFrame(checkAudioLevel);
    };

    checkAudioLevel();
};

const handleSpeechDetected = () => {
    if (!mediaRecorder || isMuted.value || !isListening.value) return;

    if (mediaRecorder.state !== 'recording') {
        recordingStartTime = Date.now();
        mediaRecorder.start();
        statusMessage.value = 'Listening...';
    }

    if (silenceTimeout) clearTimeout(silenceTimeout);

    silenceTimeout = setTimeout(() => {
        if (mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            statusMessage.value = 'Processing...';
        }
    }, SILENCE_DURATION);
};

const processAudio = async (audioBlob) => {
    try {
        isLoading.value = true;

        const transcription = await transcribeAudio(audioBlob);
        if (!transcription) return;

        messages.value.push({
            role: 'user',
            content: transcription,
            timestamp: new Date().toLocaleTimeString()
        });

        const aiResponse = await getAIResponse();
        if (!aiResponse) return;

        messages.value.push({
            role: 'assistant',
            content: aiResponse,
            timestamp: new Date().toLocaleTimeString()
        });

        if (!isMuted.value) await speakText(aiResponse);
    } catch (error) {
        handleError(error);
    } finally {
        isLoading.value = false;
        statusMessage.value = 'Ready to chat!';
    }
};

const transcribeAudio = async (audioBlob) => {
    const formData = new FormData();
    formData.append('file', audioBlob, 'audio.wav'); // Ensure valid filename
    formData.append('model', 'whisper-1');

    try {
        const response = await fetch('https://api.openai.com/v1/audio/transcriptions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${import.meta.env.VITE_OPENAI_API_KEY}`
            },
            body: formData
        });

        if (!response.ok) throw new Error('Transcription failed');
        const { text } = await response.json();
        return text;
    } catch (error) {
        throw new Error('Failed to transcribe audio');
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
                model: 'gpt-4o-mini',
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
        return choices[0].message.content;
    } catch (error) {
        throw new Error('Failed to get AI response');
    }
};

const speakText = async (text) => {
    return new Promise((resolve, reject) => {
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.rate = 1.0;
        utterance.pitch = 1.0;
        utterance.onend = resolve;
        utterance.onerror = reject;
        window.speechSynthesis.speak(utterance);
    });
};

const toggleMute = () => {
    isMuted.value = !isMuted.value;
    statusMessage.value = isMuted.value ? 'Muted' : 'Ready to chat!';
};

const toggleListening = () => {
    isListening.value = !isListening.value;
    if (isListening.value) {
        startAudioMonitoring();
        statusMessage.value = 'Listening...';
    } else {
        if (audioMonitoringFrame) {
            cancelAnimationFrame(audioMonitoringFrame);
        }
        if (mediaRecorder?.state === 'recording') {
            mediaRecorder.stop();
        }
        statusMessage.value = 'Paused';
    }
};

const handleError = (error) => {
    console.error('Error:', error);
    errorMessage.value = error.message || 'An error occurred';
    statusMessage.value = 'Error occurred';
};

const retrySetup = () => {
    errorMessage.value = '';
    audioContextNotStarted.value = true;
    startAudioSystem();
};

// Cleanup
onBeforeUnmount(() => {
    if (audioMonitoringFrame) {
        cancelAnimationFrame(audioMonitoringFrame);
    }
    if (silenceTimeout) {
        clearTimeout(silenceTimeout);
    }
    if (audioStream) {
        audioStream.getTracks().forEach(track => track.stop());
    }
    if (audioContext) {
        audioContext.close();
    }
});

// Auto-scroll chat
watch(messages, () => {
    if (chatContainer.value) {
        setTimeout(() => {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }, 100);
    }
});
</script>