<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class InternalVoiceCommandInterpreterService
{
    private const ALLOWED_COMMANDS = [
        'open profile',
        'edit profile',
        'change password',
        'delete account',
        'show my files',
        'show voice commands',
        'go home',
        'logout',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $huggingFaceToken,
        private string $huggingFaceModel
    ) {
    }

    public function interpret(string $spokenText): array
    {
        $spokenText = trim($spokenText);

        if ($spokenText === '') {
            return [
                'success' => false,
                'source' => 'llm_internal',
                'message' => 'Empty command.',
            ];
        }

        if (trim($this->huggingFaceToken) === '') {
            return [
                'success' => false,
                'source' => 'llm_internal',
                'message' => 'Hugging Face token is missing.',
            ];
        }

        $allowedCommandsText = implode("\n", array_map(
            fn (string $command) => '- ' . $command,
            self::ALLOWED_COMMANDS
        ));

        $systemPrompt = <<<PROMPT
You are an intent classifier for an accessible Symfony web voice assistant.

Return only valid JSON. Do not use markdown. Do not explain.

Your task:
Map the user's natural spoken sentence to exactly ONE safe internal web-app command.

Allowed commands:
{$allowedCommandsText}

Required JSON shape:
{
  "intent": "INTERNAL_COMMAND or UNKNOWN",
  "command": "one allowed command or null",
  "confidence": 0.0,
  "reason": "short reason"
}

Core rules:
- Use only the allowed commands.
- Never invent commands.
- Never execute anything. Only classify.
- If the user request is unclear, outside the web app, or unsafe, return UNKNOWN.
- If multiple commands could match, choose the safest and most likely command.
- Return UNKNOWN for local computer control, desktop actions, creating files, deleting files, renaming files, editing files, writing files, reading local files, or modifying local folders.

Command meanings:
- "open profile": user wants to view their profile/account page without editing.
- "edit profile": user wants to change/update/edit personal information, name, email, profile, account information, or personal details.
- "change password": user wants to change password, reset password, update password, make password stronger, improve account security, secure account, or protect account.
- "delete account": user wants to delete, remove, close, or deactivate their account.
- "show my files": user wants to see uploaded files, documents, stored files, or files inside the web application. Do not use this for creating/editing/deleting files.
- "show voice commands": user asks what they can say, available commands, command list, help with voice commands.
- "go home": user wants to return to home, main page, dashboard, start page, or user area.
- "logout": user wants to logout, log out, sign out, disconnect, exit account, or end session.

Safety rules:
- User: create a file / write a file / delete a file / rename a file / edit a file / update a file
  → UNKNOWN
- User: open YouTube / search Google / find something on Facebook
  → UNKNOWN because that belongs to the website interpreter, not this internal interpreter.
- User: click buttons on external websites
  → UNKNOWN
- User: run programs or control the desktop
  → UNKNOWN

Examples:
User: I want to change my name and my email
Assistant: {"intent":"INTERNAL_COMMAND","command":"edit profile","confidence":0.95,"reason":"The user wants to update profile information."}

User: update my personal information
Assistant: {"intent":"INTERNAL_COMMAND","command":"edit profile","confidence":0.93,"reason":"The user wants to edit personal details."}

User: I want to make my password stronger
Assistant: {"intent":"INTERNAL_COMMAND","command":"change password","confidence":0.9,"reason":"The user wants to improve password security."}

User: I want to make my key more powerful
Assistant: {"intent":"UNKNOWN","command":null,"confidence":0.0,"reason":"The request is unclear and does not map safely to an allowed internal command."}

User: show my uploaded documents
Assistant: {"intent":"INTERNAL_COMMAND","command":"show my files","confidence":0.9,"reason":"The user wants to view uploaded files inside the app."}

User: what can I say
Assistant: {"intent":"INTERNAL_COMMAND","command":"show voice commands","confidence":0.9,"reason":"The user asks for available commands."}

User: take me back to the dashboard
Assistant: {"intent":"INTERNAL_COMMAND","command":"go home","confidence":0.88,"reason":"The user wants to return to the main user area."}

User: disconnect me
Assistant: {"intent":"INTERNAL_COMMAND","command":"logout","confidence":0.9,"reason":"The user wants to end the session."}

User: create a file called test
Assistant: {"intent":"UNKNOWN","command":null,"confidence":0.0,"reason":"Creating local files is not an allowed internal web command."}

User: search YouTube for relaxing music
Assistant: {"intent":"UNKNOWN","command":null,"confidence":0.0,"reason":"Website search belongs to the website interpreter."}
PROMPT;
        try {
            $response = $this->httpClient->request('POST', 'https://router.huggingface.co/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->huggingFaceToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->huggingFaceModel,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $spokenText,
                        ],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 120,
                ],
            ]);

            $data = $response->toArray(false);

            if (isset($data['error'])) {
                return [
                    'success' => false,
                    'source' => 'llm_internal',
                    'message' => 'Hugging Face API error: ' . ($data['error']['message'] ?? $data['error']),
                ];
            }

            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                return [
                    'success' => false,
                    'source' => 'llm_internal',
                    'message' => 'The LLM response did not contain output text.',
                    'raw' => $data,
                ];
            }

            $json = $this->extractJson($content);

            if ($json === null) {
                return [
                    'success' => false,
                    'source' => 'llm_internal',
                    'message' => 'The LLM response was not valid JSON.',
                    'rawText' => $content,
                ];
            }

            $intent = strtoupper((string) ($json['intent'] ?? 'UNKNOWN'));
            $command = $json['command'] ?? null;
            $confidence = (float) ($json['confidence'] ?? 0);

            if ($intent !== 'INTERNAL_COMMAND' || !$command) {
                return [
                    'success' => false,
                    'source' => 'llm_internal',
                    'message' => 'The LLM did not detect an internal command.',
                    'llmResult' => $json,
                ];
            }

            $command = mb_strtolower(trim((string) $command));

            if (!in_array($command, self::ALLOWED_COMMANDS, true)) {
                return [
                    'success' => false,
                    'source' => 'llm_internal',
                    'message' => 'The LLM returned a command that is not allowed.',
                    'llmResult' => $json,
                ];
            }

            if ($confidence < 0.55) {
                return [
                    'success' => false,
                    'source' => 'llm_internal',
                    'message' => 'The LLM confidence was too low.',
                    'llmResult' => $json,
                ];
            }

            return [
                'success' => true,
                'source' => 'llm_internal',
                'command' => $command,
                'confidence' => $confidence,
                'llmResult' => $json,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'source' => 'llm_internal',
                'message' => 'Internal LLM request failed: ' . $exception->getMessage(),
            ];
        }
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        $decoded = json_decode($text, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}