<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class WebsiteActionResolverService
{
    public function __construct(
        private Connection $connection
    ) {
    }

    public function resolve(string $spokenText): array
    {
        $spokenText = trim($spokenText);

        if ($spokenText === '') {
            return [
                'success' => false,
                'message' => 'No text received.',
            ];
        }

        $normalizedText = mb_strtolower($spokenText);

        $websites = $this->connection->fetchAllAssociative("
            SELECT id, name, slug, base_url, category
            FROM ai_websites
            WHERE is_active = 1
            ORDER BY id ASC
        ");

        if ($websites === []) {
            return [
                'success' => false,
                'message' => 'No active websites configured in the database.',
            ];
        }

        $matchedWebsite = null;

        foreach ($websites as $website) {
            $slug = mb_strtolower((string) $website['slug']);
            $name = mb_strtolower((string) $website['name']);

            if (
                str_contains($normalizedText, $slug) ||
                str_contains($normalizedText, $name)
            ) {
                $matchedWebsite = $website;
                break;
            }
        }

        if ($matchedWebsite === null) {
            return [
                'success' => false,
                'message' => 'No supported website was detected in the command.',
            ];
        }

        $actionSlug = 'search';

        $action = $this->connection->fetchAssociative("
            SELECT 
                a.id,
                a.action_name,
                a.action_slug,
                a.description,
                a.url_template,
                a.http_method,
                a.target_type,
                a.requires_query,
                a.requires_auth,
                a.open_inside_assistant
            FROM ai_website_actions a
            WHERE a.website_id = :websiteId
            AND a.action_slug = :actionSlug
            AND a.is_active = 1
            LIMIT 1
        ", [
            'websiteId' => $matchedWebsite['id'],
            'actionSlug' => $actionSlug,
        ]);

        if ($action === false) {
            return [
                'success' => false,
                'message' => 'This website exists, but the requested action is not configured.',
                'website' => $matchedWebsite['slug'],
            ];
        }

        $query = $this->extractSearchQuery(
            $spokenText,
            (string) $matchedWebsite['slug'],
            (string) $matchedWebsite['name']
        );

        if ((int) $action['requires_query'] === 1 && $query === null) {
            return [
                'success' => false,
                'message' => 'The search command needs a query.',
                'website' => $matchedWebsite['slug'],
                'action' => $action['action_slug'],
            ];
        }

        $url = (string) $action['url_template'];

        if ($query !== null) {
            $url = str_replace('{query}', urlencode($query), $url);
        }

        return [
            'success' => true,
            'intent' => 'WEBSITE_ACTION',
            'website' => (string) $matchedWebsite['slug'],
            'websiteName' => (string) $matchedWebsite['name'],
            'action' => (string) $action['action_slug'],
            'actionName' => (string) $action['action_name'],
            'query' => $query,
            'target' => (string) $action['target_type'],
            'requiresAuth' => (bool) $action['requires_auth'],
            'openInsideAssistant' => (bool) $action['open_inside_assistant'],
            'url' => $url,
            'message' => 'Website action resolved successfully.',
        ];
    }

    private function extractSearchQuery(string $spokenText, string $websiteSlug, string $websiteName): ?string
    {
        $text = trim($spokenText);

        $websiteSlug = preg_quote($websiteSlug, '/');
        $websiteName = preg_quote($websiteName, '/');

        $patterns = [
            // search youtube for relaxing music
            '/^search\s+(' . $websiteSlug . '|' . $websiteName . ')\s+for\s+(.+)$/i',

            // search on youtube for relaxing music
            '/^search\s+on\s+(' . $websiteSlug . '|' . $websiteName . ')\s+for\s+(.+)$/i',

            // youtube search relaxing music
            '/^(' . $websiteSlug . '|' . $websiteName . ')\s+search\s+(.+)$/i',

            // find relaxing music on youtube
            '/^find\s+(.+)\s+on\s+(' . $websiteSlug . '|' . $websiteName . ')$/i',

            // look for relaxing music on youtube
            '/^look\s+for\s+(.+)\s+on\s+(' . $websiteSlug . '|' . $websiteName . ')$/i',

            // search relaxing music on youtube
            '/^search\s+(.+)\s+on\s+(' . $websiteSlug . '|' . $websiteName . ')$/i',
        ];

        foreach ($patterns as $index => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                if ($index <= 2) {
                    $query = trim((string) ($matches[2] ?? ''));
                } else {
                    $query = trim((string) ($matches[1] ?? ''));
                }

                return $query !== '' ? $query : null;
            }
        }

        return null;
    }
    public function getAllowedActionsForLlm(): array
{
    $rows = $this->connection->fetchAllAssociative("
        SELECT
            w.slug AS website,
            w.name AS websiteName,
            a.action_slug AS action,
            a.action_name AS actionName,
            a.description AS description,
            a.requires_query AS requiresQuery,
            a.target_type AS target
        FROM ai_website_actions a
        JOIN ai_websites w ON a.website_id = w.id
        WHERE w.is_active = 1
        AND a.is_active = 1
        ORDER BY w.slug ASC, a.action_slug ASC
    ");

    return array_map(static function (array $row): array {
        return [
            'website' => (string) $row['website'],
            'websiteName' => (string) $row['websiteName'],
            'action' => (string) $row['action'],
            'actionName' => (string) $row['actionName'],
            'description' => (string) ($row['description'] ?? ''),
            'requiresQuery' => (bool) $row['requiresQuery'],
            'target' => (string) $row['target'],
        ];
    }, $rows);
}

public function resolveFromLlmResult(array $llmResult): array
{
    $intent = (string) ($llmResult['intent'] ?? 'UNKNOWN');

    if ($intent !== 'WEBSITE_ACTION') {
        return [
            'success' => false,
            'message' => 'The LLM did not detect a supported website action.',
            'llmResult' => $llmResult,
        ];
    }

    $websiteSlug = mb_strtolower(trim((string) ($llmResult['website'] ?? '')));
    $actionSlug = mb_strtolower(trim((string) ($llmResult['action'] ?? '')));
    $query = $llmResult['query'] ?? null;

    if ($websiteSlug === '' || $actionSlug === '') {
        return [
            'success' => false,
            'message' => 'The LLM result is missing website or action.',
            'llmResult' => $llmResult,
        ];
    }

    if (is_string($query)) {
        $query = trim($query);
        $query = $query !== '' ? $query : null;
    } else {
        $query = null;
    }

    $action = $this->connection->fetchAssociative("
        SELECT
            w.slug AS website,
            w.name AS website_name,
            a.action_name,
            a.action_slug,
            a.url_template,
            a.target_type,
            a.requires_query,
            a.requires_auth,
            a.open_inside_assistant
        FROM ai_website_actions a
        JOIN ai_websites w ON a.website_id = w.id
        WHERE w.slug = :websiteSlug
        AND a.action_slug = :actionSlug
        AND w.is_active = 1
        AND a.is_active = 1
        LIMIT 1
    ", [
        'websiteSlug' => $websiteSlug,
        'actionSlug' => $actionSlug,
    ]);

    if ($action === false) {
        return [
            'success' => false,
            'message' => 'The LLM selected an action that is not authorized in the database.',
            'llmResult' => $llmResult,
        ];
    }

    if ((int) $action['requires_query'] === 1 && $query === null) {
        return [
            'success' => false,
            'message' => 'The selected action requires a query.',
            'llmResult' => $llmResult,
        ];
    }

    $url = (string) $action['url_template'];

    if ($query !== null) {
        $url = str_replace('{query}', urlencode($query), $url);
    }

    return [
        'success' => true,
        'intent' => 'WEBSITE_ACTION',
        'website' => (string) $action['website'],
        'websiteName' => (string) $action['website_name'],
        'action' => (string) $action['action_slug'],
        'actionName' => (string) $action['action_name'],
        'query' => $query,
        'target' => (string) $action['target_type'],
        'requiresAuth' => (bool) $action['requires_auth'],
        'openInsideAssistant' => (bool) $action['open_inside_assistant'],
        'url' => $url,
        'message' => 'Website action resolved successfully using LLM.',
        'llmResult' => $llmResult,
    ];
}
}