<?php

namespace App\Controller;

use App\Service\AiCommandLogService;
use App\Service\LlmCommandInterpreterService;
use App\Service\WebsiteActionResolverService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ApiVoiceController extends AbstractController
{
    #[Route('/api/voice/interpret', name: 'api_voice_interpret', methods: ['POST'])]
    public function interpret(
        Request $request,
        WebsiteActionResolverService $websiteActionResolver,
        LlmCommandInterpreterService $llmCommandInterpreter,
        AiCommandLogService $aiCommandLogService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $spokenText = trim((string) ($data['text'] ?? ''));

        if ($spokenText === '') {
            return $this->json([
                'success' => false,
                'message' => 'The text field is required.',
            ], 400);
        }

        $user = $this->getUser();
        $userId = is_object($user) && method_exists($user, 'getId') ? $user->getId() : null;

        /*
         * Fast mode:
         * First we try the local database/rule resolver.
         * This keeps simple website commands very fast.
         */
        $fastResult = $websiteActionResolver->resolve($spokenText);

        if (($fastResult['success'] ?? false) === true) {
            $fastResult['mode'] = 'FAST_DB_RESOLVER';

            /*
             * Fast mode does not understand compound YouTube commands like:
             * "search cats and open second video".
             * That advanced behavior is handled by the LLM mode.
             */
            if (!array_key_exists('resultPosition', $fastResult)) {
                $fastResult['resultPosition'] = null;
            }

            $aiCommandLogService->log($spokenText, $fastResult, $userId);

            return $this->json($fastResult, 200);
        }

        /*
         * AI mode:
         * If the fast resolver fails, we ask the LLM to interpret flexible language.
         */
        $allowedActions = $websiteActionResolver->getAllowedActionsForLlm();

        if ($allowedActions === []) {
            $failedResult = [
                'success' => false,
                'message' => 'No allowed website actions are configured.',
                'mode' => 'NO_ACTIONS_CONFIGURED',
            ];

            $aiCommandLogService->log($spokenText, $failedResult, $userId);

            return $this->json($failedResult, 200);
        }

        $llmResponse = $llmCommandInterpreter->interpretWebsiteCommand($spokenText, $allowedActions);

        if (($llmResponse['success'] ?? false) !== true) {
            $failedResult = [
                'success' => false,
                'message' => $llmResponse['message'] ?? 'LLM interpretation failed.',
                'llmResult' => $llmResponse['result'] ?? $llmResponse['llmResult'] ?? null,
                'mode' => 'LLM_FAILED',
            ];

            $aiCommandLogService->log($spokenText, $failedResult, $userId);

            return $this->json($failedResult, 200);
        }

        $llmResult = $llmResponse['result'];

        /*
         * Validate the LLM result against configured website/actions.
         * The LLM cannot invent websites or actions.
         */
        $validatedResult = $websiteActionResolver->resolveFromLlmResult($llmResult);

        /*
         * Extra field for compound YouTube commands:
         * Example:
         * "open YouTube, search cats, and open the second video"
         * resultPosition = 2
         */
        $resultPosition = $llmResult['resultPosition'] ?? null;

        if ($resultPosition !== null && $resultPosition !== '') {
            $resultPosition = (int) $resultPosition;

            if ($resultPosition < 1 || $resultPosition > 10) {
                $resultPosition = null;
            }
        } else {
            $resultPosition = null;
        }

        $validatedResult['resultPosition'] = $resultPosition;
        $validatedResult['mode'] = 'LLM_STRUCTURED_OUTPUT';

        $aiCommandLogService->log($spokenText, $validatedResult, $userId);

        return $this->json($validatedResult, 200);
    }
}