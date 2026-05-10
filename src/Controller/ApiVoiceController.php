<?php

namespace App\Controller;

use App\Service\GeminiChatService;
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
             * 1. Email commands first.
             * These are handled by Symfony/Gmail, not Gemini and not the extension.
             */
            $emailResult = $voiceEmailCommandService->handle($spokenText);

            if ($emailResult !== null) {
                return $this->json($emailResult);
            }

            /*
             * 2. Safe local commands.
             * The controller decides what is executable.
             * Gemini is not the executor.
             */
            $localResult = $this->tryLocalCommand(
                $spokenText,
                $language,
                is_array($browserContext) ? $browserContext : null,
                $baseUrl
            );

            if ($localResult !== null) {
                return $this->json($localResult);
            }

            /*
             * 3. Gemini chatbot fallback.
             * Gemini answers normal conversation and unsupported questions only.
             */
            $chatResult = $geminiChatService->chat(
                $this->buildSafeChatPrompt($spokenText),
                $language
            );

            if (!is_array($chatResult)) {
                return $this->json([
                    'success' => false,
                    'intent' => 'CHAT',
                    'mode' => 'GEMINI_CHAT_INVALID',
                    'requiresExtension' => false,
                    'message' => 'Gemini returned an invalid chat response.',
                    'speech' => 'Gemini returned an invalid chat response.',
                ]);
            }

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
                'rawGeminiResult' => $chatResult,
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

    private function tryLocalCommand(string $text, string $language, ?array $browserContext, string $baseUrl): ?array
    {
        $normalized = $this->normalizeText($text);

        /*
         * Dangerous or unavailable account actions.
         * Do not let Gemini invent Settings/Security/Delete Account pages.
         */
        if ($this->isDeleteAccountCommand($normalized)) {
            return $this->buildDeleteAccountResponse($baseUrl);
        }

        if ($this->isDisableAssistantCommand($normalized)) {
            return $this->buildLocalMessageAction(
                'LOCAL_DISABLE_ASSISTANT_NOT_AVAILABLE',
                'There is no voice assistant disable setting connected yet. You can stop listening by pressing Stop, or say log out to leave your account.',
                'There is no voice assistant disable setting connected yet. You can stop listening by pressing Stop, or say log out to leave your account.'
            );
        }

        /*
         * Account/session commands.
         */
        if ($this->isLogoutCommand($normalized)) {
            return $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(
                    ['app_logout', 'security_logout', 'logout'],
                    '/logout',
                    $baseUrl
                ),
                'Logging you out.',
                'LOCAL_APP_LOGOUT'
            );
        }

        if ($this->isChangePasswordCommand($normalized)) {
            return $this->buildChangePasswordResponse($baseUrl);
        }

        if ($this->isSettingsCommand($normalized)) {
            return $this->buildSettingsResponse($baseUrl);
        }

        if ($this->isGmailConnectCommand($normalized)) {
            return $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(
                    ['google_oauth_connect'],
                    '/google/oauth/connect',
                    $baseUrl
                ),
                'Opening Gmail connection.',
                'LOCAL_GMAIL_CONNECT'
            );
        }

        /*
         * Internal app navigation.
         */
        if ($this->isHomeCommand($normalized)) {
            return $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(
                    ['front_home', 'app_home', 'home'],
                    '/front/home',
                    $baseUrl
                ),
                'Opening home.',
                'LOCAL_APP_HOME'
            );
        }

        if ($this->isProfileCommand($normalized)) {
            return $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(
                    ['front_profile', 'app_profile', 'profile'],
                    '/front/profile',
                    $baseUrl
                ),
                'Opening your profile.',
                'LOCAL_APP_PROFILE'
            );
        }

        if ($this->isFilesCommand($normalized)) {
            return $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(
                    ['front_files', 'app_files', 'files'],
                    '/front/files',
                    $baseUrl
                ),
                'Opening your files.',
                'LOCAL_APP_FILES'
            );
        }

        if ($this->isVoiceCommandsCommand($normalized)) {
            return $this->buildAppNavigationAction(
                $this->buildFirstAvailableLocalAppUrl(
                    ['front_voice_commands', 'voice_commands', 'app_voice_commands'],
                    '/front/voice-commands',
                    $baseUrl
                ),
                'Opening voice commands.',
                'LOCAL_APP_VOICE_COMMANDS'
            );
        }

        /*
         * Website open/search.
         * These produce a friendly assistant answer AND executor command.
         */
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

        /*
         * Natural implicit website searches:
         * - night club music on YouTube
         * - cats on ytb
         * - play night club music on YouTube
         */
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

        /*
         * Current controlled website search:
         * User opens YouTube, then says "search for cats".
         */
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

        /*
         * YouTube/search result video selection.
         */
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
                'LOCAL_VIDEO_SELECT_RESULT'
            );
        }

        $videoTitle = $this->matchVideoTitleCommand($text);

        if ($videoTitle !== null) {
            return $this->buildBrowserAction(
                'CLICK',
                null,
                $videoTitle,
                null,
                'Sure, I will open the matching video now.',
                $language,
                $browserContext,
                'LOCAL_VIDEO_CLICK_TITLE'
            );
        }

        /*
         * Browser/page controls.
         */
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

        if (preg_match('/^(open|click|select|play)\s+(?:result\s+)?(?:number\s+)?(\d+)$/i', $normalized, $matches)) {
            return $this->buildBrowserAction(
                'SELECT_RESULT',
                null,
                null,
                (int) $matches[2],
                'Sure, I will open result number ' . $matches[2] . ' now.',
                $language,
                $browserContext,
                'LOCAL_SELECT_RESULT'
            );
        }

        if (preg_match('/^(open|click|select|play)\s+(?:result\s+)?(?:number\s+)?([a-z]+)$/i', $normalized, $matches)) {
            $number = $this->spokenNumberToInt($matches[2]);

            if ($number !== null) {
                return $this->buildBrowserAction(
                    'SELECT_RESULT',
                    null,
                    null,
                    $number,
                    'Sure, I will open result number ' . $number . ' now.',
                    $language,
                    $browserContext,
                    'LOCAL_SELECT_RESULT_WORD'
                );
            }
        }

        if (preg_match('/^(click|press|select)\s+(.+)$/i', $text, $matches)) {
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

        if (preg_match('/^(type|write|enter)\s+(.+)$/i', $text, $matches)) {
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

    private function isDeleteAccountCommand(string $normalized): bool
    {
        if (
            !str_contains($normalized, 'account') &&
            !str_contains($normalized, 'profile')
        ) {
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
            str_contains($normalized, 'deactivate account') ||
            str_contains($normalized, 'erase my account') ||
            str_contains($normalized, 'erase account') ||
            str_contains($normalized, 'i want to delete the account') ||
            str_contains($normalized, 'i want to delete my account') ||
            str_contains($normalized, 'i want to close my account')
        );
    }

    private function isDisableAssistantCommand(string $normalized): bool
    {
        return (
            str_contains($normalized, 'get rid of you') ||
            str_contains($normalized, 'remove voice assistant') ||
            str_contains($normalized, 'disable voice assistant') ||
            str_contains($normalized, 'turn off voice assistant') ||
            str_contains($normalized, 'switch off voice assistant') ||
            str_contains($normalized, 'hide voice assistant') ||
            str_contains($normalized, 'stop voice assistant') ||
            str_contains($normalized, 'disable assistant') ||
            str_contains($normalized, 'turn you off') ||
            str_contains($normalized, 'i do not want you') ||
            str_contains($normalized, 'i dont want you') ||
            str_contains($normalized, 'i don t want you')
        );
    }

    private function isLogoutCommand(string $normalized): bool
    {
        return (
            $normalized === 'logout' ||
            $normalized === 'log out' ||
            $normalized === 'sign out' ||
            $normalized === 'disconnect' ||
            $normalized === 'exit account' ||
            $normalized === 'close my session' ||
            $normalized === 'end my session' ||
            str_contains($normalized, 'log me out') ||
            str_contains($normalized, 'sign me out') ||
            str_contains($normalized, 'logout from my account') ||
            str_contains($normalized, 'log out from my account') ||
            str_contains($normalized, 'disconnect my account')
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
            str_contains($normalized, 'update my password') ||
            str_contains($normalized, 'modify password') ||
            str_contains($normalized, 'edit password') ||
            str_contains($normalized, 'reset password') ||
            str_contains($normalized, 'reset my password') ||
            str_contains($normalized, 'forgot password') ||
            str_contains($normalized, 'password settings') ||
            str_contains($normalized, 'password section') ||
            str_contains($normalized, 'i want to change my password') ||
            str_contains($normalized, 'i need to change my password') ||
            str_contains($normalized, 'take me to change password') ||
            str_contains($normalized, 'open change password') ||
            str_contains($normalized, 'open password')
        );
    }

    private function isSettingsCommand(string $normalized): bool
    {
        return (
            $normalized === 'settings' ||
            $normalized === 'open settings' ||
            $normalized === 'go to settings' ||
            $normalized === 'show settings' ||
            $normalized === 'account settings' ||
            $normalized === 'open account settings' ||
            $normalized === 'go to account settings' ||
            $normalized === 'security settings' ||
            $normalized === 'open security' ||
            $normalized === 'go to security' ||
            str_contains($normalized, 'take me to settings') ||
            str_contains($normalized, 'open my settings') ||
            str_contains($normalized, 'show my settings') ||
            str_contains($normalized, 'open security settings') ||
            str_contains($normalized, 'show security settings')
        );
    }

    private function isGmailConnectCommand(string $normalized): bool
    {
        return (
            str_contains($normalized, 'connect gmail') ||
            str_contains($normalized, 'connect my gmail') ||
            str_contains($normalized, 'gmail connection') ||
            str_contains($normalized, 'connect email') ||
            str_contains($normalized, 'connect my email') ||
            str_contains($normalized, 'connect google email') ||
            str_contains($normalized, 'authorize gmail') ||
            str_contains($normalized, 'link gmail') ||
            str_contains($normalized, 'link my gmail')
        );
    }

    private function isHomeCommand(string $normalized): bool
    {
        if (
            !preg_match('/\bhome\b/u', $normalized) &&
            !str_contains($normalized, 'dashboard')
        ) {
            return false;
        }

        return (
            $normalized === 'home' ||
            $normalized === 'dashboard' ||
            str_contains($normalized, 'go home') ||
            str_contains($normalized, 'go back home') ||
            str_contains($normalized, 'go back to home') ||
            str_contains($normalized, 'return home') ||
            str_contains($normalized, 'return to home') ||
            str_contains($normalized, 'back home') ||
            str_contains($normalized, 'take me home') ||
            str_contains($normalized, 'open home') ||
            str_contains($normalized, 'show home') ||
            str_contains($normalized, 'show me home') ||
            str_contains($normalized, 'go to home') ||
            str_contains($normalized, 'go to the home') ||
            str_contains($normalized, 'open dashboard') ||
            str_contains($normalized, 'go to dashboard') ||
            str_contains($normalized, 'show dashboard') ||
            str_contains($normalized, 'can you take me home') ||
            str_contains($normalized, 'can you go home') ||
            str_contains($normalized, 'can you return home') ||
            str_contains($normalized, 'i want to go home') ||
            str_contains($normalized, 'i want to return home') ||
            str_contains($normalized, 'i want home') ||
            str_contains($normalized, 'bring me home')
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
            str_contains($normalized, 'see profile') ||
            str_contains($normalized, 'see my profile') ||
            str_contains($normalized, 'view profile') ||
            str_contains($normalized, 'view my profile') ||
            str_contains($normalized, 'go to profile') ||
            str_contains($normalized, 'go to my profile') ||
            str_contains($normalized, 'take me to profile') ||
            str_contains($normalized, 'take me to my profile') ||
            str_contains($normalized, 'can you show me my profile') ||
            str_contains($normalized, 'can you open my profile') ||
            str_contains($normalized, 'can you take me to my profile') ||
            str_contains($normalized, 'i want to see my profile') ||
            str_contains($normalized, 'i want to view my profile') ||
            str_contains($normalized, 'i want my profile') ||
            str_contains($normalized, 'bring up my profile') ||
            str_contains($normalized, 'edit profile') ||
            str_contains($normalized, 'edit my profile') ||
            str_contains($normalized, 'update profile') ||
            str_contains($normalized, 'update my profile')
        );
    }

    private function isFilesCommand(string $normalized): bool
    {
        if (
            !preg_match('/\bfile\b/u', $normalized) &&
            !preg_match('/\bfiles\b/u', $normalized)
        ) {
            return false;
        }

        return (
            $normalized === 'files' ||
            $normalized === 'my files' ||
            str_contains($normalized, 'open files') ||
            str_contains($normalized, 'open my files') ||
            str_contains($normalized, 'show files') ||
            str_contains($normalized, 'show my files') ||
            str_contains($normalized, 'show me my files') ||
            str_contains($normalized, 'see files') ||
            str_contains($normalized, 'see my files') ||
            str_contains($normalized, 'view files') ||
            str_contains($normalized, 'view my files') ||
            str_contains($normalized, 'go to files') ||
            str_contains($normalized, 'go to my files') ||
            str_contains($normalized, 'take me to files') ||
            str_contains($normalized, 'take me to my files') ||
            str_contains($normalized, 'can you show me my files') ||
            str_contains($normalized, 'can you open my files') ||
            str_contains($normalized, 'i want to see my files') ||
            str_contains($normalized, 'i want my files')
        );
    }

    private function isVoiceCommandsCommand(string $normalized): bool
    {
        return (
            str_contains($normalized, 'voice commands') ||
            str_contains($normalized, 'voice command') ||
            str_contains($normalized, 'voice assistant') ||
            str_contains($normalized, 'open assistant') ||
            str_contains($normalized, 'go to assistant') ||
            str_contains($normalized, 'go to voice assistant') ||
            str_contains($normalized, 'open voice assistant') ||
            str_contains($normalized, 'show commands') ||
            str_contains($normalized, 'available commands') ||
            str_contains($normalized, 'what can i say') ||
            str_contains($normalized, 'what can i do')
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
            $normalized === 'i want ' . $alias ||
            $normalized === 'i want to open ' . $alias ||
            $normalized === 'i want to go to ' . $alias ||
            $normalized === 'can you open ' . $alias ||
            $normalized === 'can you go to ' . $alias ||
            $normalized === 'please open ' . $alias ||
            $normalized === 'please go to ' . $alias
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

        $website = strtolower((string) ($browserContext['website'] ?? ''));
        $url = strtolower((string) ($browserContext['url'] ?? ''));

        $source = $website . ' ' . $url;

        if (str_contains($source, 'youtube.com') || str_contains($source, 'youtube')) {
            return 'youtube';
        }

        if (str_contains($source, 'google.com') || str_contains($source, 'google')) {
            return 'google';
        }

        if (str_contains($source, 'facebook.com') || str_contains($source, 'facebook')) {
            return 'facebook';
        }

        if (str_contains($source, 'mail.google.com') || str_contains($source, 'gmail')) {
            return 'gmail';
        }

        return null;
    }

    private function matchVideoSelectionCommand(string $normalized): ?int
    {
        if (
            !preg_match('/\b(video|result)\b/u', $normalized) &&
            !preg_match('/\bnumber\b/u', $normalized)
        ) {
            return null;
        }

        if (!preg_match('/\b(open|play|select|click)\b/u', $normalized)) {
            return null;
        }

        if (preg_match('/\b(?:video|result)\s+(?:number\s+)?(\d+)\b/u', $normalized, $matches)) {
            $number = (int) $matches[1];

            return $number > 0 ? $number : null;
        }

        if (preg_match('/\bnumber\s+(\d+)\b/u', $normalized, $matches)) {
            $number = (int) $matches[1];

            return $number > 0 ? $number : null;
        }

        if (preg_match('/\b(?:video|result)\s+(?:number\s+)?([a-z]+)\b/u', $normalized, $matches)) {
            return $this->spokenNumberToInt($matches[1]);
        }

        if (preg_match('/\bnumber\s+([a-z]+)\b/u', $normalized, $matches)) {
            return $this->spokenNumberToInt($matches[1]);
        }

        return null;
    }

    private function matchVideoTitleCommand(string $text): ?string
    {
        $text = trim($text);

        $patterns = [
            '/^(?:open|play|select|click)\s+(?:the\s+)?video\s+(?:called|named|titled|title)\s+(.+)$/i',
            '/^(?:open|play|select|click)\s+(?:the\s+)?(.+?)\s+video$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $title = trim($matches[1]);

                if ($title === '') {
                    continue;
                }

                $normalizedTitle = $this->normalizeText($title);

                if (
                    in_array($normalizedTitle, [
                        'youtube',
                        'google',
                        'facebook',
                        'gmail',
                        'home',
                        'profile',
                        'files',
                        'settings',
                    ], true)
                ) {
                    continue;
                }

                return $title;
            }
        }

        return null;
    }

    private function spokenNumberToInt(string $word): ?int
    {
        $word = strtolower(trim($word));

        $numbers = [
            'one' => 1,
            'first' => 1,
            'won' => 1,
            'two' => 2,
            'second' => 2,
            'to' => 2,
            'too' => 2,
            'three' => 3,
            'third' => 3,
            'tree' => 3,
            'four' => 4,
            'fourth' => 4,
            'for' => 4,
            'five' => 5,
            'fifth' => 5,
            'six' => 6,
            'sixth' => 6,
            'seven' => 7,
            'seventh' => 7,
            'eight' => 8,
            'eighth' => 8,
            'ate' => 8,
            'nine' => 9,
            'ninth' => 9,
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
            $normalized === 'move down' ||
            $normalized === 'show me more' ||
            $normalized === 'next part' ||
            $normalized === 'continue down' ||
            str_contains($normalized, 'scroll down') ||
            str_contains($normalized, 'page down') ||
            str_contains($normalized, 'go down') ||
            str_contains($normalized, 'move down') ||
            str_contains($normalized, 'show me more') ||
            str_contains($normalized, 'scroll more') ||
            str_contains($normalized, 'you dont scroll down') ||
            str_contains($normalized, 'you don t scroll down') ||
            str_contains($normalized, 'you did not scroll down') ||
            str_contains($normalized, 'you didnt scroll down')
        ) {
            return 'SCROLL_DOWN';
        }

        if (
            $normalized === 'scroll up' ||
            $normalized === 'page up' ||
            $normalized === 'go up' ||
            $normalized === 'move up' ||
            $normalized === 'previous part' ||
            $normalized === 'back up' ||
            str_contains($normalized, 'scroll up') ||
            str_contains($normalized, 'page up') ||
            str_contains($normalized, 'go up') ||
            str_contains($normalized, 'move up') ||
            str_contains($normalized, 'back up')
        ) {
            return 'SCROLL_UP';
        }

        if (
            $normalized === 'go back' ||
            $normalized === 'back' ||
            $normalized === 'previous page' ||
            $normalized === 'return back' ||
            $normalized === 'go to previous page' ||
            str_contains($normalized, 'go back') ||
            str_contains($normalized, 'previous page')
        ) {
            return 'GO_BACK';
        }

        if (
            $normalized === 'go forward' ||
            $normalized === 'forward' ||
            $normalized === 'next page' ||
            str_contains($normalized, 'go forward')
        ) {
            return 'GO_FORWARD';
        }

        if (
            $normalized === 'play' ||
            $normalized === 'play video' ||
            $normalized === 'start video' ||
            $normalized === 'resume video' ||
            $normalized === 'continue video' ||
            str_contains($normalized, 'play video') ||
            str_contains($normalized, 'resume video')
        ) {
            return 'PLAY_VIDEO';
        }

        if (
            $normalized === 'pause' ||
            $normalized === 'pause video' ||
            $normalized === 'stop video' ||
            $normalized === 'stop the video' ||
            str_contains($normalized, 'pause video') ||
            str_contains($normalized, 'stop video')
        ) {
            return 'PAUSE_VIDEO';
        }

        if (
            $normalized === 'mute' ||
            $normalized === 'mute video' ||
            $normalized === 'turn sound off' ||
            $normalized === 'sound off' ||
            str_contains($normalized, 'mute video') ||
            str_contains($normalized, 'turn sound off')
        ) {
            return 'MUTE';
        }

        if (
            $normalized === 'unmute' ||
            $normalized === 'unmute video' ||
            $normalized === 'turn sound on' ||
            $normalized === 'sound on' ||
            str_contains($normalized, 'unmute video') ||
            str_contains($normalized, 'turn sound on')
        ) {
            return 'UNMUTE';
        }

        if (
            $normalized === 'volume up' ||
            $normalized === 'increase volume' ||
            $normalized === 'turn volume up' ||
            $normalized === 'make it louder' ||
            str_contains($normalized, 'volume up') ||
            str_contains($normalized, 'increase volume')
        ) {
            return 'VOLUME_UP';
        }

        if (
            $normalized === 'volume down' ||
            $normalized === 'decrease volume' ||
            $normalized === 'turn volume down' ||
            $normalized === 'make it quieter' ||
            str_contains($normalized, 'volume down') ||
            str_contains($normalized, 'decrease volume')
        ) {
            return 'VOLUME_DOWN';
        }

        if (
            $normalized === 'read page' ||
            $normalized === 'read this page' ||
            $normalized === 'read the page' ||
            $normalized === 'read it' ||
            str_contains($normalized, 'read this page') ||
            str_contains($normalized, 'read the page')
        ) {
            return 'READ_PAGE';
        }

        if (
            $normalized === 'summarize page' ||
            $normalized === 'summarize this page' ||
            $normalized === 'summarize the page' ||
            $normalized === 'summary' ||
            $normalized === 'give me a summary' ||
            str_contains($normalized, 'summarize this page') ||
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
                'Opening the account deletion page.',
                'LOCAL_APP_DELETE_ACCOUNT'
            );
        }

        return [
            'success' => false,
            'intent' => 'APP_NAVIGATION',
            'mode' => 'DELETE_ACCOUNT_NOT_CONNECTED',
            'requiresExtension' => false,
            'message' => 'Account deletion is not connected in this app yet. I will not invent a Settings or Security page. Please add a delete-account route first, or delete the account manually from the admin side.',
            'speech' => 'Account deletion is not connected in this app yet. I will not invent a Settings or Security page. Please add a delete-account route first, or delete the account manually from the admin side.',
        ];
    }

    private function buildChangePasswordResponse(string $baseUrl): array
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
                'Opening the password section.',
                'LOCAL_APP_CHANGE_PASSWORD'
            );
        }

        return $this->buildAppNavigationAction(
            $this->buildFirstAvailableLocalAppUrl(['front_profile', 'app_profile', 'profile'], '/front/profile', $baseUrl),
            'There is no separate password page connected yet. Opening your profile.',
            'LOCAL_APP_CHANGE_PASSWORD_FALLBACK'
        );
    }

    private function buildSettingsResponse(string $baseUrl): array
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
                'Opening your account page.',
                'LOCAL_APP_SETTINGS'
            );
        }

        return $this->buildAppNavigationAction(
            $baseUrl . '/front/profile',
            'There is no settings page connected yet. Opening your profile.',
            'LOCAL_APP_SETTINGS_FALLBACK'
        );
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

    private function buildLocalMessageAction(string $mode, string $message, string $speech): array
    {
        return [
            'success' => true,
            'intent' => 'APP_MESSAGE',
            'mode' => $mode,
            'requiresExtension' => false,
            'message' => $message,
            'speech' => $speech,
        ];
    }

    private function buildWebsiteAction(
        string $action,
        ?string $website,
        ?string $url,
        ?string $query,
        string $speech,
        string $language,
        ?array $browserContext,
        string $mode,
        mixed $confidence = null,
        mixed $reason = null
    ): array {
        $action = $this->normalizeWebsiteAction($action);
        $website = $website !== null ? strtolower($website) : null;

        $allowedWebsites = ['youtube', 'google', 'facebook', 'gmail'];

        if ($website !== null && !in_array($website, $allowedWebsites, true)) {
            return [
                'success' => false,
                'intent' => 'WEBSITE_ACTION',
                'mode' => 'WEBSITE_NOT_ALLOWED',
                'requiresExtension' => false,
                'message' => 'This website is not allowed.',
                'speech' => 'This website is not allowed.',
                'confidence' => $confidence,
                'reason' => $reason,
            ];
        }

        if ($website === null && $url === null) {
            return [
                'success' => false,
                'intent' => 'WEBSITE_ACTION',
                'mode' => 'MISSING_WEBSITE',
                'requiresExtension' => false,
                'message' => 'Website is missing.',
                'speech' => 'Website is missing.',
                'confidence' => $confidence,
                'reason' => $reason,
            ];
        }

        if ($action === 'SEARCH' && ($query === null || trim($query) === '')) {
            return [
                'success' => false,
                'intent' => 'WEBSITE_ACTION',
                'mode' => 'MISSING_SEARCH_QUERY',
                'requiresExtension' => false,
                'message' => 'Search query is missing.',
                'speech' => 'Search query is missing.',
                'confidence' => $confidence,
                'reason' => $reason,
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
            'confidence' => $confidence,
            'reason' => $reason,
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
        string $mode,
        mixed $confidence = null,
        mixed $reason = null
    ): array {
        $action = $this->normalizeBrowserAction($action);

        if ($action === '') {
            return [
                'success' => false,
                'intent' => 'BROWSER_ACTION',
                'mode' => 'MISSING_BROWSER_ACTION',
                'requiresExtension' => false,
                'message' => 'Browser action is missing.',
                'speech' => 'Browser action is missing.',
                'confidence' => $confidence,
                'reason' => $reason,
            ];
        }

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
            'confidence' => $confidence,
            'reason' => $reason,
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

    private function buildWebsiteOpenSpeech(string $website): string
    {
        return 'Sure, I will open ' . ucfirst($website) . ' now.';
    }

    private function buildWebsiteSearchSpeech(string $website, string $query): string
    {
        return 'Sure, I will search ' . ucfirst($website) . ' for ' . $query . ' now.';
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

    private function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }

    private function buildSafeChatPrompt(string $spokenText): string
    {
        return <<<PROMPT
User message:
{$spokenText}

You are the chatbot for this exact accessibility-focused voice assistant web app.

Important:
- Answer normal questions naturally and helpfully.
- Do not claim that you personally executed actions.
- Do not say external website control is impossible when the app supports it.
- If the user asks about a supported action, explain briefly that the secure executor handles actions.
- Do not invent pages that may not exist.
- Do not tell the user to open Settings, Security, Account Management, or Delete Account unless the app explicitly provided that as a local command.
- You cannot delete accounts, change passwords, send email, open websites, or control the browser by yourself.
- If the user asks for an action that is not connected, say that it is not connected yet.
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