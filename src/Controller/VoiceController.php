<?php

namespace App\Controller;

use App\Entity\DesktopAction;
use App\Entity\User;
use App\Repository\VoiceCommandRepository;
use App\Service\AiCommandInterpreterService;
use App\Service\CommandHistoryService;
use App\Service\DesktopActionService;
use App\Service\VoiceActionExecutorService;
use App\Service\VoiceCommandMatcherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class VoiceController extends AbstractController
{
    #[Route('/voice/execute', name: 'voice_execute', methods: ['POST'])]
    public function execute(
        Request $request,
        AiCommandInterpreterService $aiInterpreter,
        VoiceCommandMatcherService $matcherService,
        VoiceActionExecutorService $executorService,
        CommandHistoryService $historyService,
        VoiceCommandRepository $voiceCommandRepository,
        DesktopActionService $desktopActionService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $text = trim((string) ($data['text'] ?? ''));

        if ($text === '') {
            return $this->json([
                'success' => false,
                'message' => 'No voice text received.',
            ]);
        }

        $normalizedText = mb_strtolower(trim($text));
        $session = $request->getSession();
        /*
 * Pending create file name.
 * First command: please make a new document
 * Second command: user says the file name
 */
$pendingCreateFileName = $session->get('pending_create_file_name');

if ($pendingCreateFileName !== null) {
    /** @var User|null $user */
    $user = $this->getUser();

    if (!$user instanceof User) {
        return $this->json([
            'success' => false,
            'message' => 'You must be logged in to continue this action.',
        ]);
    }

    $isCancellation =
        str_contains($normalizedText, 'cancel') ||
        str_contains($normalizedText, 'stop') ||
        str_contains($normalizedText, 'never mind') ||
        str_contains($normalizedText, 'do not create') ||
        str_contains($normalizedText, 'dont create') ||
        str_contains($normalizedText, 'don’t create');

    if ($isCancellation) {
        $session->remove('pending_create_file_name');

        return $this->json([
            'success' => true,
            'message' => 'File creation cancelled.',
            'execution' => [
                'success' => true,
                'action' => 'cancel_create_file',
                'speech' => 'File creation cancelled.',
            ],
        ]);
    }

    $fileName = trim($text);

    // Remove common speech words if the user says "call it test" or "name it test"
    $fileName = preg_replace('/^(call it|name it|called|named|the name is)\s+/i', '', $fileName);
    $fileName = trim((string) $fileName);

    if ($fileName === '') {
        return $this->json([
            'success' => false,
            'message' => 'Please say the file name.',
            'execution' => [
                'success' => false,
                'action' => 'create_file_waiting_name',
                'speech' => 'Please say the file name.',
            ],
        ]);
    }

    $session->remove('pending_create_file_name');

    $session->set('pending_create_file', [
        'fileName' => $fileName,
    ]);

    return $this->json([
        'success' => true,
        'message' => 'File name received. Waiting for content.',
        'execution' => [
            'success' => true,
            'action' => 'create_file_waiting_content',
            'speech' => 'What would you like to write inside ' . $fileName . '? Please dictate the content now.',
        ],
    ]);
}

        /*
         * Pending create file content.
         * First command: create file called report
         * Second command: user dictates content
         */
        $pendingCreate = $session->get('pending_create_file');

        if ($pendingCreate !== null) {
            /** @var User|null $user */
            $user = $this->getUser();

            if (!$user instanceof User) {
                return $this->json([
                    'success' => false,
                    'message' => 'You must be logged in to continue this action.',
                ]);
            }

            $isCancellation =
                str_contains($normalizedText, 'cancel') ||
                str_contains($normalizedText, 'stop') ||
                str_contains($normalizedText, 'never mind') ||
                str_contains($normalizedText, 'do not create') ||
                str_contains($normalizedText, 'dont create') ||
                str_contains($normalizedText, 'don’t create');

            if ($isCancellation) {
                $session->remove('pending_create_file');

                return $this->json([
                    'success' => true,
                    'message' => 'File creation cancelled.',
                    'execution' => [
                        'success' => true,
                        'action' => 'cancel_create_file',
                        'speech' => 'File creation cancelled.',
                    ],
                ]);
            }

            $fileName = (string) ($pendingCreate['fileName'] ?? '');
            $content = trim($text);

            if ($content === '') {
                return $this->json([
                    'success' => false,
                    'message' => 'Please dictate the content you want to write in the file.',
                    'execution' => [
                        'success' => false,
                        'action' => 'create_file_waiting_content',
                        'speech' => 'Please dictate the content you want to write in the file.',
                    ],
                ]);
            }

            $session->remove('pending_create_file');

            $desktopAction = $desktopActionService->createFileAction($user, $fileName, $content);

            return $this->json([
                'success' => true,
                'message' => 'File content received. Please wait while I create the file.',
                'execution' => [
                    'success' => true,
                    'action' => 'desktop_file_action',
                    'desktopActionId' => $desktopAction->getId(),
                    'speech' => 'File content received. Please wait while I create the file.',
                ],
            ]);
        }

        /*
         * Pending delete confirmation.
         * First command: delete file report
         * Second command: yes / confirm / no / cancel
         */
        $pendingDelete = $session->get('pending_delete_file');

        if ($pendingDelete !== null) {
            /** @var User|null $user */
            $user = $this->getUser();

            if (!$user instanceof User) {
                return $this->json([
                    'success' => false,
                    'message' => 'You must be logged in to confirm this action.',
                ]);
            }

            $isCancellation =
                str_contains($normalizedText, 'no') ||
                str_contains($normalizedText, 'cancel') ||
                str_contains($normalizedText, 'stop') ||
                str_contains($normalizedText, 'do not delete') ||
                str_contains($normalizedText, 'dont delete') ||
                str_contains($normalizedText, 'don’t delete');

            if ($isCancellation) {
                $session->remove('pending_delete_file');

                return $this->json([
                    'success' => true,
                    'message' => 'File deletion cancelled.',
                    'execution' => [
                        'success' => true,
                        'action' => 'cancel_delete_file',
                        'speech' => 'File deletion cancelled.',
                    ],
                ]);
            }

            $isConfirmation =
                str_contains($normalizedText, 'yes') ||
                str_contains($normalizedText, 'confirm') ||
                str_contains($normalizedText, 'sure') ||
                str_contains($normalizedText, 'okay') ||
                str_contains($normalizedText, 'ok') ||
                str_contains($normalizedText, 'delete it') ||
                str_contains($normalizedText, 'go ahead');

            if ($isConfirmation) {
                $fileName = (string) ($pendingDelete['fileName'] ?? '');
                $session->remove('pending_delete_file');

                $desktopAction = $desktopActionService->deleteFileAction($user, $fileName);

                return $this->json([
                    'success' => true,
                    'message' => 'Deletion confirmed. Please wait while I complete it.',
                    'execution' => [
                        'success' => true,
                        'action' => 'desktop_file_action',
                        'desktopActionId' => $desktopAction->getId(),
                        'speech' => 'Deletion confirmed. Please wait while I complete it.',
                    ],
                ]);
            }

            return $this->json([
                'success' => false,
                'message' => 'Please confirm clearly. You can say yes, confirm, go ahead, no, or cancel.',
                'execution' => [
                    'success' => false,
                    'action' => 'delete_confirmation_waiting',
                    'speech' => 'Please confirm clearly. You can say yes, confirm, go ahead, no, or cancel.',
                ],
            ]);
        }

        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'You must be logged in to use voice commands.',
            ]);
        }

        $command = null;
        $source = 'rules';
        $confidence = null;
        $parameters = [];

        /*
         * 1. Try AI first.
         */
        $aiResult = $aiInterpreter->interpret($text);
$minimumAiConfidence = 0.9;

if (($aiResult['success'] ?? false) === true) {
    $keyword = $aiResult['keyword'] ?? null;
    $parameters = is_array($aiResult['parameters'] ?? null) ? $aiResult['parameters'] : [];
    $confidence = isset($aiResult['confidence']) ? (float) $aiResult['confidence'] : null;

    if (
        $keyword !== null &&
        trim((string) $keyword) !== '' &&
        $confidence !== null &&
        $confidence >= $minimumAiConfidence
    ) {
        $command = $voiceCommandRepository->findOneBy([
            'keyword' => trim((string) $keyword),
            'active' => true,
        ]);

        if ($command !== null) {
            $source = 'ai_chat';
        }
    }
}

        /*
         * 2. If AI fails, use fallback matcher.
         */
        if ($command === null) {
            $command = $matcherService->match($text);
            $source = 'rules_fallback';
        }

        if ($command === null) {
            $historyService->log($user, null, $text, 'FAILED');

            return $this->json([
                'success' => false,
                'message' => 'I did not understand your command. Please try again slowly.',
                'spokenText' => $text,
                'ai' => [
                    'source' => $source,
                    'confidence' => $confidence,
                    'result' => $aiResult,
                ],
            ]);
        }

        $keyword = (string) $command->getKeyword();

        /*
         * 3. Desktop file commands.
         */
        if (in_array($keyword, ['create file', 'delete file', 'rename file', 'read file'], true)) {
            $desktopResult = $this->handleDesktopFileAction(
                $keyword,
                $parameters,
                $user,
                $desktopActionService
            );
            if (($desktopResult['needsFileName'] ?? false) === true && $keyword === 'create file') {
    $request->getSession()->set('pending_create_file_name', true);

    $historyService->log($user, $command, $text, 'PENDING_FILE_NAME');

    return $this->json([
        'success' => true,
        'label' => $command->getLabel(),
        'keyword' => $command->getKeyword(),
        'description' => $command->getDescription(),
        'message' => $desktopResult['message'],
        'execution' => [
            'success' => true,
            'action' => 'create_file_waiting_name',
            'speech' => $desktopResult['speech'],
        ],
        'ai' => [
            'source' => $source,
            'confidence' => $confidence,
            'parameters' => $parameters,
            'result' => $aiResult,
        ],
    ]);
}

            if (($desktopResult['needsContent'] ?? false) === true && $keyword === 'create file') {
                $request->getSession()->set('pending_create_file', [
                    'fileName' => $desktopResult['fileName'],
                ]);

                $historyService->log($user, $command, $text, 'PENDING_CONTENT');

                return $this->json([
                    'success' => true,
                    'label' => $command->getLabel(),
                    'keyword' => $command->getKeyword(),
                    'description' => $command->getDescription(),
                    'message' => $desktopResult['message'],
                    'execution' => [
                        'success' => true,
                        'action' => 'create_file_waiting_content',
                        'speech' => $desktopResult['speech'],
                    ],
                    'ai' => [
                        'source' => $source,
                        'confidence' => $confidence,
                        'parameters' => $parameters,
                        'result' => $aiResult,
                    ],
                ]);
            }

            if (($desktopResult['needsConfirmation'] ?? false) === true && $keyword === 'delete file') {
                $request->getSession()->set('pending_delete_file', [
                    'fileName' => $desktopResult['fileName'],
                ]);

                $historyService->log($user, $command, $text, 'PENDING_CONFIRMATION');

                return $this->json([
                    'success' => true,
                    'label' => $command->getLabel(),
                    'keyword' => $command->getKeyword(),
                    'description' => $command->getDescription(),
                    'message' => $desktopResult['message'],
                    'execution' => [
                        'success' => true,
                        'action' => 'delete_file_confirmation',
                        'speech' => $desktopResult['speech'],
                    ],
                    'ai' => [
                        'source' => $source,
                        'confidence' => $confidence,
                        'parameters' => $parameters,
                        'result' => $aiResult,
                    ],
                ]);
            }

            $historyService->log($user, $command, $text, $desktopResult['success'] ? 'SUCCESS' : 'FAILED');

            return $this->json([
                'success' => $desktopResult['success'],
                'label' => $command->getLabel(),
                'keyword' => $command->getKeyword(),
                'description' => $command->getDescription(),
                'message' => $desktopResult['message'],
                'execution' => [
                    'success' => $desktopResult['success'],
                    'action' => 'desktop_file_action',
                    'desktopActionId' => $desktopResult['desktopActionId'] ?? null,
                    'speech' => $desktopResult['speech'],
                ],
                'ai' => [
                    'source' => $source,
                    'confidence' => $confidence,
                    'parameters' => $parameters,
                    'result' => $aiResult,
                ],
            ]);
        }

        /*
         * 4. Normal web commands.
         */
        $executionResult = $executorService->execute(
            $text,
            $keyword,
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
            'ai' => [
                'source' => $source,
                'confidence' => $confidence,
                'parameters' => $parameters,
                'result' => $aiResult,
            ],
        ]);
    }

    #[Route('/voice/desktop-action/{id}/status', name: 'voice_desktop_action_status', methods: ['GET'])]
    public function desktopActionStatus(int $id, EntityManagerInterface $em): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'You must be logged in.',
            ], 401);
        }

        $desktopAction = $em->getRepository(DesktopAction::class)->find($id);

        if (!$desktopAction instanceof DesktopAction) {
            return $this->json([
                'success' => false,
                'message' => 'Desktop action not found.',
            ], 404);
        }

        if ($desktopAction->getUser()?->getId() !== $user->getId()) {
            return $this->json([
                'success' => false,
                'message' => 'You cannot access this desktop action.',
            ], 403);
        }

        return $this->json([
            'success' => true,
            'id' => $desktopAction->getId(),
            'status' => $desktopAction->getStatus(),
            'resultMessage' => $desktopAction->getResultMessage(),
        ]);
    }

    private function handleDesktopFileAction(
        string $keyword,
        array $parameters,
        User $user,
        DesktopActionService $desktopActionService
    ): array {
        $fileName = isset($parameters['fileName']) ? trim((string) $parameters['fileName']) : '';
        $newFileName = isset($parameters['newFileName']) ? trim((string) $parameters['newFileName']) : '';
        $content = isset($parameters['content']) ? (string) $parameters['content'] : null;

      if ($keyword === 'create file' && $fileName === '') {
    return [
        'success' => true,
        'needsFileName' => true,
        'message' => 'Waiting for file name.',
        'speech' => 'Sure. What name should I give to the document?',
    ];
}

if (in_array($keyword, ['delete file', 'read file'], true) && $fileName === '') {
    return [
        'success' => false,
        'message' => 'Please specify the file name.',
        'speech' => 'Please specify the file name.',
    ];
}
        if ($keyword === 'rename file' && ($fileName === '' || $newFileName === '')) {
            return [
                'success' => false,
                'message' => 'Please specify the old file name and the new file name.',
                'speech' => 'Please specify the old file name and the new file name.',
            ];
        }

        return match ($keyword) {
            'create file' => (function () use ($desktopActionService, $user, $fileName, $content) {
                if ($content !== null && trim($content) !== '') {
                    $desktopAction = $desktopActionService->createFileAction($user, $fileName, $content);

                    return [
                        'success' => true,
                        'desktopActionId' => $desktopAction->getId(),
                        'message' => 'File creation started. Please wait.',
                        'speech' => 'File creation started. Please wait.',
                    ];
                }

                return [
                    'success' => true,
                    'needsContent' => true,
                    'message' => 'File name received. Waiting for content.',
                    'speech' => 'What would you like to write inside ' . $fileName . '? Please dictate the content now.',
                    'fileName' => $fileName,
                ];
            })(),

            'delete file' => [
                'success' => true,
                'needsConfirmation' => true,
                'message' => 'Please confirm file deletion.',
                'speech' => 'Are you sure you want to delete the file ' . $fileName . '? Say yes to confirm or no to cancel.',
                'fileName' => $fileName,
            ],

            'rename file' => (function () use ($desktopActionService, $user, $fileName, $newFileName) {
                $desktopAction = $desktopActionService->renameFileAction($user, $fileName, $newFileName);

                return [
                    'success' => true,
                    'desktopActionId' => $desktopAction->getId(),
                    'message' => 'File rename started. Please wait.',
                    'speech' => 'File rename started. Please wait.',
                ];
            })(),

            'read file' => (function () use ($desktopActionService, $user, $fileName) {
                $desktopAction = $desktopActionService->readFileAction($user, $fileName);

                return [
                    'success' => true,
                    'desktopActionId' => $desktopAction->getId(),
                    'message' => 'File reading started. Please wait.',
                    'speech' => 'File reading started. Please wait.',
                ];
            })(),

            default => [
                'success' => false,
                'message' => 'Unsupported desktop file action.',
                'speech' => 'This desktop file action is not supported.',
            ],
        };
    }
}