<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class DesktopApiController extends AbstractController
{
    #[Route('/api/desktop/ping', name: 'api_desktop_ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'message' => 'Desktop connected to Symfony API successfully.',
            'source' => 'voice-assistant-web',
            'endpoint' => '/api/desktop/ping',
        ]);
    }

    #[Route('/api/desktop/interpret', name: 'api_desktop_interpret', methods: ['POST'])]
    public function interpret(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $text = trim((string) ($data['text'] ?? ''));
        $language = trim((string) ($data['language'] ?? 'en-US'));

        if ($text === '') {
            return $this->json([
                'success' => false,
                'message' => 'Text is required.',
            ], 400);
        }

        return $this->json([
            'success' => true,
            'intent' => 'DESKTOP_TEST',
            'mode' => 'DESKTOP_API_CONNECTED',
            'message' => 'Symfony received desktop command: ' . $text,
            'speech' => 'Symfony received your desktop command.',
            'text' => $text,
            'language' => $language,
        ]);
    }
}