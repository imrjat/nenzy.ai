<template>
    <div class="chat-container p-4 max-w-3xl mx-auto">
        <!-- Header with Status -->
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold">AI Voice Assistant</h1>
            <div class="status-indicator mt-2">
                <span :class="{ 'text-green-500': isListening, 'text-gray-500': !isListening }">
                    {{ statusMessage }}
                </span>
            </div>
        </div>

        <!-- Chat Messages -->
        <div class="messages-container bg-white rounded-lg shadow-md p-4 mb-4 h-96 overflow-y-auto"
            ref="messagesContainer">
            <div v-for="(message, index) in messages" :key="index" class="message mb-4"
                :class="{ 'text-right': message.role === 'user' }">
                <div class="inline-block p-3 rounded-lg max-w-[80%]"
                    :class="message.role === 'user' ? 'bg-blue-500 text-white' : 'bg-gray-200'">
                    {{ message.content }}
                </div>
            </div>
            <div v-if="isProcessing" class="typing-indicator">
                <span class="dot"></span>
                <span class="dot"></span>
                <span class="dot"></span>
            </div>
        </div>

        <!-- Controls -->
        <div class="controls flex justify-center gap-4">
            <button @click="toggleListening" class="p-4 rounded-full w-16 h-16 flex items-center justify-center"
                :class="isListening ? 'bg-red-500' : 'bg-blue-500'">
                <span class="text-white text-2xl">
                    {{ isListening ? '⬤' : '🎤' }}
                </span>
            </button>
            <button @click="toggleAutoSpeak" class="p-4 rounded-full w-16 h-16 flex items-center justify-center"
                :class="autoSpeak ? 'bg-green-500' : 'bg-gray-400'">
                <span class="text-white text-2xl">
                    {{ autoSpeak ? '🔊' : '🔇' }}
                </span>
            </button>
        </div>
    </div>
</template>

<script>
import OpenAI from 'openai'

export default {
    name: 'VoiceChat',

    data() {
        return {
            messages: [],
            isListening: false,
            isProcessing: false,
            autoSpeak: true,
            mediaRecorder: null,
            audioChunks: [],
            statusMessage: 'Click microphone to start',
            openai: null,
            speechSynthesis: window.speechSynthesis,
            wakePhrases: ['hey assistant', 'hey ai', 'hello assistant'],
            lastProcessedTime: 0,
            processingThreshold: 2000, // 2 seconds
        }
    },

    created() {
        // Initialize OpenAI client
        this.openai = new OpenAI({
            apiKey: process.env.VUE_APP_OPENAI_API_KEY,
            dangerouslyAllowBrowser: true // Note: In production, use backend proxy
        })

        // Initialize speech synthesis voices
        window.speechSynthesis.onvoiceschanged = () => {
            this.voices = window.speechSynthesis.getVoices()
        }
    },

    methods: {
        async toggleListening() {
            if (this.isListening) {
                this.stopListening()
            } else {
                await this.startListening()
            }
        },

        async startListening() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
                this.mediaRecorder = new MediaRecorder(stream)
                this.audioChunks = []

                this.mediaRecorder.ondataavailable = (event) => {
                    this.audioChunks.push(event.data)
                }

                this.mediaRecorder.onstop = async () => {
                    const now = Date.now()
                    if (now - this.lastProcessedTime < this.processingThreshold) {
                        return
                    }
                    this.lastProcessedTime = now

                    await this.processAudioChunks()
                }

                // Start recording in 5-second chunks
                this.mediaRecorder.start(5000)
                this.isListening = true
                this.statusMessage = 'Listening...'
            } catch (error) {
                console.error('Error accessing microphone:', error)
                this.statusMessage = 'Error: Microphone access denied'
            }
        },

        stopListening() {
            if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                this.mediaRecorder.stop()
                this.mediaRecorder.stream.getTracks().forEach(track => track.stop())
            }
            this.isListening = false
            this.statusMessage = 'Click microphone to start'
        },

        async processAudioChunks() {
            if (this.audioChunks.length === 0) return

            try {
                // Create audio blob and file
                const audioBlob = new Blob(this.audioChunks, { type: 'audio/webm' })
                const audioFile = new File([audioBlob], 'recording.webm', { type: 'audio/webm' })

                // Transcribe with Whisper
                const transcription = await this.openai.audio.transcriptions.create({
                    file: audioFile,
                    model: 'whisper-1'
                })

                const transcript = transcription.text.toLowerCase().trim()

                // Check for wake phrases
                if (this.wakePhrases.some(phrase => transcript.includes(phrase))) {
                    // Remove wake phrase from transcript
                    const cleanTranscript = transcript.replace(/hey assistant|hey ai|hello assistant/g, '').trim()
                    if (cleanTranscript) {
                        await this.processUserInput(cleanTranscript)
                    }
                }

                this.audioChunks = []
            } catch (error) {
                console.error('Error processing audio:', error)
                this.statusMessage = 'Error processing audio'
            }
        },

        async processUserInput(text) {
            if (!text.trim()) return

            // Add user message
            this.messages.push({
                role: 'user',
                content: text
            })

            this.isProcessing = true
            this.scrollToBottom()

            try {
                // Get AI response
                const completion = await this.openai.chat.completions.create({
                    model: 'gpt-3.5-turbo',
                    messages: [
                        { role: 'system', content: 'You are a helpful voice assistant. Keep responses concise and natural for speech.' },
                        ...this.messages
                    ]
                })

                const response = completion.choices[0].message.content

                // Add AI response
                this.messages.push({
                    role: 'assistant',
                    content: response
                })

                // Speak response if enabled
                if (this.autoSpeak) {
                    this.speakText(response)
                }
            } catch (error) {
                console.error('Error getting AI response:', error)
                this.messages.push({
                    role: 'assistant',
                    content: 'Sorry, I encountered an error processing your request.'
                })
            }

            this.isProcessing = false
            this.scrollToBottom()
        },

        toggleAutoSpeak() {
            this.autoSpeak = !this.autoSpeak
            if (!this.autoSpeak) {
                this.speechSynthesis.cancel()
            }
        },

        speakText(text) {
            // Cancel any ongoing speech
            this.speechSynthesis.cancel()

            const utterance = new SpeechSynthesisUtterance(text)

            // Use a preferred voice if available
            const voices = this.speechSynthesis.getVoices()
            const preferredVoice = voices.find(voice =>
                voice.name.includes('Google') || voice.name.includes('Natural')
            )
            if (preferredVoice) {
                utterance.voice = preferredVoice
            }

            utterance.rate = 1.0
            utterance.pitch = 1.0
            this.speechSynthesis.speak(utterance)
        },

        scrollToBottom() {
            this.$nextTick(() => {
                const container = this.$refs.messagesContainer
                container.scrollTop = container.scrollHeight
            })
        }
    },

    beforeDestroy() {
        this.stopListening()
        this.speechSynthesis.cancel()
    }
}
</script>

<style scoped>
.typing-indicator {
    display: flex;
    gap: 4px;
    padding: 12px;
}

.dot {
    width: 8px;
    height: 8px;
    background: #90cdf4;
    border-radius: 50%;
    animation: bounce 1.4s infinite ease-in-out;
}

.dot:nth-child(1) {
    animation-delay: -0.32s;
}

.dot:nth-child(2) {
    animation-delay: -0.16s;
}

@keyframes bounce {

    0%,
    80%,
    100% {
        transform: scale(0);
    }

    40% {
        transform: scale(1.0);
    }
}

.messages-container {
    scrollbar-width: thin;
    scrollbar-color: rgba(155, 155, 155, 0.5) transparent;
}

.messages-container::-webkit-scrollbar {
    width: 6px;
}

.messages-container::-webkit-scrollbar-track {
    background: transparent;
}

.messages-container::-webkit-scrollbar-thumb {
    background-color: rgba(155, 155, 155, 0.5);
    border-radius: 20px;
    border: transparent;
}
</style>