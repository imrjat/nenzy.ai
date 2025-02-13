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

const initializeAudioSystem = async () => {
    try {
        // Check browser support
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Your browser does not support audio input');
        }

        // Request microphone access
        audioStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: false,
                channelCount: 1,
            }
        });

        // Verify stream
        if (!audioStream || !audioStream.getAudioTracks().length) {
            throw new Error('No audio input device available');
        }

        // Initialize AudioContext if needed
        if (!audioContext) {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
            if (audioContext.state === 'suspended') {
                await audioContext.resume();
            }
        }

        // Load AudioWorklet processor
        await audioContext.audioWorklet.addModule('/js/noise-suppression-processor.js');

        // Create audio nodes
        const noiseCancellationNode = new AudioWorkletNode(audioContext, 'noise-suppression-processor');
        analyser = audioContext.createAnalyser(); // Initialize analyser early
        analyser.fftSize = 1024;
        analyser.smoothingTimeConstant = 0.9;

        // Create processing chain components
        source = audioContext.createMediaStreamSource(audioStream);
        const compressor = audioContext.createDynamicsCompressor();
        const gainNode = audioContext.createGain();
        const highPassFilter = audioContext.createBiquadFilter();
        const lowPassFilter = audioContext.createBiquadFilter();

        // Configure nodes
        compressor.threshold.value = -50;
        compressor.knee.value = 40;
        compressor.ratio.value = 12;
        compressor.attack.value = 0;
        compressor.release.value = 0.25;
        gainNode.gain.value = 0.2;
        highPassFilter.type = 'highpass';
        highPassFilter.frequency.value = 180;
        highPassFilter.Q.value = 1.0;
        lowPassFilter.type = 'lowpass';
        lowPassFilter.frequency.value = 3000;
        lowPassFilter.Q.value = 1.0;

        // Connect primary processing chain
        source.connect(noiseCancellationNode).connect(analyser);

        // Connect secondary processing chain
        source
            .connect(highPassFilter)
            .connect(lowPassFilter)
            .connect(compressor)
            .connect(gainNode)
            .connect(analyser);

        // Setup MediaRecorder and monitoring
        mediaRecorder = new MediaRecorder(audioStream, { mimeType: 'audio/webm' });
        setupRecorder();
        startVolumeMonitoring(audioStream.getAudioTracks()[0], gainNode);

        // Finalize setup
        state.value.audioContextNotStarted = false;
        statusMessage.value = 'Ready! Voice chat active with noise reduction';
        startVoiceActivityDetection();
        audioStream.oninactive = cleanup;

    } catch (error) {
        console.error("Microphone Initialization Failed:", error);
        errorMessage.value = `Initialization error: ${error.message}`;
        cleanup();
    }
};
const startVolumeMonitoring = (audioTrack, gainNode) => {
    setInterval(() => {
        const settings = audioTrack.getSettings();
        if (settings.volume > 0.2) {
            gainNode.gain.value = 0.15;
            console.warn("Volume reduced for noise control");
        }
    }, 1000);
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

        state.value.isProcessing = true;
        statusMessage.value = 'Processing...';

        const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
        await processAudio(audioBlob);
        audioChunks = [];
        state.value.isProcessing = false;
    };
};

const startVoiceActivityDetection = () => {
    if (!analyser || !audioContext) return;

    const bufferLength = analyser.frequencyBinCount;
    const dataArray = new Uint8Array(bufferLength);
    let isProcessing = false;

    // Variables to track sustained activity
    let sustainedSpeechStart = null;
    const requiredSpeechDuration = 300; // milliseconds required before starting recording
    const speechThreshold = 15; // Adjust as needed

    const detectVoice = () => {
        if (!analyser || isProcessing) return;

        try {
            isProcessing = true;
            analyser.getByteFrequencyData(dataArray);

            const weightedRMS = Math.sqrt(
                dataArray.reduce((sum, value, index) => {
                    const frequency = (index * (audioContext?.sampleRate ?? 44100)) / (analyser?.fftSize ?? 1024);
                    const weight = (frequency > 300 && frequency < 3000) ? 1.2 : 0.8;
                    return sum + (value * value * weight);
                }, 0) / bufferLength
            ) || 0;

            state.value.volumeLevel = Math.min(Math.max(weightedRMS / 255, 0), 1);

            // Check if the signal exceeds the threshold
            if (weightedRMS > speechThreshold) {
                if (sustainedSpeechStart === null) {
                    // Start the timer when threshold is first crossed
                    sustainedSpeechStart = Date.now();
                } else if (Date.now() - sustainedSpeechStart > requiredSpeechDuration) {
                    // If the condition has been sustained, start recording if not already
                    if (!state.value.isListening) {
                        startRecording();
                    }
                    // Clear any previous silence timeout and set a new one
                    clearTimeout(silenceTimeout);
                    silenceTimeout = setTimeout(stopRecording, 2000);
                }
            } else {
                // Reset the sustained timer if the level drops below threshold
                sustainedSpeechStart = null;
            }
        } catch (err) {
            console.error('Error in voice detection:', err);
        } finally {
            isProcessing = false;
        }

        animationFrame = requestAnimationFrame(detectVoice);
    };

    detectVoice();
};


const startRecording = () => {
    if (state.value.isMuted || state.value.isListening || state.value.isProcessing || state.value.isSpeaking) return;

    audioChunks = [];
    mediaRecorder.start();
    state.value.isListening = true;
    statusMessage.value = 'Recording...';
};

const stopRecording = () => {
    if (mediaRecorder?.state === 'recording') {
        mediaRecorder.stop();
    }
    state.value.isListening = false;
    statusMessage.value = 'Recording stopped';
};

const processAudio = async (audioBlob) => {
    try {
        const formData = new FormData();
        formData.append('file', audioBlob, 'audio.webm');
        formData.append('model', 'whisper-1');
        // Specialized interview prompt
        formData.append('prompt',
            "Analyze the audio file to detect and classify background noise, distinguishing between speech and non-speech elements. Transcribe the given audio file into text, accurately capturing spoken words while minimizing errors caused by background noise or unclear speech."
        );

        formData.append('language', 'en');

        const response = await fetch('https://api.openai.com/v1/audio/transcriptions', {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${import.meta.env.VITE_OPENAI_API_KEY}` },
            body: formData
        });

        const { text } = await response.json();
        if (text?.trim()) {
            const ignoreInputs = ['Peace', 'Thanks', 'thank you so much for watching', 'Thank you. ',

                // Common filler sounds
                'um', 'uh', 'er', 'hmm', 'ah', 'eh',

                // Background noise descriptions
                '[background noise]', '[music]', '[cough]', '[laugh]',
                '[clearing throat]', '[breathing]', '[silence]',

                // Incomplete phrases
                'so...', 'well...', 'like...', 'you know...',

                // Common recording artifacts
                'testing', 'mic test', 'hello test',
                'can you hear me', 'is this working',

                // End of recording phrases
                'bye', 'goodbye', 'thank you', 'thanks',
                'that\'s all', 'the end'
            ];
            if (isOnlyEnglishWords(text) && !ignoreInputs.includes(text)) {
                messages.value.push({
                    role: 'user',
                    content: text,
                    timestamp: new Date().toLocaleTimeString()
                });
                await getAIResponse();
            }
        }
    } catch (error) {
        errorMessage.value = `Processing failed: ${error.message}`;
    }
};

const isOnlyEnglishWords = (text) => {
    // First, let's clean and validate the text
    if (!text || typeof text !== 'string') return false;

    // Allow letters, numbers, spaces, and basic punctuation
    const validTextRegex = /^[a-zA-Z0-9\s.,!?'"()-]+$/;

    // Test if the entire text matches our valid characters
    if (!validTextRegex.test(text)) {
        return false;
    }

    // Split by spaces and check each word
    const words = text.split(' ').filter(word => word.length > 0);

    // If no words found, return false
    if (words.length === 0) return false;

    // Check each word - allow for punctuation attached to words
    return words.every(word => {
        // Remove punctuation from the word before checking
        const cleanWord = word.replace(/[.,!?'"()-]/g, '');
        // Check if the cleaned word contains only letters (and optionally numbers)
        return cleanWord.length > 0 && /^[a-zA-Z0-9]+$/.test(cleanWord);
    });
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
            throw new Error("Speech generation failed");
        }

        const audioBlob = await response.blob();
        const audioUrl = URL.createObjectURL(audioBlob);

        const audio = new Audio(audioUrl);
        audio.onended = () => {
            state.value.isSpeaking = false;
            URL.revokeObjectURL(audioUrl);
        };

        state.value.isSpeaking = true;
        await audio.play();
    } catch (error) {
        console.error("Error in speech generation:", error);
        state.value.isSpeaking = false;
    }
};

const toggleMute = () => {
    if (state.value.isListening) {
        // Optionally notify the user that the mute state cannot be changed during recording.
        console.warn("Mute toggle is disabled while recording is active.");
        return;
    }

    state.value.isMuted = !state.value.isMuted;
    statusMessage.value = state.value.isMuted ? 'Muted' : 'Ready';
};


const cleanup = () => {
    if (mediaRecorder?.state === 'recording') {
        mediaRecorder.stop();
    }
    if (audioStream) {
        audioStream.getTracks().forEach(track => track.stop());
    }
    if (audioContext) {
        audioContext.close();
    }
    if (analyser) {
        analyser.disconnect();
    }
    if (animationFrame) {
        cancelAnimationFrame(animationFrame);
    }
    if (silenceTimeout) {
        clearTimeout(silenceTimeout);
    }

    state.value.audioContextNotStarted = true;
    state.value.isListening = false;
    state.value.isProcessing = false;
    state.value.isSpeaking = false;
    statusMessage.value = 'Audio system stopped';
};

onBeforeUnmount(cleanup);

watch(messages, () => {
    if (chatContainer.value) {
        setTimeout(() => {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }, 100);
    }
});
</script>
