<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class AiCommandLogService
{
    public function __construct(
        private Connection $connection
    ) {
    }

    public function log(string $spokenText, array $result, ?int $userId = null): void
    {
        try {
            $this->connection->insert('ai_command_logs', [
                'user_id' => $userId,
                'spoken_text' => $spokenText,
                'detected_intent' => $result['intent'] ?? null,
                'detected_website' => $result['website'] ?? null,
                'detected_action' => $result['action'] ?? null,
                'extracted_query' => $result['query'] ?? null,
                'execution_target' => $result['target'] ?? null,
                'generated_url' => $result['url'] ?? null,
                'success' => ($result['success'] ?? false) ? 1 : 0,
                'error_message' => ($result['success'] ?? false)
                    ? null
                    : ($result['message'] ?? 'Unknown error'),
            ]);
        } catch (\Throwable $exception) {
            // Logging must never break the API.
        }
    }
}