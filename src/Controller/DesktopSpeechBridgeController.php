<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DesktopSpeechBridgeController extends AbstractController
{
    private function cache(): FilesystemAdapter
    {
        return new FilesystemAdapter('desktop_speech_bridge', 0, null);
    }

    #[Route('/desktop/speech-bridge', name: 'desktop_speech_bridge_page', methods: ['GET'])]
    public function bridgePage(Request $request): Response
    {
        $userId = (int) $request->query->get('userId', 0);

        return $this->render('desktop/speech_bridge.html.twig', [
            'userId' => $userId,
        ]);
    }

    #[Route('/api/desktop/speech/push', name: 'api_desktop_speech_push', methods: ['POST'])]
    public function push(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $userId = (int) ($data['userId'] ?? 0);
        $text = trim((string) ($data['text'] ?? ''));
        $language = trim((string) ($data['language'] ?? 'en-US'));

        if ($userId <= 0) {
            return $this->json([
                'success' => false,
                'message' => 'User ID is required.',
            ], 400);
        }

        if ($text === '') {
            return $this->json([
                'success' => false,
                'message' => 'Text is required.',
            ], 400);
        }

        $payload = [
            'id' => time() . '-' . random_int(1000, 9999),
            'userId' => $userId,
            'text' => $text,
            'language' => $language,
            'createdAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $cache = $this->cache();
        $item = $cache->getItem('last_command_' . $userId);
        $item->set($payload);
        $item->expiresAfter(300);
        $cache->save($item);

        return $this->json([
            'success' => true,
            'message' => 'Speech command pushed to desktop.',
            'command' => $payload,
        ]);
    }

    #[Route('/api/desktop/speech/latest', name: 'api_desktop_speech_latest', methods: ['GET'])]
    public function latest(Request $request): JsonResponse
    {
        $userId = (int) $request->query->get('userId', 0);
        $lastId = trim((string) $request->query->get('lastId', ''));

        if ($userId <= 0) {
            return $this->json([
                'success' => false,
                'message' => 'User ID is required.',
            ], 400);
        }

        $cache = $this->cache();
        $item = $cache->getItem('last_command_' . $userId);

        if (!$item->isHit()) {
            return $this->json([
                'success' => true,
                'hasCommand' => false,
            ]);
        }

        $command = $item->get();

        if (!is_array($command)) {
            return $this->json([
                'success' => true,
                'hasCommand' => false,
            ]);
        }

        if (($command['id'] ?? '') === $lastId) {
            return $this->json([
                'success' => true,
                'hasCommand' => false,
            ]);
        }

        return $this->json([
            'success' => true,
            'hasCommand' => true,
            'command' => $command,
        ]);
    }

    #[Route('/api/desktop/speech/clear', name: 'api_desktop_speech_clear', methods: ['POST'])]
    public function clear(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $userId = (int) ($data['userId'] ?? 0);

        if ($userId <= 0) {
            return $this->json([
                'success' => false,
                'message' => 'User ID is required.',
            ], 400);
        }

        $cache = $this->cache();
        $cache->deleteItem('last_command_' . $userId);

        return $this->json([
            'success' => true,
            'message' => 'Speech bridge cleared.',
        ]);
    }
}