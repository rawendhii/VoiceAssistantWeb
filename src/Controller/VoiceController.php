<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CommandHistoryService;
use App\Service\InternalVoiceCommandInterpreterService;
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
        InternalVoiceCommandInterpreterService $internalVoiceCommandInterpreter,
        CommandHistoryService $historyService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON request.',
            ], 400);
        }

        $text = trim((string) ($data['text'] ?? ''));

        if ($text === '') {
            return $this->json([
                'success' => false,
                'message' => 'No voice text received.',
            ], 400);
        }

        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'You must be logged in to use voice commands.',
                'spokenText' => $text,
                'source' => 'not_authenticated',
            ], 401);
        }

        /*
         * Safety boundary:
         * The web assistant can show files stored in the web application,
         * but it must not create, rename, edit, delete, or manage files on the user's computer.
         * Those local computer actions belong to the desktop assistant module.
         */
        if ($this->isDesktopFileManagementRequest($text)) {
            $message = 'This web assistant can only show files stored in the web application. To create, rename, edit, delete, or manage files on your computer, please use the desktop assistant module.';

            return $this->json([
                'success' => false,
                'message' => $message,
                'speech' => $message,
                'spokenText' => $text,
                'source' => 'desktop_file_management_required',
            ]);
        }

        /*
         * Step 1:
         * Try the fast local matcher first.
         */
        $command = $matcherService->match($text);
        $source = 'rules_matcher';
        $llmResult = null;

        /*
         * Step 2:
         * If the rule matcher fails, use the LLM fallback.
         * The LLM only classifies the intent. It does not execute anything directly.
         */
        if (!$command) {
            $llmResult = $internalVoiceCommandInterpreter->interpret($text);

            if (($llmResult['success'] ?? false) === true) {
                $llmCommandKeyword = mb_strtolower(trim((string) ($llmResult['command'] ?? '')));

                /*
                 * Step 3:
                 * Validate the LLM result against active commands in the database.
                 */
                $command = $matcherService->findActiveCommandByKeyword($llmCommandKeyword);
                $source = 'llm_fallback';
            }
        }

        if (!$command) {
            return $this->json([
                'success' => false,
                'message' => 'I did not understand your command. Please try again slowly.',
                'spokenText' => $text,
                'source' => 'no_match',
                'llmResult' => $llmResult,
            ]);
        }

        $keyword = mb_strtolower(trim((string) $command->getKeyword()));

        /*
         * Step 4:
         * Execute only safe internal web actions implemented in VoiceActionExecutorService.
         */
        $executionResult = $executorService->execute(
            $text,
            $keyword,
            $user
        );

        if (($executionResult['success'] ?? false) !== true) {
            $historyService->log($user, $command, $text, 'FAILED');

            return $this->json([
                'success' => false,
                'label' => $command->getLabel(),
                'keyword' => $command->getKeyword(),
                'description' => $command->getDescription(),
                'message' => $executionResult['message'] ?? 'The command was recognized, but it could not be executed.',
                'speech' => $executionResult['speech'] ?? null,
                'spokenText' => $text,
                'source' => $source,
                'llmResult' => $llmResult,
                'execution' => $executionResult,
            ]);
        }

        $historyService->log($user, $command, $text, 'SUCCESS');

        return $this->json([
            'success' => true,
            'label' => $command->getLabel(),
            'keyword' => $command->getKeyword(),
            'description' => $command->getDescription(),
            'message' => $executionResult['message'] ?? 'Command executed successfully.',
            'speech' => $executionResult['speech'] ?? null,
            'spokenText' => $text,
            'source' => $source,
            'llmResult' => $llmResult,
            'execution' => $executionResult,
        ]);
    }

    private function normalizeVoiceText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function isDesktopFileManagementRequest(string $text): bool
    {
        $normalized = $this->normalizeVoiceText($text);

        if ($normalized === '') {
            return false;
        }

        /*
         * These are allowed because they only open the web file viewer.
         */
        $safeReadOnlyPhrases = [
            'show my files',
            'open my files',
            'go to my files',
            'view my files',
            'list my files',
            'show files',
            'open files',
            'my files',
            'show uploaded files',
            'open uploaded files',
            'view uploaded files',
            'show my documents',
            'open my documents',
            'view my documents',
            'list my documents',
            'mes fichiers',
            'afficher mes fichiers',
            'ouvrir mes fichiers',
            'voir mes fichiers',
            'mes documents',
            'afficher mes documents',
            'ouvrir mes documents',
            'voir mes documents',
        ];

        foreach ($safeReadOnlyPhrases as $phrase) {
            if ($normalized === $this->normalizeVoiceText($phrase)) {
                return false;
            }
        }

        $fileWords = [
            'file',
            'files',
            'document',
            'documents',
            'folder',
            'folders',
            'fichier',
            'fichiers',
            'dossier',
            'dossiers',
            'document',
            'documents',
        ];

        $managementWords = [
            'create',
            'make',
            'new',
            'add',
            'delete',
            'remove',
            'rename',
            'edit',
            'update',
            'modify',
            'write',
            'save',
            'change',
            'manage',
            'management',
            'organize',
            'move',
            'copy',
            'cut',
            'paste',
            'gestion',
            'gerer',
            'gérer',
            'cree',
            'créer',
            'creer',
            'ajouter',
            'supprimer',
            'effacer',
            'renommer',
            'modifier',
            'ecrire',
            'écrire',
            'sauvegarder',
            'changer',
            'organiser',
            'deplacer',
            'déplacer',
            'copier',
            'coller',
        ];

        $hasFileWord = false;

        foreach ($fileWords as $word) {
            if (str_contains($normalized, $this->normalizeVoiceText($word))) {
                $hasFileWord = true;
                break;
            }
        }

        if (!$hasFileWord) {
            return false;
        }

        foreach ($managementWords as $word) {
            if (str_contains($normalized, $this->normalizeVoiceText($word))) {
                return true;
            }
        }

        /*
         * Extra French expressions.
         */
        $frenchManagementPhrases = [
            'gestion de fichier',
            'gestion des fichiers',
            'gestion de mes fichiers',
            'gerer mes fichiers',
            'gérer mes fichiers',
            'gerer les fichiers',
            'gérer les fichiers',
            'gestion de dossier',
            'gestion des dossiers',
            'gerer mes dossiers',
            'gérer mes dossiers',
        ];

        foreach ($frenchManagementPhrases as $phrase) {
            if (str_contains($normalized, $this->normalizeVoiceText($phrase))) {
                return true;
            }
        }

        return false;
    }
}