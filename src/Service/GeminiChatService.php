<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiChatService
{
    private HttpClientInterface $httpClient;
    private string $geminiApiKey;

    public function __construct(HttpClientInterface $httpClient, string $geminiApiKey)
    {
        $this->httpClient = $httpClient;
        $this->geminiApiKey = $geminiApiKey;
    }

    public function chat(string $text, string $language = 'en-US'): array
    {
        $text = trim($text);

        if ($text === '') {
            return [
                'success' => false,
                'message' => 'Please write or say something first.',
                'speech' => 'Please write or say something first.',
            ];
        }

        if ($this->geminiApiKey === '') {
            return [
                'success' => false,
                'message' => 'Gemini API key is missing. Please configure GEMINI_API_KEY in .env.local.',
                'speech' => 'Gemini API key is missing.',
            ];
        }

        $prompt = <<<PROMPT
You are Voice Assistant, an accessibility-focused chatbot integrated into a web and desktop project.

The project helps people with disabilities, people with reduced mobility, people with visual limitations, and users who need hands-free digital support.

Answer like a helpful, clear, friendly assistant.
Keep the answer short and useful.
Answer in the same language as the user when possible.

User language: {$language}
User message: {$text}
PROMPT;

        try {
            $response = $this->httpClient->request(
                'POST',
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $this->geminiApiKey,
                    ],
                    'json' => [
                        'contents' => [
                            [
                                'parts' => [
                                    [
                                        'text' => $prompt,
                                    ],
                                ],
                            ],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.4,
                            'maxOutputTokens' => 500,
                        ],
                    ],
                ]
            );

            $data = $response->toArray(false);

            $answer = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if ($answer === '') {
                return [
                    'success' => false,
                    'message' => 'Gemini returned an empty response.',
                    'speech' => 'Gemini returned an empty response.',
                    'raw' => $data,
                ];
            }

            return [
                'success' => true,
                'intent' => 'CHAT',
                'message' => trim($answer),
                'speech' => trim($answer),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Gemini request failed: ' . $exception->getMessage(),
                'speech' => 'Gemini request failed. Please check the server configuration.',
            ];
        }
    }
}