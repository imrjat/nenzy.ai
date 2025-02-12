<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
const props = defineProps({
    messages: Object,
    receiver: Object
});

const page = usePage();

const newMessage = ref('');
const chatMessages = ref(props.messages);


onMounted(() => {


    window.Echo.private(`chat.${page.props.auth.user.id}`)
        .listen('MessageSent', (e) => {
            chatMessages.value.push(e.message);
        });

});

const sendMessage = async () => {
    if (newMessage.value.trim() === '') return;

    const response = await axios.post(route('chat.store'), {
        receiver_id: props.receiver.id,
        message: newMessage.value
    });

    chatMessages.value.push(response.data);
    newMessage.value = '';
};
</script>

<template>
    <div class="chat-container">
        <h2>Chat with {{ receiver.name }}</h2>
        <div class="messages">
            <div v-for="message in chatMessages" :key="message.id"
                :class="{ 'self': message.sender_id === $page.props.auth.user.id }">
                <p>{{ message.message }}</p>
            </div>
        </div>
        <input v-model="newMessage" @keyup.enter="sendMessage" placeholder="Type a message..." />
        <button @click="sendMessage">Send</button>
    </div>
</template>

<style scoped>
.chat-container {
    width: 400px;
    margin: auto;
    border: 1px solid #ddd;
    padding: 20px;
    border-radius: 8px;
}

.messages {
    height: 300px;
    overflow-y: auto;
    margin-bottom: 10px;
}

.self {
    text-align: right;
    color: blue;
}
</style>
