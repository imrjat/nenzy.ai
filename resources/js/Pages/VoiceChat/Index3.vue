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
let consecutiveVoiceDetections = 0;
let silenceCounter = 0;
let lastTranscription = "";
const isChatFinished = ref(false);

// Constants
const SILENCE_THRESHOLD = 0.3;
const SILENCE_DURATION = 3000;
const MIN_RECORDING_LENGTH = 2000;
const MIN_VOICE_DETECTIONS = 3;
const MAX_RECORDING_LENGTH = 30000;
const VOICE_DETECTION_INTERVAL = 100;
let recordingStartTime = null;

// Initialize the audio system
const startAudioSystem = async () => {
    try {
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

const initializeAudioContext = async () => {
    audioContext = new (window.AudioContext || window.webkitAudioContext)();
    if (audioContext.state === 'suspended') {
        await audioContext.resume();
    }
};

const setupMicrophone = async () => {
    try {
        audioStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
                googNoiseSuppression: true,
                googHighpassFilter: true,
                sampleRate: 16000 // Reduce sample rate

            }
        });
    } catch (error) {
        throw new Error('Microphone access denied');
    }
};

const setupAudioAnalyser = () => {
    analyser = audioContext.createAnalyser();
    const source = audioContext.createMediaStreamSource(audioStream);
    source.connect(analyser);
    analyser.fftSize = 2048;
    analyser.smoothingTimeConstant = 0.9;
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

        if (audioChunks.length &&
            !isMuted.value &&
            recordingDuration >= MIN_RECORDING_LENGTH &&
            recordingDuration <= MAX_RECORDING_LENGTH) {

            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });

            if (audioBlob.size > 1024) {
                await processAudio(audioBlob);
            } else {
                statusMessage.value = 'Recording too short';
            }
        } else {
            console.log('Recording discarded:', {
                duration: recordingDuration,
                chunks: audioChunks.length,
                isMuted: isMuted.value
            });
        }

        audioChunks = [];
        consecutiveVoiceDetections = 0;
    };
};

const startAudioMonitoring = () => {
    const dataArray = new Uint8Array(analyser.frequencyBinCount);

    const checkAudioLevel = () => {
        if (!isListening.value) return;

        analyser.getByteFrequencyData(dataArray);
        const rms = Math.sqrt(
            dataArray.reduce((sum, val) => sum + (val * val), 0) / dataArray.length
        ) / 255;

        // Ignore very low noises
        if (rms > SILENCE_THRESHOLD && rms < 0.8) {
            handleSpeechDetected();
            silenceCounter = 0;
            statusMessage.value = 'Listening...';
        } else {
            silenceCounter++;
            if (silenceCounter > 3) {
                consecutiveVoiceDetections = 0;
                if (mediaRecorder?.state === 'recording') {
                    statusMessage.value = 'Waiting for more input...';
                }
            }
        }

        audioMonitoringFrame = requestAnimationFrame(checkAudioLevel);
    };

    checkAudioLevel();
};


const handleSpeechDetected = () => {
    if (!mediaRecorder || isMuted.value || !isListening.value) return;

    consecutiveVoiceDetections++;

    if (mediaRecorder.state !== 'recording' && consecutiveVoiceDetections >= MIN_VOICE_DETECTIONS) {
        recordingStartTime = Date.now();
        mediaRecorder.start();
        statusMessage.value = 'Listening...';
    }

    // Clear any existing silence timeout
    if (silenceTimeout) clearTimeout(silenceTimeout);

    // Set new silence timeout for 3 seconds
    silenceTimeout = setTimeout(() => {
        if (mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            statusMessage.value = 'Processing...';
        }
    }, SILENCE_DURATION);
};

const processAudio = async (audioBlob) => {
    try {
        if (isChatFinished.value) return;

        isLoading.value = true;

        const transcription = await transcribeAudio(audioBlob);
        if (!transcription || transcription.trim() === '' || transcription === lastTranscription) {

            statusMessage.value = 'No new input detected';
            return;
        }

        lastTranscription = transcription;

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
        statusMessage.value = isChatFinished.value ? 'Chat ended' : 'Ready to chat!';
    }
};

// Add a function to end the chat
const finishChat = () => {
    isChatFinished.value = true;
    cancelAnimationFrame(audioMonitoringFrame);
    if (mediaRecorder?.state === 'recording') {
        mediaRecorder.stop();
    }
    if (audioStream) {
        audioStream.getTracks().forEach(track => track.stop());
    }
    statusMessage.value = 'Chat ended';
};


const transcribeAudio = async (audioBlob) => {
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
    return text;
};

const getAIResponse = async () => {
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
};

const speakText = (text) => {
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
        cancelAnimationFrame(audioMonitoringFrame);
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

// Cleanup
onBeforeUnmount(() => {
    cancelAnimationFrame(audioMonitoringFrame);
    if (silenceTimeout) clearTimeout(silenceTimeout);
    if (audioStream) audioStream.getTracks().forEach(track => track.stop());
    if (audioContext) audioContext.close();
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

<template>
    <div class="chat-container">
        <div class="status-bar">
            <div class="status">{{ statusMessage }}</div>
            <div v-if="errorMessage" class="error">{{ errorMessage }}</div>
        </div>

        <div ref="chatContainer" class="messages">
            <div v-for="(message, index) in messages" :key="index" :class="['message', message.role]">
                <div class="content">
                    <pre>
                    {{ message.content }}
                </pre>
                </div>
                <div class="timestamp">{{ message.timestamp }}</div>
            </div>
        </div>

        <div class="controls">
            <button v-if="audioContextNotStarted" @click="startAudioSystem" :disabled="isLoading || isChatFinished">
                Start Chat
            </button>
            <template v-else>
                <button @click="toggleListening" :disabled="!isAudioSystemReady || isLoading || isChatFinished">
                    {{ isListening ? 'Pause' : 'Resume' }}
                </button>
                <button @click="toggleMute" :disabled="!isAudioSystemReady || isLoading || isChatFinished">
                    {{ isMuted ? 'Unmute' : 'Mute' }}
                </button>
                <button @click="finishChat" :disabled="isChatFinished">
                    Finish Chat
                </button>
            </template>
        </div>
    </div>
</template>


<style scoped>
.chat-container {
    display: flex;
    flex-direction: column;
    height: 100vh;
    max-width: 800px;
    margin: 0 auto;
    padding: 1rem;
}

.status-bar {
    padding: 1rem;
    background-color: #f5f5f5;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.status {
    font-weight: bold;
}

.error {
    color: red;
    margin-top: 0.5rem;
}

.messages {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    background-color: white;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    margin-bottom: 1rem;
}

.message {
    margin-bottom: 1rem;
    padding: 0.8rem;
    border-radius: 8px;
}

.message.user {
    background-color: #e3f2fd;
    margin-left: 2rem;
}

.message.assistant {
    background-color: #f5f5f5;
    margin-right: 2rem;
}

.timestamp {
    font-size: 0.8rem;
    color: #666;
    margin-top: 0.4rem;
}

.controls {
    display: flex;
    gap: 1rem;
    justify-content: center;
    padding: 1rem;
}

button {
    padding: 0.8rem 1.5rem;
    border: none;
    border-radius: 8px;
    background-color: #2196f3;
    color: white;
    cursor: pointer;
    font-weight: bold;
    transition: background-color 0.2s;
}

button:hover:not(:disabled) {
    background-color: #1976d2;
}

button:disabled {
    background-color: #ccc;
    cursor: not-allowed;
}
</style>
