<template>
    <div class="voice-chat-app">
        <!-- Overlay for user gesture -->
        <div v-if="audioContextNotStarted" class="gesture-overlay" @click="startAudioSystem">
            <p>Click to start the chat</p>
        </div>

        <!-- Main app content -->
        <div v-else>
            <div class="status-bar" role="status" aria-live="polite">
                <span>{{ statusMessage }}</span>
            </div>

            <div class="chat-container" ref="chatContainer">
                <div v-for="(msg, index) in messages" :key="index" :class="['message', msg.role]">
                    <p>{{ msg.content }}</p>
                </div>
                <div v-if="isLoading" class="message assistant">
                    <p>Processing...</p>
                </div>
            </div>

            <button @click="toggleMute" :class="{ muted: isMuted }">
                {{ isMuted ? 'Unmute' : 'Mute' }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue';

// State variables
const audioContextNotStarted = ref(true);  // Controls whether to show the overlay
const isMuted = ref(false);
const isLoading = ref(false);
const statusMessage = ref('Click to start the chat');
const messages = ref([]);
const chatContainer = ref(null);

// Audio system variables
let audioContext = null;
let analyser = null;
let audioStream = null;
let mediaRecorder = null;
let audioChunks = [];
let silenceTimeout = null;

// **Initialize the audio system on user gesture**
const startAudioSystem = async () => {
    try {
        // Create and resume the AudioContext on user gesture
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        if (audioContext.state === 'suspended') {
            await audioContext.resume();
        }

        // Request microphone access
        audioStream = await navigator.mediaDevices.getUserMedia({ audio: true });

        // Set up the audio analyser node
        analyser = audioContext.createAnalyser();
        const source = audioContext.createMediaStreamSource(audioStream);
        source.connect(analyser);
        analyser.fftSize = 2048;

        // Voice detection setup
        setupVoiceDetection();
        startAudioMonitoring();

        // Update state after successful initialization
        audioContextNotStarted.value = false;
        statusMessage.value = 'Ready to chat!';
    } catch (error) {
        statusMessage.value = 'Error: Microphone access is required.';
        console.error('Audio system initialization error:', error);
    }
};

// **Setup voice detection**
const setupVoiceDetection = () => {
    mediaRecorder = new MediaRecorder(audioStream, { mimeType: 'audio/webm;codecs=opus' });

    mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
            audioChunks.push(event.data);
        }
    };

    mediaRecorder.onstop = async () => {
        if (audioChunks.length && !isMuted.value) {
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            audioChunks = [];
            if (audioBlob.size > 0) {
                await processAudio(audioBlob);
            }
        }
    };
};

// **Monitor audio levels**
const startAudioMonitoring = () => {
    const dataArray = new Uint8Array(analyser.frequencyBinCount);

    const checkAudioLevel = () => {
        analyser.getByteFrequencyData(dataArray);
        const average = dataArray.reduce((a, b) => a + b) / dataArray.length;
        const audioLevel = average / 255;

        if (audioLevel > 0.05) handleSpeechDetected();

        requestAnimationFrame(checkAudioLevel);
    };

    checkAudioLevel();
};

// **Handle speech detection**
const handleSpeechDetected = () => {
    if (!mediaRecorder || isMuted.value) return;

    if (!mediaRecorder.state || mediaRecorder.state !== 'recording') {
        mediaRecorder.start();
        statusMessage.value = 'Listening...';
    }

    // Reset silence timeout
    if (silenceTimeout) clearTimeout(silenceTimeout);

    // Stop recording after a period of silence
    silenceTimeout = setTimeout(() => {
        if (mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            statusMessage.value = 'Processing...';
        }
    }, 1500);
};

// **Process audio and get AI response**
const processAudio = async (audioBlob) => {
    try {
        isLoading.value = true;

        // Transcribe audio using OpenAI Whisper
        const formData = new FormData();
        formData.append('file', audioBlob);
        formData.append('model', 'whisper-1');

        const transcriptResponse = await fetch('https://api.openai.com/v1/audio/transcriptions', {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${import.meta.env.VITE_OPENAI_API_KEY}` },
            body: formData
        });

        if (!transcriptResponse.ok) throw new Error('Transcription failed');
        const { text } = await transcriptResponse.json();

        // Add user message to chat
        messages.value.push({ role: 'user', content: text });

        // Get AI response from OpenAI
        const aiResponse = await fetch('https://api.openai.com/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${import.meta.env.VITE_OPENAI_API_KEY}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                model: 'gpt-4',
                messages: messages.value.map(msg => ({ role: msg.role, content: msg.content }))
            })
        });

        if (!aiResponse.ok) throw new Error('AI response failed');
        const { choices } = await aiResponse.json();

        // Add AI response to chat and speak it out
        const responseText = choices[0].message.content;
        messages.value.push({ role: 'assistant', content: responseText });

        if (!isMuted.value) await speakText(responseText);
    } catch (error) {
        console.error('Error processing audio:', error);
        statusMessage.value = 'Error occurred. Try again.';
    } finally {
        isLoading.value = false;
        statusMessage.value = 'Ready to chat!';
    }
};

// **Text-to-speech function**
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

// **Toggle mute**
const toggleMute = () => {
    isMuted.value = !isMuted.value;
    statusMessage.value = isMuted.value ? 'Muted' : 'Ready to chat!';
};

// Scroll to the latest message
watch(messages, () => {
    if (chatContainer.value) {
        setTimeout(() => {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }, 100);
    }
});
</script>

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
