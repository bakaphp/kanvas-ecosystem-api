<?php


 $chat_history = [
    [
        "role" => "user",
        "content" => "add two glasses of beer on the table",
        "timestamp" => 1762457218
    ],
    [
        "role" => "assistant",
        "content" => "https://cdn.promptmine.ai/v05htwRaifegI7W2JIo6iGFv8PIhLTRYo3MgOdIS.png",
        "timestamp" => 1762457218
    ],
    [
        "role" => "user",
        "content" => "add two glasses of beer on the table",
        "timestamp" => 1762457218
    ],
    [
        "role" => "assistant",
        "content" => "https://cdn.promptmine.ai/hU54FlhKzjKy2e2dUG6KMhjXpq9vxykehPCKIXlL.png",
        "timestamp" => 1762457218
    ],
    [
        "role" => "user",
        "content" => "turn on of them blue",
        "timestamp" => 1762457218
    ],
    [
        "role" => "assistant",
        "content" => "https://cdn.promptmine.ai/qWi5Olt5tsqdwPKRAnEDlggQecph9tgfPFftwD1V.png",
        "timestamp" => 1762457218
    ],
    [
        "role" => "user",
        "content" => "turn on of them blue",
        "timestamp" => 1762457218
    ],
    [
        "role" => "assistant",
        "content" => "https://cdn.promptmine.ai/3afHDgXaQWQP19jvjQex3q7JRX6AKNBZRXa3Gos5.png",
        "timestamp" => 1762457218
    ]
];


function getLastAssistantResponse(array $chatHistory): array
    {
        $assistantMessages = $chatHistory;
        // $assistantMessages = array_filter($chatHistory, function ($item) {
        //     return $item['role'] === 'assistant';
        // });
        print_r($assistantMessages);
        array_pop($assistantMessages);
        $assistantMessages[key($assistantMessages)]['original_index'] = key($assistantMessages);

        print_r($assistantMessages);

        return end($assistantMessages);
    }


$lastAssistantResponse = getLastAssistantResponse($chat_history);
var_dump($lastAssistantResponse);