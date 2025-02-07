<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use EchoLabs\Prism\Prism;
use EchoLabs\Prism\Enums\Provider;
use EchoLabs\Prism\Schema\ObjectSchema;
use EchoLabs\Prism\Schema\StringSchema;
use EchoLabs\Prism\ValueObjects\Messages\AssistantMessage;
use EchoLabs\Prism\ValueObjects\Messages\SystemMessage;
use EchoLabs\Prism\ValueObjects\Messages\UserMessage;

use function Pest\Laravel\json;

class ChatWithAIController extends Controller
{
    public function index(Request $request)
    {
        $systemMessageContent = Setting::where('key', 'chat_agent_prompt')->value('value');
        // Retrieve input data
        $jobTitle = 'Laravel Developer';
        $experienceLevel = 'Intermediate';
        $customQuestions = ['What is Laravel?', 'Explain MVC architecture.', 'How do you manage state in React?'];

        $formattedMessage = sprintf($systemMessageContent, $jobTitle, $experienceLevel, json_encode($customQuestions));

        $systemMessage = new SystemMessage($formattedMessage);

        $conversationChat = [];
        $messages = [$systemMessage];

        $messages[] = new AssistantMessage('What is Laravel');

        $messages[] = new UserMessage('Laravel is a php framework created by Taylor Otwall');

        // Add previous conversation chats
        foreach ($conversationChat as $chat) {
            if ($chat['role'] === 'user') {
                $messages[] = new UserMessage($chat['content']);
            } elseif ($chat['role'] === 'assistant') {
                $messages[] = new AssistantMessage($chat['content']);
            }
        }


        $questionSchema = new ObjectSchema(
            name: 'interview_question',
            description: 'A structured interview question',
            properties: [
                new StringSchema('question', 'The interview question text'),
                new StringSchema('topic', 'The topic of the question'),
                new StringSchema('difficulty', 'The difficulty level of the question')
            ],
            requiredFields: ['question', 'topic', 'difficulty']
        );


        // Generate the structured interview question
        $response = Prism::structured()
            ->using(Provider::OpenAI, 'gpt-4o-mini')
            ->withSchema($questionSchema)
            ->withMessages($messages)
            ->generate();

        // Extract the structured data
        $structuredData = $response->structured;

        // Return the generated question as a JSON response
        return response()->json(['question' => $structuredData['question']]);
    }
}
