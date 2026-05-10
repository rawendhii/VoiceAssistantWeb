<?php

namespace App\Controller;

use App\Service\GeminiChatService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class GeminiController extends AbstractController
{
    #[Route('/api/gemini/chat', name: 'api_gemini_chat', methods: ['POST', 'OPTIONS'])]
    public function chat(Request $request, GeminiChatService $geminiChatService): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->json([
                'success' => true,
                'message' => 'CORS preflight accepted.',
            ]);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
                'speech' => 'Invalid JSON body.',
            ], 400);
        }

        $text = trim((string) ($data['text'] ?? ''));
        $language = trim((string) ($data['language'] ?? 'en-US'));

        if ($text === '') {
            return $this->json([
                'success' => false,
                'message' => 'The text field is required.',
                'speech' => 'The text field is required.',
            ], 400);
        }

        $result = $geminiChatService->chat($text, $language);

        if (!is_array($result)) {
            return $this->json([
                'success' => false,
                'message' => 'Gemini returned an invalid response.',
                'speech' => 'Gemini returned an invalid response.',
            ], 500);
        }

        return $this->json($result);
    }
}