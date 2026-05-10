<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiCommandPlannerService
{
    public function __construct(
        private readonly string $geminiApiKey,
        private readonly HttpClientInterface $httpClient,
        private readonly string $geminiModel = 'gemini-2.5-flash'
    ) {
    }

    public function planCommand(
        string $spokenText,
        string $language = 'en-US',
        ?array $browserContext = null
    ): array {
        $spokenText = trim($spokenText);

        if ($spokenText === '') {
            return [
                'success' => false,
                'message' => 'Empty command.',
                'result' => null,
            ];
        }

        if (trim($this->geminiApiKey) === '') {
            return [
                'success' => false,
                'message' => 'GEMINI_API_KEY is missing.',
                'result' => null,
            ];
        }

        try {
            $prompt = $this->buildPrompt($spokenText, $language, $browserContext);

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
                        'temperature' => 0.15,
                        'topP' => 0.8,
                        'topK' => 40,
                        'maxOutputTokens' => 700,
                        'responseMimeType' => 'application/json',
                    ],
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);

            if ($statusCode < 200 || $statusCode >= 300) {
                return [
                    'success' => false,
                    'message' => 'Gemini command planner returned HTTP ' . $statusCode,
                    'result' => null,
                    'raw' => $payload,
                ];
            }

            $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (!is_string($text) || trim($text) === '') {
                return [
                    'success' => false,
                    'message' => 'Gemini returned an empty command plan.',
                    'result' => null,
                    'raw' => $payload,
                ];
            }

            $json = $this->extractJson($text);
            $decoded = json_decode($json, true);

            if (!is_array($decoded)) {
                return [
                    'success' => false,
                    'message' => 'Gemini returned invalid JSON.',
                    'result' => null,
                    'raw' => [
                        'rawText' => $text,
                        'jsonError' => json_last_error_msg(),
                    ],
                ];
            }

            return [
                'success' => true,
                'message' => 'Command planned successfully.',
                'result' => $this->normalizePlan($decoded),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Gemini command planner failed: ' . $exception->getMessage(),
                'result' => null,
            ];
        }
    }

    private function buildPrompt(string $spokenText, string $language, ?array $browserContext): string
    {
        $contextJson = json_encode(
            $browserContext ?? new \stdClass(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return <<<PROMPT
You are the command planner for an accessibility-focused voice assistant app.

Very important:
- You do NOT execute anything.
- You only return a safe structured JSON plan.
- Symfony will validate your plan.
- A separate executor will execute only approved actions.
- Never claim the action is completed.
- Use positive speech like: "Sure, I will search YouTube for cats now."
- Do not say "I cannot open external websites" when the website is supported.
- Do not invent unsupported app pages.
- Do not invent account deletion, settings, or security pages.
- Keep the speech short, accessible, and natural.

User spoken text:
{$spokenText}

Voice language:
{$language}

Current browser context:
{$contextJson}

Allowed intents:
- APP_NAVIGATION
- WEBSITE_ACTION
- BROWSER_ACTION
- EMAIL_ACTION
- CHAT
- UNKNOWN

Allowed APP_NAVIGATION actions:
- OPEN_HOME
- OPEN_PROFILE
- OPEN_FILES
- OPEN_VOICE_COMMANDS
- CONNECT_GMAIL
- LOGOUT
- CHANGE_PASSWORD
- OPEN_SETTINGS

Allowed WEBSITE_ACTION actions:
- OPEN_URL
- SEARCH

Allowed websites:
- youtube
- google
- facebook
- gmail

Allowed BROWSER_ACTION actions:
- SELECT_RESULT
- CLICK
- TYPE
- SCROLL_DOWN
- SCROLL_UP
- GO_BACK
- GO_FORWARD
- PLAY_VIDEO
- PAUSE_VIDEO
- MUTE
- UNMUTE
- VOLUME_UP
- VOLUME_DOWN
- READ_PAGE
- SUMMARIZE_PAGE
- RETURN_TO_ASSISTANT

Rules:
1. If the user asks a normal question, use CHAT.
2. If the user asks to open profile, files, home, voice commands, Gmail connect, logout, settings, or password page, use APP_NAVIGATION.
3. If the user says "open YouTube", "open ytb", "go to Google", use WEBSITE_ACTION with OPEN_URL.
4. If the user says "search YouTube for cats", "search on YouTube cats", "cats on YouTube", "night club music on YouTube", use WEBSITE_ACTION with SEARCH.
5. If the user already has YouTube in browser context and says "search for cats", use WEBSITE_ACTION with SEARCH and website youtube.
6. If the user says "open first video", "open second video", "open 2nd video", "open video number two", use BROWSER_ACTION SELECT_RESULT with resultPosition.
7. If the user says "open video titled relaxing music", use BROWSER_ACTION CLICK with target.
8. If the user says "scroll down", "scroll up", "pause video", "play video", "mute", "unmute", use BROWSER_ACTION.
9. If the user asks to send an email, use EMAIL_ACTION. Email sending still requires Symfony confirmation.
10. If unsafe or unclear, use UNKNOWN.

Return ONLY this JSON shape:
{
  "intent": "APP_NAVIGATION | WEBSITE_ACTION | BROWSER_ACTION | EMAIL_ACTION | CHAT | UNKNOWN",
  "action": "string or null",
  "website": "youtube | google | facebook | gmail | null",
  "url": null,
  "query": "string or null",
  "target": "string or null",
  "resultPosition": 1,
  "speech": "short natural sentence to speak before execution",
  "confidence": 0.0,
  "reason": "short reason"
}
PROMPT;
    }

    private function normalizePlan(array $plan): array
    {
        $intent = strtoupper(trim((string) ($plan['intent'] ?? 'UNKNOWN')));
        $action = strtoupper(trim((string) ($plan['action'] ?? '')));

        $allowedIntents = [
            'APP_NAVIGATION',
            'WEBSITE_ACTION',
            'BROWSER_ACTION',
            'EMAIL_ACTION',
            'CHAT',
            'UNKNOWN',
        ];

        if (!in_array($intent, $allowedIntents, true)) {
            $intent = 'UNKNOWN';
        }

        if ($action === '' || $action === 'NULL') {
            $action = null;
        }

        return [
            'intent' => $intent,
            'action' => $action,
            'website' => $this->cleanNullableString($plan['website'] ?? null),
            'url' => $this->cleanNullableString($plan['url'] ?? null),
            'query' => $this->cleanNullableString($plan['query'] ?? null),
            'target' => $this->cleanNullableString($plan['target'] ?? null),
            'resultPosition' => $this->cleanNullableInt($plan['resultPosition'] ?? null),
            'speech' => $this->cleanNullableString($plan['speech'] ?? null) ?? 'Sure, I will process that now.',
            'confidence' => $this->cleanConfidence($plan['confidence'] ?? null),
            'reason' => $this->cleanNullableString($plan['reason'] ?? null),
        ];
    }

    private function cleanNullableString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }

        return $value;
    }

    private function cleanNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function cleanConfidence(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        $confidence = (float) $value;

        if ($confidence < 0) {
            return 0.0;
        }

        if ($confidence > 1) {
            return 1.0;
        }

        return $confidence;
    }

    private function extractJson(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }

        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            return substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        return $text;
    }
}