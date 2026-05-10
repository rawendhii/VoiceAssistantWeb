<?php

namespace App\Controller;

use App\Service\GeminiAssistantService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class GeminiAssistantController extends AbstractController
{
    #[Route('/api/gemini/assistant', name: 'api_gemini_assistant', methods: ['POST'])]
    public function assistant(Request $request, GeminiAssistantService $geminiAssistantService): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'intent' => 'INVALID_JSON',
                'action' => 'NONE',
                'message' => 'Invalid JSON body.',
                'speech' => 'Invalid JSON body.',
            ], 400);
        }

        $text = trim((string) ($data['text'] ?? ''));
        $language = trim((string) ($data['language'] ?? 'en-US'));
        $browserContext = is_array($data['browserContext'] ?? null) ? $data['browserContext'] : [];

        $result = $geminiAssistantService->understand($text, $language, $browserContext);

        return $this->json($this->prepareExecutionResponse($result));
    }

    private function prepareExecutionResponse(array $result): array
    {
        $intent = strtoupper((string) ($result['intent'] ?? 'CHAT'));

        if ($intent === 'NAVIGATE') {
            return $this->prepareNavigation($result);
        }

        if ($intent === 'BROWSER_ACTION') {
            return $this->prepareBrowserAction($result);
        }

        if ($intent === 'EMAIL_PREPARE') {
            return $this->prepareEmail($result);
        }

        return $result;
    }

    private function prepareNavigation(array $result): array
    {
        $target = strtoupper((string) ($result['navigation']['target'] ?? ''));

        $routes = [
            'HOME' => 'front_home',
            'PROFILE' => 'front_profile',
            'FILES' => 'front_files',
            'VOICE_COMMANDS' => 'front_voice_commands',
            'LOGOUT' => 'app_logout',
        ];

        if (!isset($routes[$target])) {
            return array_merge($result, [
                'success' => false,
                'message' => 'I understood the navigation request, but I do not know this page.',
                'speech' => 'I understood the navigation request, but I do not know this page.',
            ]);
        }

        return array_merge($result, [
            'success' => true,
            'execution' => [
                'type' => 'NAVIGATE',
                'redirect' => $this->generateUrl($routes[$target]),
            ],
            'message' => $result['message'] ?? 'Opening page.',
            'speech' => $result['speech'] ?? 'Opening page.',
        ]);
    }

    private function prepareBrowserAction(array $result): array
    {
        $browser = is_array($result['browser'] ?? null) ? $result['browser'] : [];

        $command = strtoupper((string) ($browser['command'] ?? ''));

        $extensionAction = match ($command) {
            'OPEN_WEBSITE' => 'OPEN_URL',
            'SEARCH_WEBSITE' => 'SEARCH',
            'GO_BACK' => 'GO_BACK',
            'GO_FORWARD' => 'GO_FORWARD',
            'SCROLL_DOWN' => 'SCROLL_DOWN',
            'SCROLL_UP' => 'SCROLL_UP',
            'READ_PAGE' => 'READ_PAGE',
            'CLICK_RESULT' => 'CLICK_RESULT',
            'PLAY' => 'PLAY',
            'PAUSE' => 'PAUSE',
            'MUTE' => 'MUTE',
            'UNMUTE' => 'UNMUTE',
            default => '',
        };

        if ($extensionAction === '') {
            return array_merge($result, [
                'success' => false,
                'message' => 'I understood that you want a browser action, but the action is not supported yet.',
                'speech' => 'I understood that you want a browser action, but the action is not supported yet.',
            ]);
        }

        return array_merge($result, [
            'success' => true,
            'requiresExtension' => true,
            'extensionCommand' => [
                'action' => $extensionAction,
                'website' => $browser['website'] ?? null,
                'websiteName' => $browser['websiteName'] ?? null,
                'url' => $browser['url'] ?? null,
                'query' => $browser['query'] ?? null,
                'target' => null,
                'resultPosition' => $browser['resultPosition'] ?? null,
                'nextAction' => null,
            ],
            'message' => $result['message'] ?? 'Executing browser action.',
            'speech' => $result['speech'] ?? 'Executing browser action.',
        ]);
    }

    private function prepareEmail(array $result): array
    {
        $email = is_array($result['email'] ?? null) ? $result['email'] : [];

        $to = trim((string) ($email['to'] ?? ''));
        $subject = trim((string) ($email['subject'] ?? 'Message from Voice Assistant'));
        $body = trim((string) ($email['body'] ?? ''));

        if ($to === '' || $body === '') {
            return array_merge($result, [
                'success' => false,
                'intent' => 'EMAIL_PREPARE',
                'action' => 'MISSING_EMAIL_DATA',
                'message' => 'I can prepare the email, but I need the recipient and the message body.',
                'speech' => 'I can prepare the email, but I need the recipient and the message body.',
                'requiresConfirmation' => false,
            ]);
        }

        return array_merge($result, [
            'success' => true,
            'intent' => 'EMAIL_PREPARE',
            'action' => 'EMAIL_PREPARED_NEEDS_CONFIRMATION',
            'requiresConfirmation' => true,
            'emailPreview' => [
                'to' => $to,
                'subject' => $subject !== '' ? $subject : 'Message from Voice Assistant',
                'body' => $body,
            ],
            'message' => 'I prepared the email. Please confirm before sending.',
            'speech' => 'I prepared the email. Please confirm before sending.',
        ]);
    }
}