<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiAssistantService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $geminiApiKey
    ) {
    }

    public function understand(string $text, string $language = 'en-US', array $browserContext = []): array
    {
        $text = trim($text);

        if ($text === '') {
            return [
                'success' => false,
                'intent' => 'EMPTY',
                'action' => 'NONE',
                'message' => 'Please say something first.',
                'speech' => 'Please say something first.',
                'requiresConfirmation' => false,
            ];
        }

        if ($this->geminiApiKey === '') {
            return [
                'success' => false,
                'intent' => 'GEMINI_NOT_CONFIGURED',
                'action' => 'NONE',
                'message' => 'Gemini API key is missing.',
                'speech' => 'Gemini API key is missing.',
                'requiresConfirmation' => false,
            ];
        }

        $geminiResult = $this->askGemini($text, $language, $browserContext);

        if ($geminiResult['success'] === true) {
            return $geminiResult;
        }

        $fallback = $this->localFallbackUnderstanding($text, $language);

        if ($fallback['intent'] !== 'UNKNOWN') {
            return array_merge($fallback, [
                'fallbackReason' => $geminiResult['intent'] ?? 'GEMINI_FAILED',
                'geminiError' => $geminiResult['message'] ?? null,
            ]);
        }

        return $geminiResult;
    }

    private function askGemini(string $text, string $language, array $browserContext = []): array
    {
        $browserContextJson = json_encode(
            $browserContext,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $prompt = <<<PROMPT
You are the AI brain of an accessibility-focused project called Voice Assistant.

The project helps people with disabilities, reduced mobility, visual limitations, and users who need hands-free digital support.

Your job is to understand the user message and return ONLY valid JSON.

Important distinction:
A question is CHAT, not an action.
A direct command is an action.

Examples:
"Can you tell me how to search on YouTube?" => CHAT
"How do I search on Google?" => CHAT
"What is Gmail?" => CHAT
"Explain how to send an email" => CHAT

"Open YouTube" => BROWSER_ACTION / OPEN_WEBSITE
"Search YouTube for relaxing music" => BROWSER_ACTION / SEARCH_WEBSITE
"Open profile" => NAVIGATE / PROFILE
"Show my files" => NAVIGATE / FILES
"Send an email to john@example.com saying hello" => EMAIL_PREPARE

Supported intents:
CHAT
NAVIGATE
BROWSER_ACTION
EMAIL_PREPARE
HELP
UNKNOWN

Supported navigation targets:
HOME
PROFILE
FILES
VOICE_COMMANDS
LOGOUT

Supported browser commands:
OPEN_WEBSITE
SEARCH_WEBSITE
GO_BACK
GO_FORWARD
SCROLL_DOWN
SCROLL_UP
READ_PAGE
CLICK_RESULT
PLAY
PAUSE
MUTE
UNMUTE

Rules:
1. Answer in the same language as the user when possible.
2. If the user asks a question, return CHAT and answer normally.
3. If the user gives a direct command, return the correct structured action.
4. Never say an email was sent.
5. Only prepare emails.
6. Dangerous actions must require confirmation.
7. Return valid JSON only.
8. Do not use markdown.
9. Do not wrap JSON in ```.

Current browser context:
{$browserContextJson}

User language:
{$language}

User message:
{$text}

Return exactly this JSON structure:
{
  "success": true,
  "intent": "CHAT",
  "action": "NONE",
  "message": "short visible answer",
  "speech": "short spoken answer",
  "requiresConfirmation": false,
  "navigation": {
    "target": null
  },
  "browser": {
    "command": null,
    "website": null,
    "websiteName": null,
    "url": null,
    "query": null,
    "resultPosition": null
  },
  "email": {
    "to": null,
    "subject": null,
    "body": null
  }
}
PROMPT;

        try {
            $response = $this->httpClient->request(
                'POST',
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
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
                            'temperature' => 0.1,
                            'maxOutputTokens' => 1000,
                            'responseMimeType' => 'application/json',
                        ],
                    ],
                ]
            );

            $content = $response->getContent(false);
            $data = json_decode($content, true);

            if (!is_array($data)) {
                return [
                    'success' => false,
                    'intent' => 'GEMINI_INVALID_HTTP_RESPONSE',
                    'action' => 'NONE',
                    'message' => 'Gemini returned an invalid HTTP response.',
                    'speech' => 'Gemini returned an invalid response.',
                    'requiresConfirmation' => false,
                    'rawHttp' => $content,
                ];
            }

            if (isset($data['error'])) {
                return [
                    'success' => false,
                    'intent' => 'GEMINI_API_ERROR',
                    'action' => 'NONE',
                    'message' => $data['error']['message'] ?? 'Gemini API returned an error.',
                    'speech' => 'Gemini API returned an error.',
                    'requiresConfirmation' => false,
                    'debug' => $data,
                ];
            }

            $rawText = '';

            if (isset($data['candidates'][0]['content']['parts']) && is_array($data['candidates'][0]['content']['parts'])) {
                foreach ($data['candidates'][0]['content']['parts'] as $part) {
                    if (isset($part['text'])) {
                        $rawText .= $part['text'];
                    }
                }
            }

            $rawText = trim($rawText);

            if ($rawText === '') {
                return [
                    'success' => false,
                    'intent' => 'GEMINI_EMPTY_RESPONSE',
                    'action' => 'NONE',
                    'message' => 'Gemini returned an empty response.',
                    'speech' => 'Gemini returned an empty response.',
                    'requiresConfirmation' => false,
                    'debug' => [
                        'finishReason' => $data['candidates'][0]['finishReason'] ?? null,
                        'rawData' => $data,
                    ],
                ];
            }

            $jsonText = $this->extractJson($rawText);
            $decoded = json_decode($jsonText, true);

            if (!is_array($decoded)) {
                return [
                    'success' => false,
                    'intent' => 'GEMINI_INVALID_JSON',
                    'action' => 'NONE',
                    'message' => 'Gemini answered, but the response was not valid JSON.',
                    'speech' => 'Gemini answered, but the response was not valid JSON.',
                    'requiresConfirmation' => false,
                    'raw' => $rawText,
                ];
            }

            return $this->normalizeResult($decoded);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'intent' => 'GEMINI_ERROR',
                'action' => 'NONE',
                'message' => 'Gemini request failed: ' . $exception->getMessage(),
                'speech' => 'Gemini request failed. Please check the server configuration.',
                'requiresConfirmation' => false,
            ];
        }
    }

    private function localFallbackUnderstanding(string $text, string $language = 'en-US'): array
    {
        $normalized = $this->normalizeText($text);

        if ($this->looksLikeQuestion($normalized)) {
            return $this->normalizeResult([
                'success' => true,
                'intent' => 'CHAT',
                'action' => 'NONE',
                'message' => $this->basicQuestionAnswer($text, $normalized),
                'speech' => $this->basicQuestionAnswer($text, $normalized),
                'requiresConfirmation' => false,
            ]);
        }

        if (preg_match('/^(open|go to|launch)\s+(youtube)$/i', $text)) {
            return $this->browserOpenWebsite('youtube', 'YouTube', 'https://www.youtube.com');
        }

        if (preg_match('/^(open|go to|launch)\s+(google)$/i', $text)) {
            return $this->browserOpenWebsite('google', 'Google', 'https://www.google.com');
        }

        if (preg_match('/^(open|go to|launch)\s+(gmail)$/i', $text)) {
            return $this->browserOpenWebsite('gmail', 'Gmail', 'https://mail.google.com');
        }

        if (preg_match('/search\s+youtube\s+for\s+(.+)/i', $text, $matches)) {
            return $this->browserSearchWebsite('youtube', 'YouTube', trim($matches[1]));
        }

        if (preg_match('/search\s+google\s+for\s+(.+)/i', $text, $matches)) {
            return $this->browserSearchWebsite('google', 'Google', trim($matches[1]));
        }

        if (
            str_contains($normalized, 'open profile') ||
            $normalized === 'profile' ||
            $normalized === 'my profile'
        ) {
            return $this->navigationResult('PROFILE', 'Opening your profile.', 'Opening your profile.');
        }

        if (
            str_contains($normalized, 'open files') ||
            str_contains($normalized, 'show my files') ||
            $normalized === 'files' ||
            $normalized === 'my files'
        ) {
            return $this->navigationResult('FILES', 'Opening your files.', 'Opening your files.');
        }

        if (
            str_contains($normalized, 'open home') ||
            str_contains($normalized, 'go home') ||
            $normalized === 'home' ||
            $normalized === 'dashboard'
        ) {
            return $this->navigationResult('HOME', 'Opening home.', 'Opening home.');
        }

        if (
            str_contains($normalized, 'voice commands') ||
            str_contains($normalized, 'show commands')
        ) {
            return $this->navigationResult('VOICE_COMMANDS', 'Opening voice commands.', 'Opening voice commands.');
        }

        return $this->normalizeResult([
            'success' => true,
            'intent' => 'UNKNOWN',
            'action' => 'NONE',
            'message' => 'I understood your words, but I am not sure what action you want.',
            'speech' => 'I understood your words, but I am not sure what action you want.',
            'requiresConfirmation' => false,
        ]);
    }

    private function looksLikeQuestion(string $normalized): bool
    {
        return (
            str_starts_with($normalized, 'what ') ||
            str_starts_with($normalized, 'what is') ||
            str_starts_with($normalized, 'what are') ||
            str_starts_with($normalized, 'how ') ||
            str_starts_with($normalized, 'how do i') ||
            str_starts_with($normalized, 'how can i') ||
            str_starts_with($normalized, 'can you tell me') ||
            str_starts_with($normalized, 'could you tell me') ||
            str_starts_with($normalized, 'tell me how') ||
            str_starts_with($normalized, 'explain') ||
            str_starts_with($normalized, 'describe') ||
            str_contains($normalized, 'can you tell me how') ||
            str_contains($normalized, 'i want to know')
        );
    }

    private function basicQuestionAnswer(string $originalText, string $normalized): string
    {
        if (str_contains($normalized, 'search') && str_contains($normalized, 'youtube')) {
            return 'To search on YouTube, open YouTube, click the search bar, type what you want to find, and press Enter. You can also say a direct command like: search YouTube for relaxing music.';
        }

        if (str_contains($normalized, 'search') && str_contains($normalized, 'google')) {
            return 'To search on Google, open Google, type your question or keywords in the search bar, and press Enter.';
        }

        if (str_contains($normalized, 'send') && str_contains($normalized, 'email')) {
            return 'To send an email, you need a recipient, a subject, and a message body. For example, you can say: prepare an email to john@example.com saying hello.';
        }

        return 'I can answer questions and help explain things. Ask me what you want to know, or give me a direct command if you want an action.';
    }

    private function browserOpenWebsite(string $website, string $websiteName, string $url): array
    {
        return $this->normalizeResult([
            'success' => true,
            'intent' => 'BROWSER_ACTION',
            'action' => 'OPEN_WEBSITE',
            'message' => 'Opening ' . $websiteName . '.',
            'speech' => 'Opening ' . $websiteName . '.',
            'requiresConfirmation' => false,
            'browser' => [
                'command' => 'OPEN_WEBSITE',
                'website' => $website,
                'websiteName' => $websiteName,
                'url' => $url,
                'query' => null,
                'resultPosition' => null,
            ],
        ]);
    }

    private function browserSearchWebsite(string $website, string $websiteName, string $query): array
    {
        return $this->normalizeResult([
            'success' => true,
            'intent' => 'BROWSER_ACTION',
            'action' => 'SEARCH_WEBSITE',
            'message' => 'Searching ' . $websiteName . ' for ' . $query . '.',
            'speech' => 'Searching ' . $websiteName . ' for ' . $query . '.',
            'requiresConfirmation' => false,
            'browser' => [
                'command' => 'SEARCH_WEBSITE',
                'website' => $website,
                'websiteName' => $websiteName,
                'url' => null,
                'query' => $query,
                'resultPosition' => null,
            ],
        ]);
    }

    private function navigationResult(string $target, string $message, string $speech): array
    {
        return $this->normalizeResult([
            'success' => true,
            'intent' => 'NAVIGATE',
            'action' => 'OPEN_PAGE',
            'message' => $message,
            'speech' => $speech,
            'requiresConfirmation' => false,
            'navigation' => [
                'target' => $target,
            ],
        ]);
    }

    private function normalizeResult(array $result): array
    {
        return array_merge([
            'success' => true,
            'intent' => 'CHAT',
            'action' => 'NONE',
            'message' => 'I understood.',
            'speech' => 'I understood.',
            'requiresConfirmation' => false,
            'navigation' => [
                'target' => null,
            ],
            'browser' => [
                'command' => null,
                'website' => null,
                'websiteName' => null,
                'url' => null,
                'query' => null,
                'resultPosition' => null,
            ],
            'email' => [
                'to' => null,
                'subject' => null,
                'body' => null,
            ],
        ], $result);
    }

    private function normalizeText(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/\s+/', ' ', $text);

        return $text ?: '';
    }

    private function extractJson(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '```json')) {
            $text = preg_replace('/^```json\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            return trim((string) $text);
        }

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            return trim((string) $text);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }
}