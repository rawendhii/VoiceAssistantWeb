<?php

namespace App\Controller;

use App\Entity\VoiceCommand;
use App\Repository\VoiceCommandRepository;
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
            'message' => 'Symfony desktop API connected successfully.',
        ]);
    }

    #[Route('/api/desktop/interpret', name: 'api_desktop_interpret', methods: ['POST'])]
    public function interpret(
        Request $request,
        VoiceCommandRepository $voiceCommandRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
                'speech' => 'Invalid request.',
                'intent' => 'INVALID_REQUEST',
                'action' => 'NONE',
                'commandId' => 0,
            ], 400);
        }

        $text = strtolower(trim((string) ($data['text'] ?? '')));
        $language = trim((string) ($data['language'] ?? 'en-US'));
        $client = trim((string) ($data['client'] ?? 'desktop'));

        if ($text === '') {
            return $this->json([
                'success' => false,
                'message' => 'Command text is required.',
                'speech' => 'Please provide a command.',
                'intent' => 'EMPTY_COMMAND',
                'action' => 'NONE',
                'commandId' => 0,
            ], 400);
        }

        /*
         * 1) Built-in desktop actions first.
         * This allows natural commands like:
         * open calculator
         * open my files
         * delete file test
         */
        $directAction = $this->detectDirectAction($text);

        if ($directAction !== null) {
            return $this->json([
                'success' => true,
                'message' => $this->messageForAction($directAction),
                'speech' => $this->messageForAction($directAction),
                'intent' => $this->intentForAction($directAction),
                'action' => $directAction,
                'commandId' => 0,
                'language' => $language,
                'client' => $client,
            ]);
        }

        /*
         * 2) Then match saved voice commands from database.
         */
        $commands = $voiceCommandRepository->findBy([
            'active' => true,
        ]);

        $matchedCommand = null;

        foreach ($commands as $command) {
            if (!$command instanceof VoiceCommand) {
                continue;
            }

            $keyword = strtolower(trim((string) $command->getKeyword()));

            if ($keyword !== '' && str_contains($text, $keyword)) {
                $matchedCommand = $command;
                break;
            }
        }

        if ($matchedCommand instanceof VoiceCommand) {
            $keyword = strtolower((string) $matchedCommand->getKeyword());
            $action = $this->detectAction($keyword);

            return $this->json([
                'success' => true,
                'message' => 'Command recognized: ' . $matchedCommand->getLabel(),
                'speech' => 'Command recognized: ' . $matchedCommand->getLabel(),
                'intent' => $this->intentForAction($action),
                'action' => $action,
                'commandId' => $matchedCommand->getId(),
                'command' => [
                    'id' => $matchedCommand->getId(),
                    'label' => $matchedCommand->getLabel(),
                    'keyword' => $matchedCommand->getKeyword(),
                    'description' => $matchedCommand->getDescription(),
                    'active' => $matchedCommand->isActive(),
                ],
                'language' => $language,
                'client' => $client,
            ]);
        }

        return $this->json([
            'success' => false,
            'message' => 'No matching voice command found.',
            'speech' => 'I could not understand this command.',
            'intent' => 'UNKNOWN',
            'action' => 'NONE',
            'commandId' => 0,
            'language' => $language,
            'client' => $client,
        ]);
    }

    private function detectDirectAction(string $text): ?string
    {
        if (str_contains($text, 'calculator')) {
            return 'OPEN_CALCULATOR';
        }

        if (str_contains($text, 'notepad')) {
            return 'OPEN_NOTEPAD';
        }

        if (str_contains($text, 'browser') || str_contains($text, 'chrome')) {
            return 'OPEN_BROWSER';
        }

        if (str_contains($text, 'logout') || str_contains($text, 'log out')) {
            return 'LOGOUT';
        }

        if (str_contains($text, 'profile')) {
            return 'OPEN_PROFILE';
        }

        if (
            str_contains($text, 'show my files') ||
            str_contains($text, 'list my files') ||
            str_contains($text, 'open my files') ||
            str_contains($text, 'my files') ||
            str_contains($text, 'show files') ||
            str_contains($text, 'list files')
        ) {
            return 'LIST_FILES';
        }

        if (
            str_contains($text, 'delete file ') ||
            str_contains($text, 'remove file ') ||
            str_starts_with($text, 'delete file ') ||
            str_starts_with($text, 'remove file ')
        ) {
            return 'DELETE_FILE';
        }

        if (
            str_contains($text, 'open file ') ||
            str_starts_with($text, 'open file ')
        ) {
            return 'OPEN_FILE';
        }

        return null;
    }

    private function detectAction(string $keyword): string
    {
        if (str_contains($keyword, 'calculator')) {
            return 'OPEN_CALCULATOR';
        }

        if (str_contains($keyword, 'notepad')) {
            return 'OPEN_NOTEPAD';
        }

        if (str_contains($keyword, 'browser') || str_contains($keyword, 'chrome')) {
            return 'OPEN_BROWSER';
        }

        if (str_contains($keyword, 'logout') || str_contains($keyword, 'log out')) {
            return 'LOGOUT';
        }

        if (str_contains($keyword, 'profile')) {
            return 'OPEN_PROFILE';
        }

        if (
            str_contains($keyword, 'show my files') ||
            str_contains($keyword, 'list my files') ||
            str_contains($keyword, 'open my files')
        ) {
            return 'LIST_FILES';
        }

        if (str_contains($keyword, 'delete file') || str_contains($keyword, 'remove file')) {
            return 'DELETE_FILE';
        }

        if (str_contains($keyword, 'open file')) {
            return 'OPEN_FILE';
        }

        if (str_contains($keyword, 'file') || str_contains($keyword, 'files')) {
            return 'FILE_ACTION';
        }

        return 'CUSTOM_COMMAND';
    }

    private function intentForAction(string $action): string
    {
        return match ($action) {
            'OPEN_FILE', 'DELETE_FILE', 'LIST_FILES' => 'FILE_ACTION',
            default => 'VOICE_COMMAND',
        };
    }

    private function messageForAction(string $action): string
    {
        return match ($action) {
            'OPEN_CALCULATOR' => 'Opening calculator.',
            'OPEN_NOTEPAD' => 'Opening notepad.',
            'OPEN_BROWSER' => 'Opening browser.',
            'OPEN_PROFILE' => 'Opening your profile.',
            'LOGOUT' => 'Logging out.',
            'OPEN_FILE' => 'Opening the matching managed file.',
            'DELETE_FILE' => 'Deleting the matching managed file.',
            'LIST_FILES' => 'Showing your managed files.',
            default => 'Command recognized.',
        };
    }
}