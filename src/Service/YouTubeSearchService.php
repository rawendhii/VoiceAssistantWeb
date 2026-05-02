<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class YouTubeSearchService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $youtubeApiKey
    ) {
    }

    public function search(string $query, int $maxResults = 6): array
    {
        $query = trim($query);

        if ($query === '') {
            return [
                'success' => false,
                'error' => 'Search query is empty.',
                'items' => [],
            ];
        }

        if (trim($this->youtubeApiKey) === '') {
            return [
                'success' => false,
                'error' => 'YOUTUBE_API_KEY is missing in .env.local.',
                'items' => [],
            ];
        }

        $maxResults = max(1, min($maxResults, 10));

        try {
            $response = $this->httpClient->request('GET', 'https://www.googleapis.com/youtube/v3/search', [
                'query' => [
                    'part' => 'snippet',
                    'q' => $query,
                    'type' => 'video',
                    'maxResults' => $maxResults,
                    'safeSearch' => 'moderate',
                    'key' => $this->youtubeApiKey,
                ],
            ]);

            $data = $response->toArray(false);

            if (isset($data['error'])) {
                return [
                    'success' => false,
                    'error' => $data['error']['message'] ?? 'YouTube API error.',
                    'items' => [],
                ];
            }

            $videos = [];

            foreach (($data['items'] ?? []) as $item) {
                $videoId = $item['id']['videoId'] ?? null;

                if (!$videoId) {
                    continue;
                }

                $snippet = $item['snippet'] ?? [];

                $videos[] = [
                    'videoId' => $videoId,
                    'title' => $snippet['title'] ?? 'Untitled video',
                    'channelTitle' => $snippet['channelTitle'] ?? 'Unknown channel',
                    'description' => $snippet['description'] ?? '',
                    'thumbnail' => $snippet['thumbnails']['medium']['url']
                        ?? $snippet['thumbnails']['default']['url']
                        ?? null,
                    'url' => 'https://www.youtube.com/watch?v=' . $videoId,
                ];
            }

            return [
                'success' => true,
                'error' => null,
                'items' => $videos,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'error' => 'Could not connect to YouTube API: ' . $exception->getMessage(),
                'items' => [],
            ];
        }
    }
}