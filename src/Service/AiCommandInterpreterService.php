<?php

namespace App\Service;

use App\Repository\VoiceCommandRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiCommandInterpreterService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private VoiceCommandRepository $voiceCommandRepository,
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
                'keyword' => null,
                'confidence' => 0,
                'parameters' => [],
                'message' => 'No text received.',
            ];
        }

        $commands = $this->voiceCommandRepository->findBy(['active' => true], ['id' => 'ASC']);

        if ($commands === []) {
            return [
                'success' => false,
                'keyword' => null,
                'confidence' => 0,
                'parameters' => [],
                'message' => 'No active voice commands are configured.',
            ];
        }

        $allowedCommandsText = '';

        foreach ($commands as $command) {
            $allowedCommandsText .= sprintf(
                "- keyword: %s | label: %s | description: %s\n",
                (string) $command->getKeyword(),
                (string) $command->getLabel(),
                (string) $command->getDescription()
            );
        }

        $systemPrompt = <<<PROMPT
You are an AI command interpreter for an accessibility voice assistant.

Your job:
- Understand the user's natural sentence.
- Choose the closest command from the allowed command list.
- You must only choose a keyword that exists in the allowed list.
- If the user sentence does not match any allowed command, return null.
- Do not invent new commands.
- Extract file names when the user asks for file actions.
- Do not invent or guess file names.
- If the user asks to create a file/document but does not give a clear name, return fileName null.
- Generic words like "file", "document", "text file", "new document", or "new file" are not valid file names.
- Return only valid JSON.
- Confidence must be a number between 0.1 and 1.0.
- Use high confidence, 0.75 to 1.0, when the user's meaning clearly matches one allowed command.
- Use low confidence, 0.1 to 0.4, only when the command is unclear.

Important examples:
- "please make a new document" means keyword "create file", fileName null, content null.
- "create a new file" means keyword "create file", fileName null, content null.
- "make a document" means keyword "create file", fileName null, content null.
- "create a file called report and write hello world" means keyword "create file", fileName "report.txt", content "hello world".
- "make a document named notes with the text remember the meeting tomorrow" means keyword "create file", fileName "notes.txt", content "remember the meeting tomorrow".
- If the user asks to create a file but does not provide content, return content null.
- "go to my account" means keyword "open profile" if it exists.
- "open my personal page" means keyword "open profile" if it exists.
- "show my documents" means keyword "show my files" if it exists.
- "log me out" means keyword "logout" if it exists.
- "sign me out" means keyword "logout" if it exists.
- "take me home" means keyword "go home" if it exists.
- "create a file called report" means keyword "create file" and fileName "report.txt".
- "make a document named homework" means keyword "create file" and fileName "homework.txt".
- "delete file report" means keyword "delete file" and fileName "report.txt".
- "remove the document homework" means keyword "delete file" and fileName "homework.txt".
- "rename report to final report" means keyword "rename file", fileName "report.txt", newFileName "final report.txt".
- "read file notes" means keyword "read file" and fileName "notes.txt".

Allowed commands:
$allowedCommandsText

JSON format:
{
  "keyword": "one allowed keyword or null",
  "confidence": 0.85,
  "parameters": {
    "fileName": "file name with extension or null",
    "newFileName": "new file name with extension or null",
    "content": "file content or null"
  },
  "reason": "short reason"
}
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
                    'max_tokens' => 160,
                    'response_format' => [
                        'type' => 'json_object',
                    ],
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray(false);

            if (isset($data['error'])) {
                return [
                    'success' => false,
                    'keyword' => null,
                    'confidence' => 0,
                    'parameters' => [],
                    'message' => is_array($data['error'])
                        ? json_encode($data['error'])
                        : (string) $data['error'],
                    'raw' => $data,
                ];
            }

            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!is_string($content) || trim($content) === '') {
                return [
                    'success' => false,
                    'keyword' => null,
                    'confidence' => 0,
                    'parameters' => [],
                    'message' => 'AI returned an empty response.',
                    'raw' => $data,
                ];
            }

            $json = json_decode($content, true);

            if (!is_array($json)) {
                return [
                    'success' => false,
                    'keyword' => null,
                    'confidence' => 0,
                    'parameters' => [],
                    'message' => 'AI did not return valid JSON.',
                    'raw' => $data,
                    'content' => $content,
                ];
            }

            $keyword = $json['keyword'] ?? null;
            $confidence = isset($json['confidence']) ? (float) $json['confidence'] : 0;
            $parameters = is_array($json['parameters'] ?? null) ? $json['parameters'] : [];

            if ($keyword === null || $keyword === 'null' || trim((string) $keyword) === '') {
                return [
                    'success' => false,
                    'keyword' => null,
                    'confidence' => $confidence,
                    'parameters' => $parameters,
                    'message' => 'No matching command found by AI.',
                    'raw' => $json,
                ];
            }

            $keyword = trim((string) $keyword);

            $allowedKeywords = array_map(
                static fn ($command) => mb_strtolower((string) $command->getKeyword()),
                $commands
            );

            if (!in_array(mb_strtolower($keyword), $allowedKeywords, true)) {
                return [
                    'success' => false,
                    'keyword' => null,
                    'confidence' => 0,
                    'parameters' => $parameters,
                    'message' => 'AI returned a command that is not allowed.',
                    'raw' => $json,
                ];
            }

            return [
                'success' => true,
                'keyword' => $keyword,
                'confidence' => $confidence,
                'parameters' => $parameters,
                'message' => 'AI matched the command successfully.',
                'raw' => $json,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'keyword' => null,
                'confidence' => 0,
                'parameters' => [],
                'message' => $e->getMessage(),
            ];
        }
    }
}