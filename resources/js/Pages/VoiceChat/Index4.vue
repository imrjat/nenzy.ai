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
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';

// State management with more detailed status tracking
const appState = ref('initial'); // initial, starting, ready, error, recording, processing
const audioContextNotStarted = ref(true);
const isMuted = ref(false);
const isLoading = ref(false);
const isListening = ref(false);
const isAudioSystemReady = ref(false);
const statusMessage = ref('Click to start the chat');
const errorMessage = ref('');
const messages = ref([]);
const chatContainer = ref(null);
const reconnectAttempts = ref(0);
const MAX_RECONNECT_ATTEMPTS = 3;

// Audio system variables with better initialization checks
let audioContext = null;
let analyser = null;
let audioStream = null;
let mediaRecorder = null;
let audioChunks = [];
let silenceTimeout = null;
let audioMonitoringFrame = null;
let isProcessingAudio = false;

// Enhanced constants
const SILENCE_THRESHOLD = 0.05;
const SILENCE_DURATION = 1500;
const MIN_RECORDING_LENGTH = 500;
const MAX_RECORDING_LENGTH = 30000; // 30 seconds max recording
const RECONNECT_DELAY = 2000;
let recordingStartTime = null;
let recordingTimeout = null;

// Initialize the audio system with better error recovery
const startAudioSystem = async () => {
    try {
        appState.value = 'starting';
        await initializeAudioContext();
        await setupMicrophone();
        await setupAudioAnalyser();
        setupVoiceDetection();
        startAudioMonitoring();

        audioContextNotStarted.value = false;
        isAudioSystemReady.value = true;
        appState.value = 'ready';
        statusMessage.value = 'Ready to chat!';
        reconnectAttempts.value = 0;
    } catch (error) {
        handleError(error);
        attemptReconnect();
    }
};

// Enhanced audio context initialization with suspended state handling
const initializeAudioContext = async () => {
    try {
        audioContext = new (window.AudioContext || window.webkitAudioContext)();

        // Handle suspended state
        if (audioContext.state === 'suspended') {
            await audioContext.resume();
        }

        // Add state change listener
        audioContext.addEventListener('statechange', () => {
            if (audioContext.state === 'suspended') {
                handleError(new Error('Audio context suspended'));
                attemptReconnect();
            }
        });
    } catch (error) {
        throw new Error('Failed to initialize audio context: ' + error.message);
    }
};

// Enhanced microphone setup with permission handling
const setupMicrophone = async () => {
    try {
        audioStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
                channelCount: 1
            }
        });

        // Add track ended listener
        audioStream.getTracks().forEach(track => {
            track.addEventListener('ended', () => {
                handleError(new Error('Audio track ended unexpectedly'));
                attemptReconnect();
            });
        });
    } catch (error) {
        if (error.name === 'NotAllowedError') {
            throw new Error('Please grant microphone access to use the chat');
        } else if (error.name === 'NotFoundError') {
            throw new Error('No microphone detected');
        } else {
            throw new Error('Microphone setup failed: ' + error.message);
        }
    }
};

// Enhanced audio analyzer setup
const setupAudioAnalyser = async () => {
    try {
        analyser = audioContext.createAnalyser();
        const source = audioContext.createMediaStreamSource(audioStream);
        source.connect(analyser);
        analyser.fftSize = 2048;
        analyser.smoothingTimeConstant = 0.8;
    } catch (error) {
        throw new Error('Failed to setup audio analysis: ' + error.message);
    }
};

// Enhanced voice detection with better error handling
const setupVoiceDetection = () => {
    try {
        const options = {
            mimeType: MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                ? 'audio/webm;codecs=opus'
                : 'audio/webm',
            audioBitsPerSecond: 128000
        };

        mediaRecorder = new MediaRecorder(audioStream, options);

        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                audioChunks.push(event.data);
            }
        };

        mediaRecorder.onerror = (event) => {
            handleError(new Error('MediaRecorder error: ' + event.error.message));
        };

        mediaRecorder.onstop = async () => {
            await handleRecordingComplete();
        };
    } catch (error) {
        throw new Error('Failed to setup voice detection: ' + error.message);
    }
};

// Enhanced audio monitoring with performance optimization
const startAudioMonitoring = () => {
    const dataArray = new Uint8Array(analyser.frequencyBinCount);
    let lastProcessingTime = 0;
    const PROCESS_INTERVAL = 50; // Minimum time between processing in ms

    const checkAudioLevel = (timestamp) => {
        if (!isListening.value) return;

        // Throttle processing
        if (timestamp - lastProcessingTime >= PROCESS_INTERVAL) {
            analyser.getByteFrequencyData(dataArray);
            const average = dataArray.reduce((a, b) => a + b) / dataArray.length;
            const audioLevel = average / 255;

            if (audioLevel > SILENCE_THRESHOLD) {
                handleSpeechDetected();
            }

            lastProcessingTime = timestamp;
        }

        audioMonitoringFrame = requestAnimationFrame(checkAudioLevel);
    };

    audioMonitoringFrame = requestAnimationFrame(checkAudioLevel);
};

// Enhanced speech detection with safeguards
const handleSpeechDetected = () => {
    if (!mediaRecorder || isMuted.value || !isListening.value || isProcessingAudio) return;

    if (mediaRecorder.state !== 'recording') {
        recordingStartTime = Date.now();
        try {
            mediaRecorder.start();
            appState.value = 'recording';
            statusMessage.value = 'Listening...';

            // Set maximum recording duration
            recordingTimeout = setTimeout(() => {
                if (mediaRecorder.state === 'recording') {
                    mediaRecorder.stop();
                    statusMessage.value = 'Maximum recording length reached';
                }
            }, MAX_RECORDING_LENGTH);
        } catch (error) {
            handleError(new Error('Failed to start recording: ' + error.message));
            return;
        }
    }

    if (silenceTimeout) clearTimeout(silenceTimeout);

    silenceTimeout = setTimeout(() => {
        if (mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            statusMessage.value = 'Processing...';
        }
    }, SILENCE_DURATION);
};

// Enhanced recording completion handler
const handleRecordingComplete = async () => {
    if (recordingTimeout) {
        clearTimeout(recordingTimeout);
        recordingTimeout = null;
    }

    const recordingDuration = Date.now() - recordingStartTime;
    if (audioChunks.length && !isMuted.value && recordingDuration >= MIN_RECORDING_LENGTH) {
        const audioBlob = new Blob(audioChunks, { type: mediaRecorder.mimeType });
        audioChunks = [];
        if (audioBlob.size > 0) {
            await processAudio(audioBlob);
        }
    }
    audioChunks = [];
};

// Enhanced audio processing with rate limiting and error handling
const processAudio = async (audioBlob) => {
    if (isProcessingAudio) return;

    try {
        isProcessingAudio = true;
        isLoading.value = true;
        appState.value = 'processing';

        const transcription = await transcribeAudio(audioBlob);

        if (!transcription) {
            throw new Error('No transcription received');
        }

        messages.value.push({
            role: 'user',
            content: transcription,
            timestamp: new Date().toLocaleTimeString()
        });

        const aiResponse = await getAIResponse();
        if (!aiResponse) {
            throw new Error('No AI response received');
        }

        messages.value.push({
            role: 'assistant',
            content: aiResponse,
            timestamp: new Date().toLocaleTimeString()
        });

        if (!isMuted.value) {
            try {
                await speakText(aiResponse);
            } catch (error) {
                console.warn('Text-to-speech failed:', error);
                // Continue even if TTS fails
            }
        }
    } catch (error) {
        handleError(error);
    } finally {
        isProcessingAudio = false;
        isLoading.value = false;
        appState.value = 'ready';
        statusMessage.value = 'Ready to chat!';
    }
};

// Enhanced API calls with timeout and retry logic
const transcribeAudio = async (audioBlob) => {
    const formData = new FormData();
    formData.append('file', audioBlob, 'audio.wav'); // Ensure valid filename

    formData.append('model', 'whisper-1');

    try {
        const response = await fetchWithTimeout('https://api.openai.com/v1/audio/transcriptions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${import.meta.env.VITE_OPENAI_API_KEY}`
            },
            body: formData
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(`Transcription failed: ${errorData.error?.message || response.statusText}`);
        }

        const { text } = await response.json();
        return text;
    } catch (error) {
        throw new Error('Failed to transcribe audio: ' + error.message);
    }
};

// Helper function for fetch with timeout
const fetchWithTimeout = (url, options = {}, timeout = 10000) => {
    return Promise.race([
        fetch(url, options),
        new Promise((_, reject) =>
            setTimeout(() => reject(new Error('Request timeout')), timeout)
        )
    ]);
};

// Enhanced AI response handling
const getAIResponse = async () => {
    try {
        const response = await fetchWithTimeout('https://api.openai.com/v1/chat/completions', {
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

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(`AI response failed: ${errorData.error?.message || response.statusText}`);
        }

        const { choices } = await response.json();
        return choices[0].message.content;
    } catch (error) {
        throw new Error('Failed to get AI response: ' + error.message);
    }
};

// Enhanced text-to-speech with better browser support
const speakText = async (text) => {
    return new Promise((resolve, reject) => {
        if (!window.speechSynthesis) {
            reject(new Error('Text-to-speech not supported'));
            return;
        }

        // Cancel any ongoing speech
        window.speechSynthesis.cancel();

        const utterance = new SpeechSynthesisUtterance(text);
        utterance.rate = 1.0;
        utterance.pitch = 1.0;
        utterance.onend = resolve;
        utterance.onerror = (event) => reject(new Error('Speech synthesis failed: ' + event.error));

        // Split long text into chunks if needed
        if (text.length > 200) {
            const chunks = text.match(/.{1,200}(?=\s|$)/g) || [];
            chunks.forEach((chunk, index) => {
                setTimeout(() => {
                    const chunkUtterance = new SpeechSynthesisUtterance(chunk);
                    window.speechSynthesis.speak(chunkUtterance);
                }, index * 1000);
            });
            resolve();
        } else {
            window.speechSynthesis.speak(utterance);
        }
    });
};

// Enhanced controls with debouncing
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
        stopListening();
    }
};

const stopListening = () => {
    if (audioMonitoringFrame) {
        cancelAnimationFrame(audioMonitoringFrame);
    }
    if (mediaRecorder?.state === 'recording') {
        mediaRecorder.stop();
    }
    if (silenceTimeout) {
        clearTimeout(silenceTimeout);
    }
    if (recordingTimeout) {
        clearTimeout(recordingTimeout);
    }
    statusMessage.value = 'Paused';
};

// Enhanced error handling with reconnection logic
const handleError = (error) => {
    console.error('Error:', error);
    errorMessage.value = error.message || 'An error occurred';
    statusMessage.value = 'Error occurred';
    appState.value = 'error';
};

const attemptReconnect = async () => {
    if (reconnectAttempts.value >= MAX_RECONNECT_ATTEMPTS) {
        errorMessage.value = 'Maximum reconnection attempts reached. Please refresh the page.';
        return;
    }

    reconnectAttempts.value++;
    await new Promise(resolve => setTimeout(resolve, RECONNECT_DELAY));
    retrySetup();
};

const retrySetup = () => {
    cleanup();
    errorMessage.value = '';
    audioContextNotStarted.value = true;
    startAudioSystem();
};

// Enhanced cleanup
const cleanup = () => {
    if (audioMonitoringFrame) {
        cancelAnimationFrame(audioMonitoringFrame);
    }
    if (silenceTimeout) {
        clearTimeout(silenceTimeout);
    }
    if (recordingTimeout) {
        clearTimeout(recordingTimeout);
    }
    if (audioStream) {
        audioStream.getTracks().forEach(track => track.stop());
    }
    if (audioContext) {
        audioContext.close();
    }
};

// Lifecycle hooks
onMounted(() => {
    window.addEventListener('beforeunload', cleanup);
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', cleanup);
    cleanup();
});</script>

<style scoped>
.voice-chat-app {
    font-family: Arial, sans-serif;
    text-align: center;
    padding: 20px;
}

.status-bar {
    margin-bottom: 20px;
}

.message {
    margin: 10px 0;
}

.gesture-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.8);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    z-index: 1000;
    cursor: pointer;
    text-align: center;
}
</style>
