<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatMessageController extends Controller
{
    public function show($Id)
    {
        $receiver = User::findOrFail($Id);
        $messages = ChatMessage::where(function ($query) use ($receiver) {
            $query->where('sender_id', Auth::id())->where('receiver_id', $receiver->id);
        })->orWhere(function ($query) use ($receiver) {
            $query->where('sender_id', $receiver->id)->where('receiver_id', Auth::id());
        })->orderBy('created_at')->get();

        return inertia('Chat/Index', [
            'messages' => $messages,
            'receiver' => $receiver
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'message' => 'required|string'
        ]);

        $message = ChatMessage::create([
            'sender_id' => Auth::id(),
            'receiver_id' => $request->receiver_id,
            'message' => $request->message
        ]);

        broadcast(new \App\Events\MessageSent($message))->toOthers();

        return response()->json($message);
    }
}
