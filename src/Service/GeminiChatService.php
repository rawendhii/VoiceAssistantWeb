<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiChatService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $geminiApiKey,
        private readonly string $geminiModel = 'gemini-2.5-flash'
    ) {
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

        if (trim($this->geminiApiKey) === '') {
            return [
                'success' => false,
                'message' => 'Gemini API key is missing. Please configure GEMINI_API_KEY in .env.local.',
                'speech' => 'Gemini API key is missing.',
            ];
        }

        $prompt = <<<PROMPT
You are Voice Assistant, an accessibility-focused chatbot integrated into a web and future JavaFX desktop project.

The project helps people with disabilities, people with reduced mobility, people with visual limitations, and users who need hands-free digital support.

Important:
- Answer normal questions naturally and helpfully.
- You are not the executor.
- Do not claim that you personally executed actions.
- Do not say external website control is impossible when the app supports it.
- If the user asks about supported actions, explain briefly that the secure executor handles actions.
- Do not invent pages that may not exist.
- Do not tell the user to open Settings, Security, Account Management, or Delete Account unless the app has that page.
- Keep the answer short, clear, friendly, and accessible.
- Answer in the same language as the user when possible.

User language: {$language}

User message:
{$text}
PROMPT;

        try {
            $url = sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
                rawurlencode($this->geminiModel),
                rawurlencode($this->geminiApiKey)
            );

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                [
                                    'text' => $prompt,
                                ],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.4,
                        'topP' => 0.8,
                        'topK' => 40,
                        'maxOutputTokens' => 500,
                    ],
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($statusCode < 200 || $statusCode >= 300) {
                return [
                    'success' => false,
                    'message' => 'Gemini chat returned HTTP ' . $statusCode,
                    'speech' => 'Gemini chat is not available right now.',
                    'raw' => $data,
                ];
            }

            $answer = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (!is_string($answer) || trim($answer) === '') {
                return [
                    'success' => false,
                    'message' => 'Gemini returned an empty response.',
                    'speech' => 'Gemini returned an empty response.',
                    'raw' => $data,
                ];
            }

            $answer = trim($answer);

            return [
                'success' => true,
                'intent' => 'CHAT',
                'mode' => 'GEMINI_CHAT',
                'requiresExtension' => false,
                'message' => $answer,
                'speech' => $answer,
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