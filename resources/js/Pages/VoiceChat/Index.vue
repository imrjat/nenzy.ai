// components/AudioRecorder.vue
<template>
  <div class="voice-chat-container">
    <!-- Status indicators -->
    <div class="status-indicators">
      <div class="status-badge" :class="{ active: isListening }">
        {{ isListening ? 'Recording' : 'Inactive' }}
      </div>
      <div class="audio-level" v-if="isListening">
        Audio Level: {{ audioLevel }}db
      </div>
    </div>

    <!-- Controls -->
    <div class="controls">
      <button 
        @click="toggleRecording" 
        :class="{ recording: isListening }"
      >
        {{ isListening ? 'Stop' : 'Start' }} Recording
      </button>
      <button 
        @click="toggleMute"
        :class="{ muted: isMuted }"
      >
        {{ isMuted ? 'Unmute' : 'Mute' }}
      </button>
      <button 
        @click="endCall"
        class="end-call"
      >
        End Call
      </button>
    </div>

    <!-- Chat History -->
    <div class="chat-history">
      <div 
        v-for="(message, index) in messages" 
        :key="index"
        :class="['message', message.role]"
      >
        <strong>{{ message.role }}:</strong> {{ message.content }}
        <span class="timestamp">{{ formatTimestamp(message.timestamp) }}</span>
      </div>
    </div>

    <!-- Error Display -->
    <div v-if="error" class="error-message">
      {{ error }}
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import RecordRTC from 'recordrtc';

// State management
const messages = ref([]);
const isListening = ref(false);
const isMuted = ref(false);
const error = ref('');
const audioLevel = ref(0);
const audioContext = ref(null);
const analyser = ref(null);
let recorder = null;
let audioStream = null;

// Constants
const AUDIO_CHUNK_SIZE = 4096;
const MAX_RECORDING_TIME = 30000; // 30 seconds
const MIN_AUDIO_LEVEL = -60;
const API_ENDPOINT = import.meta.env.VITE_OPENAI_API_ENDPOINT;
const API_KEY = import.meta.env.VITE_OPENAI_API_KEY;

// Initialize audio context
const initializeAudio = async () => {
  try {
    audioContext.value = new (window.AudioContext || window.webkitAudioContext)();
    analyser.value = audioContext.value.createAnalyser();
    analyser.value.fftSize = 2048;
  } catch (err) {
    error.value = 'Failed to initialize audio context: ' + err.message;
  }
};

// Audio level monitoring
const startAudioLevelMonitoring = (stream) => {
  const source = audioContext.value.createMediaStreamSource(stream);
  source.connect(analyser.value);
  
  const dataArray = new Uint8Array(analyser.value.frequencyBinCount);
  
  const updateLevel = () => {
    if (!isListening.value) return;
    
    analyser.value.getByteFrequencyData(dataArray);
    const average = dataArray.reduce((a, b) => a + b) / dataArray.length;
    audioLevel.value = Math.max(MIN_AUDIO_LEVEL, Math.round(20 * Math.log10(average / 255)));
    
    requestAnimationFrame(updateLevel);
  };
  
  updateLevel();
};

// Recording controls
const toggleRecording = async () => {
  try {
    if (isListening.value) {
      await stopRecording();
    } else {
      await startRecording();
    }
  } catch (err) {
    error.value = 'Recording error: ' + err.message;
  }
};

const startRecording = async () => {
  try {
    audioStream = await navigator.mediaDevices.getUserMedia({ 
      audio: {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
      }
    });
    
    recorder = new RecordRTC(audioStream, {
      type: 'audio',
      mimeType: 'audio/webm',
      numberOfAudioChannels: 1,
      desiredSampRate: 16000,
      bufferSize: AUDIO_CHUNK_SIZE
    });
    
    recorder.startRecording();
    isListening.value = true;
    startAudioLevelMonitoring(audioStream);
    
    // Auto-stop after max duration
    setTimeout(() => {
      if (isListening.value) stopRecording();
    }, MAX_RECORDING_TIME);
    
  } catch (err) {
    error.value = 'Failed to start recording: ' + err.message;
  }
};

const stopRecording = () => {
  return new Promise((resolve, reject) => {
    if (!recorder) {
      reject(new Error('No active recorder'));
      return;
    }

    recorder.stopRecording(async () => {
      try {
        const blob = recorder.getBlob();
        await sendToAI(blob);
        cleanup();
        resolve();
      } catch (err) {
        reject(err);
      }
    });
  });
};

// AI Communication
const sendToAI = async (audioBlob) => {
  const formData = new FormData();
  formData.append('file', audioBlob, 'audio.webm');
  formData.append('model', 'whisper-1');

  try {
    // First convert audio to text
    const transcriptionResponse = await fetch(`${API_ENDPOINT}/audio/transcriptions`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${API_KEY}`
      },
      body: formData
    });

    if (!transcriptionResponse.ok) {
      throw new Error(`Transcription failed: ${transcriptionResponse.statusText}`);
    }

    const { text } = await transcriptionResponse.json();
    
    // Add user message to chat
    messages.value.push({
      role: 'user',
      content: text,
      timestamp: Date.now()
    });

    // Then get AI response
    const aiResponse = await fetch(`${API_ENDPOINT}/chat/completions`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${API_KEY}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        model: 'gpt-4',
        messages: messages.value.map(({ role, content }) => ({ role, content }))
      })
    });

    if (!aiResponse.ok) {
      throw new Error(`AI response failed: ${aiResponse.statusText}`);
    }

    const { choices } = await aiResponse.json();
    
    // Add AI response to chat
    messages.value.push({
      role: 'assistant',
      content: choices[0].message.content,
      timestamp: Date.now()
    });

    // Text-to-speech for AI response
    await speakAIResponse(choices[0].message.content);

  } catch (err) {
    error.value = 'AI communication error: ' + err.message;
  }
};

// Text-to-speech
const speakAIResponse = async (text) => {
  if (isMuted.value) return;
  
  try {
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.rate = 1.0;
    utterance.pitch = 1.0;
    utterance.volume = 1.0;
    window.speechSynthesis.speak(utterance);
  } catch (err) {
    error.value = 'Text-to-speech error: ' + err.message;
  }
};

// Utility functions
const cleanup = () => {
  if (recorder) {
    recorder.destroy();
    recorder = null;
  }
  
  if (audioStream) {
    audioStream.getTracks().forEach(track => track.stop());
    audioStream = null;
  }
  
  isListening.value = false;
};

const formatTimestamp = (timestamp) => {
  return new Date(timestamp).toLocaleTimeString();
};

const toggleMute = () => {
  isMuted.value = !isMuted.value;
};

const endCall = async () => {
  if (isListening.value) {
    await stopRecording();
  }
  cleanup();
};

// Lifecycle hooks
onMounted(async () => {
  await initializeAudio();
});

onBeforeUnmount(() => {
  cleanup();
});
</script>

<style scoped>
.voice-chat-container {
  max-width: 800px;
  margin: 0 auto;
  padding: 20px;
}

.status-indicators {
  display: flex;
  align-items: center;
  gap: 20px;
  margin-bottom: 20px;
}

.status-badge {
  padding: 5px 10px;
  border-radius: 15px;
  background-color: #eee;
}

.status-badge.active {
  background-color: #ff4444;
  color: white;
}

.controls {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
}

button {
  padding: 10px 20px;
  border-radius: 5px;
  border: none;
  cursor: pointer;
  transition: all 0.3s;
}

button.recording {
  background-color: #ff4444;
  color: white;
}

button.muted {
  background-color: #666;
  color: white;
}

.end-call {
  background-color: #ff4444;
  color: white;
}

.chat-history {
  max-height: 400px;
  overflow-y: auto;
  padding: 10px;
  border: 1px solid #eee;
  border-radius: 5px;
}

.message {
  margin: 10px 0;
  padding: 10px;
  border-radius: 5px;
}

.message.user {
  background-color: #e3f2fd;
}

.message.assistant {
  background-color: #f5f5f5;
}

.timestamp {
  font-size: 0.8em;
  color: #666;
  margin-left: 10px;
}

.error-message {
  color: #ff4444;
  margin-top: 10px;
}
</style>