<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CommandHistoryService;
use App\Service\VoiceActionExecutorService;
use App\Service\VoiceCommandMatcherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class VoiceController extends AbstractController
{
    #[Route('/voice/execute', name: 'voice_execute', methods: ['POST'])]
    public function execute(
        Request $request,
        VoiceCommandMatcherService $matcherService,
        VoiceActionExecutorService $executorService,
        CommandHistoryService $historyService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $text = trim((string) ($data['text'] ?? ''));

        if ($text === '') {
            return $this->json([
                'success' => false,
                'message' => 'No voice text received.',
            ]);
        }

        /** @var User|null $user */
        $user = $this->getUser();

        $command = $matcherService->match($text);

        if ($command === null) {
            $historyService->log($user, null, $text, 'FAILED');

            return $this->json([
                'success' => false,
                'message' => 'I did not understand your command. Please try again slowly.',
                'spokenText' => $text,
            ]);
        }

        $executionResult = $executorService->execute(
            $text,
            (string) $command->getKeyword(),
            $user
        );

        $historyService->log($user, $command, $text, 'SUCCESS');

        return $this->json([
            'success' => true,
            'label' => $command->getLabel(),
            'keyword' => $command->getKeyword(),
            'description' => $command->getDescription(),
            'message' => $executionResult['message'] ?? 'Command executed successfully.',
            'execution' => $executionResult,
        ]);
    }
}