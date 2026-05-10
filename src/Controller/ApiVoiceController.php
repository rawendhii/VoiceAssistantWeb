<?php

namespace App\Controller;

use App\Service\GeminiChatService;
use App\Service\GeminiCommandPlannerService;
use App\Service\VoiceEmailCommandService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ApiVoiceController extends AbstractController
{
    #[Route('/api/voice/interpret', name: 'api_voice_interpret', methods: ['POST', 'OPTIONS'])]
    public function interpret(
        Request $request,
        GeminiChatService $geminiChatService,
        GeminiCommandPlannerService $geminiCommandPlannerService,
        VoiceEmailCommandService $voiceEmailCommandService
    ): JsonResponse {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->json([
                'success' => true,
                'message' => 'CORS preflight accepted.',
            ]);
        }

        try {
            $data = json_decode($request->getContent(), true);

            if (!is_array($data)) {
                return $this->json([
                    'success' => false,
                    'intent' => 'UNKNOWN',
                    'mode' => 'INVALID_JSON',
                    'requiresExtension' => false,
                    'message' => 'Invalid JSON body.',
                    'speech' => 'Invalid JSON body.',
                ], 400);
            }

            $spokenText = trim((string) ($data['text'] ?? ''));
            $language = trim((string) ($data['language'] ?? 'en-US'));
            $browserContext = $data['browserContext'] ?? null;
            $browserContext = is_array($browserContext) ? $browserContext : null;
            $baseUrl = $request->getSchemeAndHttpHost();

            if ($spokenText === '') {
                return $this->json([
                    'success' => false,
                    'intent' => 'UNKNOWN',
                    'mode' => 'EMPTY_TEXT',
                    'requiresExtension' => false,
                    'message' => 'The text field is required.',
                    'speech' => 'The text field is required.',
                ], 400);
            }

            /*
             * 1. Email first.
             * This must catch pending email confirmations before Gemini chat.
             */
            $emailResult = $voiceEmailCommandService->handle($spokenText);

            if ($emailResult !== null) {
                return $this->json($emailResult);
            }

            /*
             * 2. Local reliable commands.
             * These work even if Gemini is busy, rate-limited, or unavailable.
             */
            $localResult = $this->tryLocalCommand(
                $spokenText,
                $language,
                $browserContext,
                $baseUrl
            );

            if ($localResult !== null) {
                return $this->json($localResult);
            }

            /*
             * 3. Gemini command planner.
             * Gemini understands natural language, but Symfony validates before execution.
             */
            $planResult = $geminiCommandPlannerService->planCommand(
                $spokenText,
                $language,
                $browserContext
            );

            if (($planResult['success'] ?? false) === true && is_array($planResult['result'] ?? null)) {
                $plannedResponse = $this->handleGeminiPlan(
                    $planResult['result'],
                    $language,
                    $browserContext,
                    $baseUrl
                );

                if ($plannedResponse !== null) {
                    return $this->json($plannedResponse);
                }
            }

            /*
             * 4. Gemini chatbot fallback.
             * Normal questions only.
             */
            $chatResult = $geminiChatService->chat(
                $this->buildSafeChatPrompt($spokenText),
                $language
            );

            $reply =
                $this->nullableString($chatResult['speech'] ?? null) ??
                $this->nullableString($chatResult['message'] ?? null) ??
                $this->nullableString($chatResult['reply'] ?? null) ??
                'I understood you, but I do not have a clear answer.';

            return $this->json([
                'success' => (bool) ($chatResult['success'] ?? true),
                'intent' => 'CHAT',
                'mode' => 'GEMINI_CHAT',
                'requiresExtension' => false,
                'message' => $reply,
                'speech' => $reply,
                'plannerMessage' => $planResult['message'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            return $this->json([
                'success' => false,
                'intent' => 'UNKNOWN',
                'mode' => 'SERVER_EXCEPTION',
                'requiresExtension' => false,
                'message' => 'Voice interpreter server error: ' . $exception->getMessage(),
                'speech' => 'A server error happened while interpreting the voice command.',
            ], 500);
        }
    }

    private function tryLocalCommand(
        string $spokenText,
        string $language,
        ?array $browserContext,
        string $baseUrl
    ): ?array {
        $normalized = $this->normalizeText($spokenText);

        if ($this->isDeleteAccountCommand($normalized)) {
            return $this->buildDeleteAccountResponse($baseUrl);
        }

        if ($this->isDisableAssistantCommand($normalized)) {
            return [
                'success' => true,
                'intent' => 'APP_MESSAGE',
                'mode' => 'LOCAL_DISABLE_ASSISTANT_NOT_CONNECTED',
                'requiresExtension' => false,
                'message' => 'There is no disable assistant setting connected yet. You can stop listening by pressing Stop, or say log out to leave your account.',
                'speech' => 'There is no disable assistant setting connected yet. You can stop listening by pressing Stop, or say log out to leave your account.',
            ];
        }

        if ($this->isConnectGmailCommand($normalized)) {
            return $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(['google_oauth_connect'], '/google/oauth/connect', $baseUrl),
                'Sure, I will open Gmail connection now.',
                'LOCAL_CONNECT_GMAIL'
            );
        }

        if ($this->isLogoutCommand($normalized)) {
            return $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(['app_logout', 'security_logout', 'logout'], '/logout', $baseUrl),
                'Sure, I will log you out now.',
                'LOCAL_LOGOUT'
            );
        }

        if ($this->isHomeCommand($normalized)) {
            return $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(['front_home', 'app_home', 'home'], '/front/home', $baseUrl),
                'Sure, I will open home now.',
                'LOCAL_OPEN_HOME'
            );
        }

        if ($this->isProfileCommand($normalized)) {
            return $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(['front_profile', 'app_profile', 'profile'], '/front/profile', $baseUrl),
                'Sure, I will open your profile now.',
                'LOCAL_OPEN_PROFILE'
            );
        }

        if ($this->isFilesCommand($normalized)) {
            return $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(['front_files', 'app_files', 'files'], '/front/files', $baseUrl),
                'Sure, I will open your files now.',
                'LOCAL_OPEN_FILES'
            );
        }

        if ($this->isVoiceCommandsCommand($normalized)) {
            return $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(['front_voice_commands', 'voice_commands', 'app_voice_commands'], '/front/voice-commands', $baseUrl),
                'Sure, I will open the voice commands page now.',
                'LOCAL_OPEN_VOICE_COMMANDS'
            );
        }

        if ($this->isChangePasswordCommand($normalized)) {
            return $this->buildChangePasswordResponse($baseUrl);
        }

        if ($this->isSettingsCommand($normalized)) {
            return $this->buildSettingsResponse($baseUrl);
        }

        $websiteAliases = [
            'youtube' => ['youtube', 'you tube', 'ytb', 'y t b', 'yt'],
            'google' => ['google', 'gogle'],
            'facebook' => ['facebook', 'fb'],
            'gmail' => ['gmail', 'google mail'],
        ];

        foreach ($websiteAliases as $website => $aliases) {
            foreach ($aliases as $alias) {
                $searchQuery = $this->extractWebsiteSearchQuery($normalized, $alias);

                if ($searchQuery !== null) {
                    return $this->buildWebsiteAction(
                        'SEARCH',
                        $website,
                        null,
                        $searchQuery,
                        $this->buildWebsiteSearchSpeech($website, $searchQuery),
                        $language,
                        $browserContext,
                        'LOCAL_WEBSITE_SEARCH'
                    );
                }

                if ($this->isWebsiteOpenCommand($normalized, $alias)) {
                    return $this->buildWebsiteAction(
                        'OPEN_URL',
                        $website,
                        null,
                        null,
                        $this->buildWebsiteOpenSpeech($website),
                        $language,
                        $browserContext,
                        'LOCAL_WEBSITE_OPEN'
                    );
                }
            }
        }

        $implicitWebsiteSearch = $this->extractImplicitWebsiteSearch($normalized);

        if ($implicitWebsiteSearch !== null) {
            return $this->buildWebsiteAction(
                'SEARCH',
                $implicitWebsiteSearch['website'],
                null,
                $implicitWebsiteSearch['query'],
                $this->buildWebsiteSearchSpeech($implicitWebsiteSearch['website'], $implicitWebsiteSearch['query']),
                $language,
                $browserContext,
                'LOCAL_IMPLICIT_WEBSITE_SEARCH'
            );
        }

        $currentWebsite = $this->detectCurrentWebsiteFromContext($browserContext);
        $currentWebsiteSearchQuery = $this->extractCurrentWebsiteSearchQuery($normalized);

        if ($currentWebsite !== null && $currentWebsiteSearchQuery !== null) {
            return $this->buildWebsiteAction(
                'SEARCH',
                $currentWebsite,
                null,
                $currentWebsiteSearchQuery,
                $this->buildWebsiteSearchSpeech($currentWebsite, $currentWebsiteSearchQuery),
                $language,
                $browserContext,
                'LOCAL_CURRENT_WEBSITE_SEARCH'
            );
        }

        $videoSelection = $this->matchVideoSelectionCommand($normalized);

        if ($videoSelection !== null) {
            return $this->buildBrowserAction(
                'SELECT_RESULT',
                null,
                null,
                $videoSelection,
                'Sure, I will open video number ' . $videoSelection . ' now.',
                $language,
                $browserContext,
                'LOCAL_SELECT_VIDEO'
            );
        }

        $videoTitle = $this->matchVideoTitleCommand($spokenText, $browserContext);

        if ($videoTitle !== null) {
            return $this->buildBrowserAction(
                'CLICK',
                null,
                $videoTitle,
                null,
                'Sure, I will open the matching video now.',
                $language,
                $browserContext,
                'LOCAL_CLICK_VIDEO_TITLE'
            );
        }

        $browserAction = $this->matchBrowserAction($normalized);

        if ($browserAction !== null) {
            return $this->buildBrowserAction(
                $browserAction,
                null,
                null,
                null,
                $this->speechForBrowserAction($browserAction),
                $language,
                $browserContext,
                'LOCAL_BROWSER_ACTION'
            );
        }

        if (preg_match('/^(click|press|select)\s+(.+)$/i', $spokenText, $matches)) {
            $target = trim($matches[2]);

            if ($target !== '') {
                return $this->buildBrowserAction(
                    'CLICK',
                    null,
                    $target,
                    null,
                    'Sure, I will click ' . $target . ' now.',
                    $language,
                    $browserContext,
                    'LOCAL_CLICK'
                );
            }
        }

        if (preg_match('/^(type|write|enter)\s+(.+)$/i', $spokenText, $matches)) {
            $query = trim($matches[2]);

            if ($query !== '') {
                return $this->buildBrowserAction(
                    'TYPE',
                    $query,
                    null,
                    null,
                    'Sure, I will type that now.',
                    $language,
                    $browserContext,
                    'LOCAL_TYPE'
                );
            }
        }

        return null;
    }

    private function handleGeminiPlan(
        array $plan,
        string $language,
        ?array $browserContext,
        string $baseUrl
    ): ?array {
        $intent = strtoupper((string) ($plan['intent'] ?? 'UNKNOWN'));
        $action = strtoupper((string) ($plan['action'] ?? ''));
        $speech = $this->cleanSpeech($plan['speech'] ?? null);

        if ($intent === 'UNKNOWN' || $intent === 'CHAT') {
            return null;
        }

        if ($intent === 'EMAIL_ACTION') {
            return [
                'success' => true,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_GUIDANCE',
                'requiresExtension' => false,
                'message' => 'I can help with email. Please say: send an email to someone at gmail dot com saying your message.',
                'speech' => 'I can help with email. Please say: send an email to someone at gmail dot com saying your message.',
            ];
        }

        if ($intent === 'APP_NAVIGATION') {
            return $this->handlePlannedAppNavigation($action, $speech, $baseUrl);
        }

        if ($intent === 'WEBSITE_ACTION') {
            return $this->handlePlannedWebsiteAction($plan, $speech, $language, $browserContext);
        }

        if ($intent === 'BROWSER_ACTION') {
            return $this->handlePlannedBrowserAction($plan, $speech, $language, $browserContext);
        }

        return null;
    }

    private function handlePlannedAppNavigation(string $action, string $speech, string $baseUrl): ?array
    {
        return match ($action) {
            'OPEN_HOME' => $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(['front_home', 'app_home', 'home'], '/front/home', $baseUrl),
                $speech ?: 'Sure, I will open home now.',
                'GEMINI_PLAN_OPEN_HOME'
            ),

            'OPEN_PROFILE' => $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(['front_profile', 'app_profile', 'profile'], '/front/profile', $baseUrl),
                $speech ?: 'Sure, I will open your profile now.',
                'GEMINI_PLAN_OPEN_PROFILE'
            ),

            'OPEN_FILES' => $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(['front_files', 'app_files', 'files'], '/front/files', $baseUrl),
                $speech ?: 'Sure, I will open your files now.',
                'GEMINI_PLAN_OPEN_FILES'
            ),

            'OPEN_VOICE_COMMANDS' => $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(['front_voice_commands', 'voice_commands', 'app_voice_commands'], '/front/voice-commands', $baseUrl),
                $speech ?: 'Sure, I will open the voice commands page now.',
                'GEMINI_PLAN_OPEN_VOICE_COMMANDS'
            ),

            'CONNECT_GMAIL' => $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(['google_oauth_connect'], '/google/oauth/connect', $baseUrl),
                $speech ?: 'Sure, I will open Gmail connection now.',
                'GEMINI_PLAN_CONNECT_GMAIL'
            ),

            'LOGOUT' => $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(['app_logout', 'security_logout', 'logout'], '/logout', $baseUrl),
                $speech ?: 'Sure, I will log you out now.',
                'GEMINI_PLAN_LOGOUT'
            ),

            'CHANGE_PASSWORD' => $this->buildChangePasswordResponse($baseUrl, $speech),

            'OPEN_SETTINGS' => $this->buildSettingsResponse($baseUrl, $speech),

            default => null,
        };
    }

    private function handlePlannedWebsiteAction(
        array $plan,
        string $speech,
        string $language,
        ?array $browserContext
    ): ?array {
        $action = $this->normalizeWebsiteAction((string) ($plan['action'] ?? ''));
        $website = $this->nullableString($plan['website'] ?? null);
        $url = $this->nullableString($plan['url'] ?? null);
        $query = $this->nullableString($plan['query'] ?? null);

        if ($website !== null) {
            $website = strtolower($website);
        }

        if ($website === null && $action === 'SEARCH') {
            $website = $this->detectCurrentWebsiteFromContext($browserContext);
        }

        if (!in_array($action, ['OPEN_URL', 'SEARCH'], true)) {
            return null;
        }

        return $this->buildWebsiteAction(
            $action,
            $website,
            $url,
            $query,
            $speech ?: ($action === 'SEARCH'
                ? $this->buildWebsiteSearchSpeech((string) $website, (string) $query)
                : $this->buildWebsiteOpenSpeech((string) $website)),
            $language,
            $browserContext,
            'GEMINI_PLAN_WEBSITE_ACTION'
        );
    }

    private function handlePlannedBrowserAction(
        array $plan,
        string $speech,
        string $language,
        ?array $browserContext
    ): ?array {
        $action = $this->normalizeBrowserAction((string) ($plan['action'] ?? ''));
        $query = $this->nullableString($plan['query'] ?? null);
        $target = $this->nullableString($plan['target'] ?? null);
        $resultPosition = $this->cleanNullableInt($plan['resultPosition'] ?? null);

        $allowedActions = [
            'SELECT_RESULT',
            'CLICK',
            'TYPE',
            'SCROLL_DOWN',
            'SCROLL_UP',
            'GO_BACK',
            'GO_FORWARD',
            'PLAY_VIDEO',
            'PAUSE_VIDEO',
            'MUTE',
            'UNMUTE',
            'VOLUME_UP',
            'VOLUME_DOWN',
            'READ_PAGE',
            'SUMMARIZE_PAGE',
            'RETURN_TO_ASSISTANT',
        ];

        if (!in_array($action, $allowedActions, true)) {
            return null;
        }

        if ($action === 'SELECT_RESULT' && $resultPosition === null) {
            return [
                'success' => false,
                'intent' => 'BROWSER_ACTION',
                'mode' => 'MISSING_RESULT_POSITION',
                'requiresExtension' => false,
                'message' => 'Please tell me which result number to open.',
                'speech' => 'Please tell me which result number to open.',
            ];
        }

        if ($action === 'CLICK' && $target === null && $query !== null) {
            $target = $query;
            $query = null;
        }

        if ($action === 'CLICK' && $target === null) {
            return [
                'success' => false,
                'intent' => 'BROWSER_ACTION',
                'mode' => 'MISSING_CLICK_TARGET',
                'requiresExtension' => false,
                'message' => 'Please tell me what to click.',
                'speech' => 'Please tell me what to click.',
            ];
        }

        if ($action === 'TYPE' && $query === null && $target !== null) {
            $query = $target;
            $target = null;
        }

        if ($action === 'TYPE' && $query === null) {
            return [
                'success' => false,
                'intent' => 'BROWSER_ACTION',
                'mode' => 'MISSING_TYPE_TEXT',
                'requiresExtension' => false,
                'message' => 'Please tell me what to type.',
                'speech' => 'Please tell me what to type.',
            ];
        }

        return $this->buildBrowserAction(
            $action,
            $query,
            $target,
            $resultPosition,
            $speech ?: $this->speechForBrowserAction($action),
            $language,
            $browserContext,
            'GEMINI_PLAN_BROWSER_ACTION'
        );
    }

    private function buildWebsiteAction(
        string $action,
        ?string $website,
        ?string $url,
        ?string $query,
        string $speech,
        string $language,
        ?array $browserContext,
        string $mode
    ): array {
        $action = $this->normalizeWebsiteAction($action);

        if ($website !== null) {
            $website = strtolower($website);
        }

        $allowedWebsites = ['youtube', 'google', 'facebook', 'gmail'];

        if ($website !== null && !in_array($website, $allowedWebsites, true)) {
            return [
                'success' => false,
                'intent' => 'WEBSITE_ACTION',
                'mode' => 'WEBSITE_NOT_ALLOWED',
                'requiresExtension' => false,
                'message' => 'That website is not supported yet.',
                'speech' => 'That website is not supported yet.',
            ];
        }

        if ($action === 'SEARCH' && ($query === null || trim($query) === '')) {
            return [
                'success' => false,
                'intent' => 'WEBSITE_ACTION',
                'mode' => 'MISSING_SEARCH_QUERY',
                'requiresExtension' => false,
                'message' => 'Please tell me what you want to search for.',
                'speech' => 'Please tell me what you want to search for.',
            ];
        }

        if ($action === 'OPEN_URL' && $website === null && $url === null) {
            return [
                'success' => false,
                'intent' => 'WEBSITE_ACTION',
                'mode' => 'MISSING_WEBSITE',
                'requiresExtension' => false,
                'message' => 'Please tell me which website to open.',
                'speech' => 'Please tell me which website to open.',
            ];
        }

        if ($action === 'SEARCH' && $website === null) {
            return [
                'success' => false,
                'intent' => 'WEBSITE_ACTION',
                'mode' => 'MISSING_SEARCH_WEBSITE',
                'requiresExtension' => false,
                'message' => 'Please tell me where you want to search.',
                'speech' => 'Please tell me where you want to search.',
            ];
        }

        return [
            'success' => true,
            'intent' => 'WEBSITE_ACTION',
            'mode' => $mode,
            'requiresExtension' => true,
            'website' => $website,
            'action' => $action,
            'query' => $query,
            'url' => $url,
            'message' => $speech,
            'speech' => $speech,
            'extensionCommand' => [
                'action' => $action,
                'website' => $website,
                'url' => $url,
                'query' => $query,
                'language' => $language,
                'context' => $browserContext,
            ],
        ];
    }

    private function buildBrowserAction(
        string $action,
        ?string $query,
        ?string $target,
        ?int $resultPosition,
        string $speech,
        string $language,
        ?array $browserContext,
        string $mode
    ): array {
        $action = $this->normalizeBrowserAction($action);

        return [
            'success' => true,
            'intent' => 'BROWSER_ACTION',
            'mode' => $mode,
            'requiresExtension' => true,
            'action' => $action,
            'query' => $query,
            'target' => $target,
            'resultPosition' => $resultPosition,
            'message' => $speech,
            'speech' => $speech,
            'extensionCommand' => [
                'action' => $action,
                'query' => $query,
                'target' => $target,
                'resultPosition' => $resultPosition,
                'language' => $language,
                'context' => $browserContext,
            ],
        ];
    }

    private function buildAppNavigationAction(string $url, string $speech, string $mode): array
    {
        return [
            'success' => true,
            'intent' => 'APP_NAVIGATION',
            'mode' => $mode,
            'requiresExtension' => false,
            'action' => 'NAVIGATE',
            'url' => $url,
            'message' => $speech,
            'speech' => $speech,
            'frontendAction' => [
                'type' => 'NAVIGATE',
                'url' => $url,
            ],
        ];
    }

    private function buildDeleteAccountResponse(string $baseUrl): array
    {
        $deleteUrl = $this->tryGenerateFirstAvailableLocalAppUrl([
            'front_delete_account',
            'front_account_delete',
            'app_delete_account',
            'app_account_delete',
            'account_delete',
            'delete_account',
            'user_delete_account',
            'profile_delete_account',
        ], $baseUrl);

        if ($deleteUrl !== null) {
            return $this->buildAppNavigationAction(
                $deleteUrl,
                'I found the account deletion page. I will open it now so you can review it safely.',
                'LOCAL_DELETE_ACCOUNT_PAGE'
            );
        }

        return [
            'success' => false,
            'intent' => 'APP_NAVIGATION',
            'mode' => 'DELETE_ACCOUNT_NOT_CONNECTED',
            'requiresExtension' => false,
            'message' => 'Account deletion is not connected in this app yet. I will not invent a page for it.',
            'speech' => 'Account deletion is not connected in this app yet. I will not invent a page for it.',
        ];
    }

    private function buildChangePasswordResponse(string $baseUrl, string $speech = ''): array
    {
        $changePasswordUrl = $this->tryGenerateFirstAvailableLocalAppUrl([
            'front_change_password',
            'front_profile_change_password',
            'app_change_password',
            'change_password',
            'profile_change_password',
            'user_change_password',
            'app_forgot_password_request',
        ], $baseUrl);

        if ($changePasswordUrl !== null) {
            return $this->buildAppNavigationAction(
                $changePasswordUrl,
                $speech ?: 'Sure, I will open the password section now.',
                'LOCAL_CHANGE_PASSWORD'
            );
        }

        return $this->buildAppNavigationAction(
            $this->buildFirstAvailableLocalAppUrl(['front_profile', 'app_profile', 'profile'], '/front/profile', $baseUrl),
            'There is no separate password page connected yet. I will open your profile.',
            'CHANGE_PASSWORD_FALLBACK_PROFILE'
        );
    }

    private function buildSettingsResponse(string $baseUrl, string $speech = ''): array
    {
        $settingsUrl = $this->tryGenerateFirstAvailableLocalAppUrl([
            'front_settings',
            'app_settings',
            'user_settings',
            'account_settings',
            'front_profile',
        ], $baseUrl);

        if ($settingsUrl !== null) {
            return $this->buildAppNavigationAction(
                $settingsUrl,
                $speech ?: 'Sure, I will open your account page now.',
                'LOCAL_OPEN_SETTINGS'
            );
        }

        return $this->buildAppNavigationAction(
            $baseUrl . '/front/profile',
            'There is no settings page connected yet. I will open your profile.',
            'SETTINGS_FALLBACK_PROFILE'
        );
    }

    private function isDeleteAccountCommand(string $normalized): bool
    {
        if (!str_contains($normalized, 'account') && !str_contains($normalized, 'profile')) {
            return false;
        }

        return (
            str_contains($normalized, 'delete my account') ||
            str_contains($normalized, 'delete account') ||
            str_contains($normalized, 'remove my account') ||
            str_contains($normalized, 'remove account') ||
            str_contains($normalized, 'close my account') ||
            str_contains($normalized, 'close account') ||
            str_contains($normalized, 'deactivate my account') ||
            str_contains($normalized, 'deactivate account')
        );
    }

    private function isDisableAssistantCommand(string $normalized): bool
    {
        return (
            str_contains($normalized, 'get rid of you') ||
            str_contains($normalized, 'remove voice assistant') ||
            str_contains($normalized, 'disable voice assistant') ||
            str_contains($normalized, 'turn off voice assistant') ||
            str_contains($normalized, 'hide voice assistant') ||
            str_contains($normalized, 'stop voice assistant') ||
            str_contains($normalized, 'turn you off') ||
            str_contains($normalized, 'i dont want you') ||
            str_contains($normalized, 'i don t want you')
        );
    }

    private function isConnectGmailCommand(string $normalized): bool
    {
        return (
            str_contains($normalized, 'connect gmail') ||
            str_contains($normalized, 'connect my gmail') ||
            str_contains($normalized, 'gmail connection') ||
            str_contains($normalized, 'connect email') ||
            str_contains($normalized, 'connect my email') ||
            str_contains($normalized, 'authorize gmail') ||
            str_contains($normalized, 'link gmail') ||
            str_contains($normalized, 'link my gmail')
        );
    }

    private function isLogoutCommand(string $normalized): bool
    {
        return (
            $normalized === 'logout' ||
            $normalized === 'log out' ||
            $normalized === 'sign out' ||
            str_contains($normalized, 'log me out') ||
            str_contains($normalized, 'sign me out') ||
            str_contains($normalized, 'disconnect my account') ||
            str_contains($normalized, 'close my session')
        );
    }

    private function isHomeCommand(string $normalized): bool
    {
        return (
            $normalized === 'home' ||
            $normalized === 'dashboard' ||
            str_contains($normalized, 'go home') ||
            str_contains($normalized, 'go back home') ||
            str_contains($normalized, 'return home') ||
            str_contains($normalized, 'take me home') ||
            str_contains($normalized, 'open home') ||
            str_contains($normalized, 'go to dashboard') ||
            str_contains($normalized, 'open dashboard')
        );
    }

    private function isProfileCommand(string $normalized): bool
    {
        if (!preg_match('/\bprofile\b/u', $normalized)) {
            return false;
        }

        return (
            $normalized === 'profile' ||
            str_contains($normalized, 'my profile') ||
            str_contains($normalized, 'open profile') ||
            str_contains($normalized, 'open my profile') ||
            str_contains($normalized, 'show profile') ||
            str_contains($normalized, 'show my profile') ||
            str_contains($normalized, 'show me my profile') ||
            str_contains($normalized, 'go to profile') ||
            str_contains($normalized, 'take me to profile') ||
            str_contains($normalized, 'i want to see my profile') ||
            str_contains($normalized, 'edit profile') ||
            str_contains($normalized, 'update profile')
        );
    }

    private function isFilesCommand(string $normalized): bool
    {
        if (!preg_match('/\bfile\b/u', $normalized) && !preg_match('/\bfiles\b/u', $normalized)) {
            return false;
        }

        return (
            $normalized === 'files' ||
            $normalized === 'my files' ||
            str_contains($normalized, 'open files') ||
            str_contains($normalized, 'open my files') ||
            str_contains($normalized, 'show files') ||
            str_contains($normalized, 'show my files') ||
            str_contains($normalized, 'go to files') ||
            str_contains($normalized, 'take me to files') ||
            str_contains($normalized, 'i want to see my files')
        );
    }

    private function isVoiceCommandsCommand(string $normalized): bool
    {
        return (
            str_contains($normalized, 'voice commands') ||
            str_contains($normalized, 'voice command') ||
            str_contains($normalized, 'show commands') ||
            str_contains($normalized, 'available commands') ||
            str_contains($normalized, 'what can i say') ||
            str_contains($normalized, 'what can i do')
        );
    }

    private function isChangePasswordCommand(string $normalized): bool
    {
        if (!preg_match('/\bpassword\b/u', $normalized)) {
            return false;
        }

        return (
            str_contains($normalized, 'change password') ||
            str_contains($normalized, 'change my password') ||
            str_contains($normalized, 'update password') ||
            str_contains($normalized, 'reset password') ||
            str_contains($normalized, 'password settings') ||
            str_contains($normalized, 'password section')
        );
    }

    private function isSettingsCommand(string $normalized): bool
    {
        return (
            $normalized === 'settings' ||
            $normalized === 'open settings' ||
            $normalized === 'go to settings' ||
            $normalized === 'account settings' ||
            str_contains($normalized, 'open my settings') ||
            str_contains($normalized, 'open security settings')
        );
    }

    private function isWebsiteOpenCommand(string $normalized, string $alias): bool
    {
        return (
            $normalized === $alias ||
            $normalized === 'open ' . $alias ||
            $normalized === 'go to ' . $alias ||
            $normalized === 'launch ' . $alias ||
            $normalized === 'show me ' . $alias ||
            $normalized === 'take me to ' . $alias ||
            $normalized === 'i want to open ' . $alias ||
            $normalized === 'can you open ' . $alias ||
            $normalized === 'please open ' . $alias
        );
    }

    private function extractWebsiteSearchQuery(string $normalized, string $alias): ?string
    {
        $aliasPattern = preg_quote($alias, '/');

        $patterns = [
            '/^(?:search|find|look up|play)\s+' . $aliasPattern . '\s+(?:for\s+|gfor\s+|about\s+)?(.+)$/u',
            '/^(?:search|find|look up|play)\s+on\s+' . $aliasPattern . '\s+(?:for\s+|gfor\s+|about\s+)?(.+)$/u',
            '/^(?:open|go to|launch)\s+' . $aliasPattern . '\s+(?:then|and)\s+(?:search|find|look up|play)\s+(?:for\s+|gfor\s+|about\s+)?(.+)$/u',
            '/^(?:open|go to|launch)\s+' . $aliasPattern . '\s+(?:then|and)\s+(?:search|find|look up|play)\s+(.+)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                return $this->cleanSearchQuery($matches[1] ?? null);
            }
        }

        return null;
    }

    private function extractImplicitWebsiteSearch(string $normalized): ?array
    {
        $websiteAliases = [
            'youtube' => ['youtube', 'you tube', 'ytb', 'y t b', 'yt'],
            'google' => ['google', 'gogle'],
            'facebook' => ['facebook', 'fb'],
        ];

        foreach ($websiteAliases as $website => $aliases) {
            foreach ($aliases as $alias) {
                $aliasPattern = preg_quote($alias, '/');

                $patterns = [
                    '/^(?:play|search|find|look up)\s+(.+?)\s+on\s+' . $aliasPattern . '$/u',
                    '/^(?:play|search|find|look up)\s+on\s+' . $aliasPattern . '\s+(.+)$/u',
                    '/^(.+?)\s+on\s+' . $aliasPattern . '$/u',
                    '/^(.+?)\s+in\s+' . $aliasPattern . '$/u',
                    '/^(.+?)\s+' . $aliasPattern . '$/u',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $normalized, $matches)) {
                        $query = $this->cleanSearchQuery($matches[1] ?? null);

                        if ($query !== null) {
                            return [
                                'website' => $website,
                                'query' => $query,
                            ];
                        }
                    }
                }
            }
        }

        return null;
    }

    private function extractCurrentWebsiteSearchQuery(string $normalized): ?string
    {
        $patterns = [
            '/^(?:search|find|look up|play)\s+(?:for\s+|gfor\s+|about\s+)?(.+)$/u',
            '/^(?:search|find|look up|play)\s+(.+)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                return $this->cleanSearchQuery($matches[1] ?? null);
            }
        }

        return null;
    }

    private function cleanSearchQuery(mixed $query): ?string
    {
        if ($query === null || is_array($query) || is_object($query)) {
            return null;
        }

        $query = trim((string) $query);
        $query = preg_replace('/^(for|gfor|about)\s+/i', '', $query) ?? $query;
        $query = preg_replace('/\s+/', ' ', $query) ?? $query;
        $query = trim($query);

        if ($query === '') {
            return null;
        }

        $blockedQueries = [
            'youtube',
            'you tube',
            'ytb',
            'y t b',
            'yt',
            'google',
            'facebook',
            'gmail',
            'open',
            'go',
            'launch',
            'search',
            'find',
            'play',
        ];

        if (in_array(strtolower($query), $blockedQueries, true)) {
            return null;
        }

        return $query;
    }

    private function detectCurrentWebsiteFromContext(?array $browserContext): ?string
    {
        if (!is_array($browserContext)) {
            return null;
        }

        $memory = $browserContext['memory'] ?? null;

        if (is_array($memory)) {
            $activeWebsite = strtolower((string) ($memory['activeWebsite'] ?? ''));

            if (in_array($activeWebsite, ['youtube', 'google', 'facebook', 'gmail'], true)) {
                return $activeWebsite;
            }

            $lastWebsite = strtolower((string) ($memory['lastWebsite'] ?? ''));

            if (in_array($lastWebsite, ['youtube', 'google', 'facebook', 'gmail'], true)) {
                return $lastWebsite;
            }
        }

        $website = strtolower((string) ($browserContext['website'] ?? ''));
        $url = strtolower((string) ($browserContext['url'] ?? ''));

        $source = $website . ' ' . $url;

        if (str_contains($source, 'youtube.com') || str_contains($source, 'youtube')) {
            return 'youtube';
        }

        if (str_contains($source, 'mail.google.com') || str_contains($source, 'gmail')) {
            return 'gmail';
        }

        if (str_contains($source, 'google.com') || str_contains($source, 'google')) {
            return 'google';
        }

        if (str_contains($source, 'facebook.com') || str_contains($source, 'facebook')) {
            return 'facebook';
        }

        return null;
    }

    private function matchVideoSelectionCommand(string $normalized): ?int
    {
        $hasVideoWord =
            str_contains($normalized, 'video') ||
            str_contains($normalized, 'vd') ||
            str_contains($normalized, 'result');

        $hasNumberWord = preg_match('/\b(first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|one|two|three|four|five|six|seven|eight|nine|ten|1st|2nd|2ns|3rd|4th|5th|6th|7th|8th|9th|10th|\d+)\b/u', $normalized);

        if (!$hasVideoWord && !$hasNumberWord) {
            return null;
        }

        if (!preg_match('/\b(open|play|select|click|choose|start)\b/u', $normalized)) {
            return null;
        }

        $patterns = [
            '/\b(?:video|vd|result)\s+(?:number\s+)?(\d+)\b/u',
            '/\b(?:video|vd|result)\s+(?:number\s+)?([a-z0-9]+)\b/u',
            '/\bnumber\s+(\d+)\b/u',
            '/\bnumber\s+([a-z0-9]+)\b/u',
            '/\b(\d+)\s*(?:st|nd|ns|rd|th)?\s+(?:video|vd|result)\b/u',
            '/\b([a-z0-9]+)\s+(?:video|vd|result)\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $value = strtolower(trim($matches[1]));

                if (is_numeric($value)) {
                    $number = (int) $value;

                    return $number > 0 ? $number : null;
                }

                return $this->spokenNumberToInt($value);
            }
        }

        return null;
    }

    private function matchVideoTitleCommand(string $text, ?array $browserContext = null): ?string
    {
        $text = trim($text);

        $patterns = [
            '/^(?:open|play|select|click|choose|start)\s+(?:the\s+)?(?:video|vd)\s+(?:called|named|titled|title)\s+(.+)$/i',
            '/^(?:open|play|select|click|choose|start)\s+(?:the\s+)?(.+?)\s+(?:video|vd)$/i',
            '/^(?:open|play|select|click|choose|start)\s+(?:title|the title)\s+(.+)$/i',
        ];

        if ($this->detectCurrentWebsiteFromContext($browserContext) === 'youtube') {
            $patterns[] = '/^(?:open|play|select|click|choose|start)\s+(.+)$/i';
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return $this->cleanVideoTitleCandidate(trim($matches[1]));
            }
        }

        return null;
    }

    private function cleanVideoTitleCandidate(string $title): ?string
    {
        $title = trim($title);

        if ($title === '') {
            return null;
        }

        $normalizedTitle = $this->normalizeText($title);

        $blocked = [
            'youtube',
            'you tube',
            'ytb',
            'google',
            'facebook',
            'gmail',
            'home',
            'profile',
            'files',
            'settings',
            'video',
            'vd',
            'result',
            'number',
        ];

        if (in_array($normalizedTitle, $blocked, true)) {
            return null;
        }

        if (preg_match('/\b(number|first|second|third|one|two|three|four|five|six|seven|eight|nine|ten|1st|2nd|2ns|3rd)\b/u', $normalizedTitle)) {
            return null;
        }

        return $title;
    }

    private function spokenNumberToInt(string $word): ?int
    {
        $word = strtolower(trim($word));

        $numbers = [
            '1' => 1,
            '1st' => 1,
            'one' => 1,
            'first' => 1,
            'won' => 1,

            '2' => 2,
            '2nd' => 2,
            '2ns' => 2,
            'two' => 2,
            'second' => 2,
            'to' => 2,
            'too' => 2,

            '3' => 3,
            '3rd' => 3,
            'three' => 3,
            'third' => 3,
            'tree' => 3,

            '4' => 4,
            '4th' => 4,
            'four' => 4,
            'fourth' => 4,
            'for' => 4,

            '5' => 5,
            '5th' => 5,
            'five' => 5,
            'fifth' => 5,

            '6' => 6,
            '6th' => 6,
            'six' => 6,
            'sixth' => 6,

            '7' => 7,
            '7th' => 7,
            'seven' => 7,
            'seventh' => 7,

            '8' => 8,
            '8th' => 8,
            'eight' => 8,
            'eighth' => 8,
            'ate' => 8,

            '9' => 9,
            '9th' => 9,
            'nine' => 9,
            'ninth' => 9,

            '10' => 10,
            '10th' => 10,
            'ten' => 10,
            'tenth' => 10,
        ];

        return $numbers[$word] ?? null;
    }

    private function matchBrowserAction(string $normalized): ?string
    {
        if (
            $normalized === 'scroll down' ||
            $normalized === 'page down' ||
            $normalized === 'go down' ||
            str_contains($normalized, 'scroll down') ||
            str_contains($normalized, 'show me more') ||
            str_contains($normalized, 'scroll more')
        ) {
            return 'SCROLL_DOWN';
        }

        if (
            $normalized === 'scroll up' ||
            $normalized === 'page up' ||
            $normalized === 'go up' ||
            str_contains($normalized, 'scroll up')
        ) {
            return 'SCROLL_UP';
        }

        if (
            $normalized === 'go back' ||
            $normalized === 'back' ||
            str_contains($normalized, 'previous page') ||
            str_contains($normalized, 'go back')
        ) {
            return 'GO_BACK';
        }

        if (
            $normalized === 'go forward' ||
            $normalized === 'forward' ||
            str_contains($normalized, 'go forward')
        ) {
            return 'GO_FORWARD';
        }

        if (
            $normalized === 'play' ||
            $normalized === 'play video' ||
            str_contains($normalized, 'play video') ||
            str_contains($normalized, 'resume video')
        ) {
            return 'PLAY_VIDEO';
        }

        if (
            $normalized === 'pause' ||
            $normalized === 'pause video' ||
            str_contains($normalized, 'pause video') ||
            str_contains($normalized, 'stop video')
        ) {
            return 'PAUSE_VIDEO';
        }

        if (
            $normalized === 'mute' ||
            $normalized === 'mute video' ||
            str_contains($normalized, 'turn sound off')
        ) {
            return 'MUTE';
        }

        if (
            $normalized === 'unmute' ||
            $normalized === 'unmute video' ||
            str_contains($normalized, 'turn sound on')
        ) {
            return 'UNMUTE';
        }

        if (
            $normalized === 'volume up' ||
            str_contains($normalized, 'increase volume') ||
            str_contains($normalized, 'make it louder')
        ) {
            return 'VOLUME_UP';
        }

        if (
            $normalized === 'volume down' ||
            str_contains($normalized, 'decrease volume') ||
            str_contains($normalized, 'make it quieter')
        ) {
            return 'VOLUME_DOWN';
        }

        if (
            $normalized === 'read page' ||
            $normalized === 'read this page' ||
            str_contains($normalized, 'read this page')
        ) {
            return 'READ_PAGE';
        }

        if (
            $normalized === 'summarize page' ||
            $normalized === 'summarize this page' ||
            str_contains($normalized, 'give me a summary')
        ) {
            return 'SUMMARIZE_PAGE';
        }

        if (
            $normalized === 'return to assistant' ||
            $normalized === 'go back to assistant' ||
            $normalized === 'open assistant tab' ||
            $normalized === 'focus assistant'
        ) {
            return 'RETURN_TO_ASSISTANT';
        }

        return null;
    }

    private function normalizeWebsiteAction(string $action): string
    {
        $action = strtoupper(trim($action));

        return match ($action) {
            'OPEN', 'OPEN_URL', 'OPEN-URL', 'OPEN_SITE', 'OPEN_WEBSITE' => 'OPEN_URL',
            'SEARCH', 'SEARCH_URL', 'SEARCH-URL', 'SEARCH_WEB' => 'SEARCH',
            default => $action,
        };
    }

    private function normalizeBrowserAction(string $action): string
    {
        $action = strtoupper(trim($action));

        return match ($action) {
            'OPEN_RESULT', 'SELECT', 'CLICK_RESULT' => 'SELECT_RESULT',
            'BACK' => 'GO_BACK',
            'FORWARD' => 'GO_FORWARD',
            'PLAY' => 'PLAY_VIDEO',
            'PAUSE' => 'PAUSE_VIDEO',
            'READ' => 'READ_PAGE',
            'SUMMARIZE' => 'SUMMARIZE_PAGE',
            'RETURN_ASSISTANT', 'FOCUS_ASSISTANT' => 'RETURN_TO_ASSISTANT',
            default => $action,
        };
    }

    private function speechForBrowserAction(string $action): string
    {
        return match ($action) {
            'SELECT_RESULT' => 'Sure, I will open that result now.',
            'CLICK' => 'Sure, I will click that now.',
            'TYPE' => 'Sure, I will type that now.',
            'SCROLL_DOWN' => 'Sure, I will scroll down now.',
            'SCROLL_UP' => 'Sure, I will scroll up now.',
            'GO_BACK' => 'Sure, I will go back now.',
            'GO_FORWARD' => 'Sure, I will go forward now.',
            'PLAY_VIDEO' => 'Sure, I will play the video now.',
            'PAUSE_VIDEO' => 'Sure, I will pause the video now.',
            'MUTE' => 'Sure, I will mute the video now.',
            'UNMUTE' => 'Sure, I will unmute the video now.',
            'VOLUME_UP' => 'Sure, I will increase the volume now.',
            'VOLUME_DOWN' => 'Sure, I will decrease the volume now.',
            'READ_PAGE' => 'Sure, I will read this page now.',
            'SUMMARIZE_PAGE' => 'Sure, I will summarize this page now.',
            'RETURN_TO_ASSISTANT' => 'Sure, I will return to the assistant now.',
            default => 'Sure, I will process that now.',
        };
    }

    private function buildWebsiteOpenSpeech(string $website): string
    {
        return 'Sure, I will open ' . ucfirst($website) . ' now.';
    }

    private function buildWebsiteSearchSpeech(string $website, string $query): string
    {
        return 'Sure, I will search ' . ucfirst($website) . ' for ' . $query . ' now.';
    }

    private function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}@._\-\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function cleanSpeech(mixed $value): string
    {
        $speech = $this->nullableString($value) ?? '';

        $blocked = [
            'i cannot open external websites',
            'i can not open external websites',
            'i cannot execute',
            'i can not execute',
            'i do not have this feature',
            'i don t have this feature',
            'i dont have this feature',
        ];

        $lower = strtolower($speech);

        foreach ($blocked as $phrase) {
            if (str_contains($lower, $phrase)) {
                return 'Sure, I will process that now.';
            }
        }

        return $speech;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }

    private function cleanNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function buildSafeChatPrompt(string $spokenText): string
    {
        return <<<PROMPT
User message:
{$spokenText}

You are the chatbot for this exact accessibility-focused voice assistant web app.

Important:
- Answer normal questions naturally and helpfully.
- You are not the executor.
- Do not claim that you personally executed actions.
- Do not say external website control is impossible when the app supports it.
- If the user asks about a supported action, explain briefly that the secure executor handles actions.
- Do not invent pages that may not exist.
- Do not tell the user to open Settings, Security, Account Management, or Delete Account unless the app explicitly provided that as a local command.
- You cannot delete accounts, change passwords, send email, open websites, or control the browser by yourself.
- Keep answers short, clear, friendly, and accessible.
PROMPT;
    }

    private function buildFirstAvailableLocalAppUrl(array $routeNames, string $fallbackPath, string $baseUrl): string
    {
        foreach ($routeNames as $routeName) {
            try {
                return $baseUrl . $this->generateUrl($routeName);
            } catch (\Throwable) {
                continue;
            }
        }

        return $baseUrl . $fallbackPath;
    }

    private function tryGenerateFirstAvailableLocalAppUrl(array $routeNames, string $baseUrl): ?string
    {
        foreach ($routeNames as $routeName) {
            try {
                return $baseUrl . $this->generateUrl($routeName);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}