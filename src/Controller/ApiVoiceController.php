<?php

namespace App\Controller;

use App\Service\AiCommandLogService;
use App\Service\GmailService;
use App\Service\LlmCommandInterpreterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;

class ApiVoiceController extends AbstractController
{
    #[Route('/api/voice/interpret', name: 'api_voice_interpret', methods: ['POST', 'OPTIONS'])]
    public function interpret(
        Request $request,
        RequestStack $requestStack,
        LlmCommandInterpreterService $llmCommandInterpreter,
        AiCommandLogService $aiCommandLogService,
        GmailService $gmailService
    ): JsonResponse {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->json([
                'success' => true,
                'message' => 'CORS preflight accepted.',
            ], 200);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $spokenText = trim((string) ($data['text'] ?? ''));
        $language = trim((string) ($data['language'] ?? 'en-US'));
        $browserContext = $data['browserContext'] ?? null;

        if ($spokenText === '') {
            return $this->json([
                'success' => false,
                'message' => 'The text field is required.',
            ], 400);
        }

        $user = $this->getUser();
        $userId = is_object($user) && method_exists($user, 'getId') ? $user->getId() : null;

        $pendingCancelResult = $this->handlePendingEmailCancellationCommand(
            $spokenText,
            $requestStack,
            $aiCommandLogService,
            $userId
        );

        if (is_array($pendingCancelResult)) {
            return $this->json($pendingCancelResult, 200);
        }

        $pendingBodyResult = $this->completePendingEmailBodyCommand(
            $spokenText,
            $language,
            $requestStack,
            $aiCommandLogService,
            $userId
        );

        if (is_array($pendingBodyResult)) {
            return $this->json($pendingBodyResult, 200);
        }

        /*
         * Local email fallback first.
         * This prevents "send an email to john@gmail.com" from being detected as "open Gmail".
         */
        $localEmailResult = $this->tryLocalEmailCommand($spokenText, $language);

        if (is_array($localEmailResult)) {
            $emailResult = $this->handleEmailAction(
                $localEmailResult,
                $spokenText,
                $userId,
                $requestStack,
                $aiCommandLogService,
                $gmailService
            );

            return $this->json($emailResult, 200);
        }

        /*
         * Local browser / website fallback.
         * Browser/page controls are checked before website open/search logic.
         */
        $localResult = $this->tryLocalExternalCommand($spokenText, $language, $browserContext);

        if ($localResult !== null) {
            $aiCommandLogService->log($spokenText, $localResult, $userId);

            return $this->json($localResult, 200);
        }

        /*
         * LLM mode:
         * Symfony + LLM = brain
         * Chrome extension = executor
         */
        $allowedActions = $this->getAllowedExternalActionsForLlm();

        $llmResponse = $llmCommandInterpreter->interpretWebsiteCommand(
            $spokenText,
            $allowedActions,
            $language,
            is_array($browserContext) ? $browserContext : null
        );

        if (($llmResponse['success'] ?? false) !== true) {
            $message = $llmResponse['message'] ?? 'LLM interpretation failed.';

            if (str_contains($message, 'HTTP 402')) {
                $message = 'The AI service quota is exhausted. Basic browser commands still work, but advanced natural-language interpretation needs a working AI API key.';
            }

            $failedResult = [
                'success' => false,
                'message' => $message,
                'speech' => $message,
                'llmResult' => $llmResponse['result'] ?? $llmResponse['llmResult'] ?? null,
                'mode' => 'LLM_FAILED',
            ];

            $aiCommandLogService->log($spokenText, $failedResult, $userId);

            return $this->json($failedResult, 200);
        }

        $llmResult = $llmResponse['result'];
        $intent = strtoupper((string) ($llmResult['intent'] ?? ''));

        if ($intent === 'EMAIL_ACTION') {
            $emailResult = $this->handleEmailAction(
                $llmResult,
                $spokenText,
                $userId,
                $requestStack,
                $aiCommandLogService,
                $gmailService
            );

            return $this->json($emailResult, 200);
        }

        if ($intent === 'UNKNOWN') {
            $unknownResult = [
                'success' => false,
                'intent' => 'UNKNOWN',
                'mode' => 'LLM_UNKNOWN',
                'requiresExtension' => false,
                'message' => $llmResult['speech'] ?? 'I could not safely understand that command.',
                'speech' => $llmResult['speech'] ?? 'I could not safely understand that command.',
                'reason' => $llmResult['reason'] ?? null,
                'confidence' => $llmResult['confidence'] ?? null,
            ];

            $aiCommandLogService->log($spokenText, $unknownResult, $userId);

            return $this->json($unknownResult, 200);
        }

        if ($intent === 'BROWSER_ACTION') {
            $browserResult = $this->buildBrowserActionResult($llmResult, $language, $browserContext);

            $aiCommandLogService->log($spokenText, $browserResult, $userId);

            return $this->json($browserResult, 200);
        }

        if ($intent === 'WEBSITE_ACTION') {
            $websiteResult = $this->buildWebsiteActionResult($llmResult, $language, $browserContext);

            $aiCommandLogService->log($spokenText, $websiteResult, $userId);

            return $this->json($websiteResult, 200);
        }

        $fallbackResult = [
            'success' => false,
            'intent' => 'UNKNOWN',
            'mode' => 'UNSUPPORTED_INTENT',
            'requiresExtension' => false,
            'message' => 'I could not match that to a supported action.',
            'speech' => 'I could not match that to a supported action.',
            'rawLlmResult' => $llmResult,
        ];

        $aiCommandLogService->log($spokenText, $fallbackResult, $userId);

        return $this->json($fallbackResult, 200);
    }

    private function buildBrowserActionResult(array $llmResult, string $language, mixed $browserContext): array
    {
        $resultPosition = $this->normalizeResultPosition($llmResult['resultPosition'] ?? null);

        return [
            'success' => true,
            'intent' => 'BROWSER_ACTION',
            'mode' => 'LOCAL_OR_LLM_BROWSER_ACTION',
            'requiresExtension' => true,
            'extensionCommand' => [
                'action' => $this->normalizeBrowserAction($llmResult['action'] ?? null),
                'website' => $llmResult['website'] ?? null,
                'query' => $llmResult['query'] ?? null,
                'target' => $llmResult['target'] ?? null,
                'resultPosition' => $resultPosition,
                'nextAction' => $this->normalizeNextAction($llmResult['nextAction'] ?? null),
                'language' => $language,
                'context' => is_array($browserContext) ? $browserContext : null,
            ],
            'message' => $llmResult['speech'] ?? 'I will execute this action in the browser.',
            'speech' => $llmResult['speech'] ?? 'I will execute this action in the browser.',
            'confidence' => $llmResult['confidence'] ?? null,
            'reason' => $llmResult['reason'] ?? null,
        ];
    }

    private function buildWebsiteActionResult(array $llmResult, string $language, mixed $browserContext): array
    {
        $website = strtolower(trim((string) ($llmResult['website'] ?? '')));
        $action = strtolower(trim((string) ($llmResult['action'] ?? '')));
        $query = isset($llmResult['query']) ? trim((string) $llmResult['query']) : null;
        $resultPosition = $this->normalizeResultPosition($llmResult['resultPosition'] ?? null);

        $allowedWebsites = ['youtube', 'google', 'facebook', 'gmail'];
        $allowedActions = ['open', 'open_url', 'search', 'search_url'];

        if (!in_array($website, $allowedWebsites, true)) {
            return [
                'success' => false,
                'intent' => 'WEBSITE_ACTION',
                'mode' => 'WEBSITE_NOT_ALLOWED',
                'requiresExtension' => false,
                'message' => 'This website is not allowed.',
                'speech' => 'This website is not allowed.',
                'website' => $website ?: null,
            ];
        }

        if (!in_array($action, $allowedActions, true)) {
            return [
                'success' => false,
                'intent' => 'WEBSITE_ACTION',
                'mode' => 'WEBSITE_ACTION_NOT_ALLOWED',
                'requiresExtension' => false,
                'message' => 'This website action is not allowed.',
                'speech' => 'This website action is not allowed.',
                'website' => $website,
                'action' => $action ?: null,
            ];
        }

        $url = $this->buildExternalWebsiteUrl($website, $action, $query);

        return [
            'success' => true,
            'intent' => 'WEBSITE_ACTION',
            'mode' => 'LOCAL_OR_LLM_WEBSITE_ACTION',
            'requiresExtension' => true,
            'website' => $website,
            'websiteName' => ucfirst($website),
            'action' => $action,
            'query' => $query,
            'url' => $url,
            'resultPosition' => $resultPosition,
            'language' => $language,
            'extensionCommand' => [
                'action' => $this->normalizeWebsiteActionForExtension($action),
                'website' => $website,
                'websiteName' => ucfirst($website),
                'url' => $url,
                'query' => $query,
                'target' => null,
                'resultPosition' => $resultPosition,
                'nextAction' => $this->normalizeNextAction($llmResult['nextAction'] ?? null),
                'language' => $language,
                'context' => is_array($browserContext) ? $browserContext : null,
            ],
            'message' => $llmResult['speech'] ?? ($action === 'search' ? 'Searching website.' : 'Opening website.'),
            'speech' => $llmResult['speech'] ?? ($action === 'search' ? 'Searching website.' : 'Opening website.'),
            'confidence' => $llmResult['confidence'] ?? null,
            'reason' => $llmResult['reason'] ?? null,
        ];
    }

    private function handlePendingEmailCancellationCommand(
        string $spokenText,
        RequestStack $requestStack,
        AiCommandLogService $aiCommandLogService,
        ?int $userId
    ): ?array {
        $session = $requestStack->getSession();
        $hasPendingEmail = is_array($session->get('pending_email_to_send')) || is_array($session->get('pending_email_missing_body'));

        if (!$hasPendingEmail) {
            return null;
        }

        $normalized = mb_strtolower(trim($spokenText));

        if (!$this->looksLikeEmailCancellation($normalized)) {
            return null;
        }

        $session->remove('pending_email_to_send');
        $session->remove('pending_email_missing_body');

        $result = [
            'success' => true,
            'intent' => 'EMAIL_ACTION',
            'mode' => 'EMAIL_CANCELLED',
            'message' => 'Email preparation cancelled.',
            'speech' => 'Email preparation cancelled.',
        ];

        $aiCommandLogService->log($spokenText, $result, $userId);

        return $result;
    }

    private function completePendingEmailBodyCommand(
        string $spokenText,
        string $language,
        RequestStack $requestStack,
        AiCommandLogService $aiCommandLogService,
        ?int $userId
    ): ?array {
        $session = $requestStack->getSession();
        $pendingDraft = $session->get('pending_email_missing_body');

        if (!is_array($pendingDraft)) {
            return null;
        }

        $text = trim($spokenText);
        $normalized = mb_strtolower($text);

        if ($text === '') {
            return null;
        }

        if ($this->looksLikeEmailCancellation($normalized)) {
            $session->remove('pending_email_missing_body');
            $session->remove('pending_email_to_send');

            $result = [
                'success' => true,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_CANCELLED',
                'message' => 'Email preparation cancelled.',
                'speech' => 'Email preparation cancelled.',
            ];

            $aiCommandLogService->log($spokenText, $result, $userId);

            return $result;
        }

        if ($this->looksLikeEmailCommand($normalized) && !$this->looksLikeEmailConfirmation($normalized)) {
            $session->remove('pending_email_missing_body');
            return null;
        }

        if ($this->looksLikeEmailConfirmation($normalized)) {
            $result = [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_MISSING_BODY',
                'message' => 'Please say the email message first. What should the email say?',
                'speech' => 'Please say the email message first. What should the email say?',
                'requiresEmailBody' => true,
            ];

            $aiCommandLogService->log($spokenText, $result, $userId);

            return $result;
        }

        $to = $this->normalizeSpokenEmailAddress((string) ($pendingDraft['to'] ?? ''));
        $subject = trim((string) ($pendingDraft['subject'] ?? 'Message from voice assistant'));
        $body = $this->cleanEmailBodyText($text);

        if ($subject === '') {
            $subject = $this->generateEmailSubject($body);
        }

        $session->remove('pending_email_missing_body');
        $session->set('pending_email_to_send', [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ]);

        $result = [
            'success' => true,
            'intent' => 'EMAIL_ACTION',
            'mode' => 'EMAIL_PREPARED_NEEDS_CONFIRMATION',
            'requiresConfirmation' => true,
            'message' => sprintf(
                'I prepared an email to %s with subject "%s". Say confirm send email to send it.',
                $to,
                $subject
            ),
            'speech' => sprintf(
                'I prepared an email to %s. Say confirm send email to send it.',
                $to
            ),
            'emailPreview' => [
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
            ],
        ];

        $aiCommandLogService->log($spokenText, $result, $userId);

        return $result;
    }

    private function handleEmailAction(
        array $llmResult,
        string $spokenText,
        ?int $userId,
        RequestStack $requestStack,
        AiCommandLogService $aiCommandLogService,
        GmailService $gmailService
    ): array {
        $session = $requestStack->getSession();
        $action = strtoupper((string) ($llmResult['action'] ?? ''));

        if ($action !== 'SEND_PENDING_EMAIL') {
            $session->remove('pending_email_to_send');
        }

        if ($action === 'SEND_PENDING_EMAIL') {
            $pendingEmail = $session->get('pending_email_to_send');

            if (!is_array($pendingEmail)) {
                $result = [
                    'success' => false,
                    'intent' => 'EMAIL_ACTION',
                    'mode' => 'EMAIL_NO_PENDING_MESSAGE',
                    'message' => 'There is no pending email to send.',
                    'speech' => 'There is no pending email to send.',
                ];

                $aiCommandLogService->log($spokenText, $result, $userId);

                return $result;
            }

            $sendResult = $gmailService->sendEmail(
                $pendingEmail['to'],
                $pendingEmail['subject'],
                $pendingEmail['body']
            );

            if (($sendResult['success'] ?? false) === true) {
                $session->remove('pending_email_to_send');
            }

            $result = [
                'success' => $sendResult['success'] ?? false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_SEND_CONFIRMED',
                'message' => $sendResult['message'] ?? 'Email send attempted.',
                'speech' => $sendResult['message'] ?? 'Email send attempted.',
                'connectUrl' => $sendResult['connectUrl'] ?? null,
            ];

            $aiCommandLogService->log($spokenText, $result, $userId);

            return $result;
        }

        $email = $llmResult['email'] ?? null;

        if (!is_array($email)) {
            $result = [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_MISSING_DETAILS',
                'message' => 'I need the recipient and message before preparing an email.',
                'speech' => 'I need the recipient and message before preparing an email.',
            ];

            $aiCommandLogService->log($spokenText, $result, $userId);

            return $result;
        }

        $to = $this->normalizeSpokenEmailAddress((string) ($email['to'] ?? ''));
        $subject = trim((string) ($email['subject'] ?? ''));
        $body = $this->cleanEmailBodyText((string) ($email['body'] ?? ''));

        if ($to === '') {
            $result = [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_MISSING_RECIPIENT',
                'message' => $llmResult['speech'] ?? 'Who should I send the email to? Please give me the email address.',
                'speech' => $llmResult['speech'] ?? 'Who should I send the email to? Please give me the email address.',
            ];

            $aiCommandLogService->log($spokenText, $result, $userId);

            return $result;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $result = [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_INVALID_RECIPIENT',
                'message' => 'The recipient email address is not valid.',
                'speech' => 'The recipient email address is not valid. Please try again. Say it like name at gmail dot com.',
                'emailPreview' => [
                    'to' => $to,
                    'subject' => $subject,
                    'body' => $body,
                ],
            ];

            $aiCommandLogService->log($spokenText, $result, $userId);

            return $result;
        }

        if ($body === '') {
            $session->set('pending_email_missing_body', [
                'to' => $to,
                'subject' => $subject !== '' ? $subject : 'Message from voice assistant',
            ]);

            $result = [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_MISSING_BODY',
                'message' => $llmResult['speech'] ?? 'What should the email say?',
                'speech' => $llmResult['speech'] ?? 'What should the email say?',
                'requiresEmailBody' => true,
                'emailPreview' => [
                    'to' => $to,
                    'subject' => $subject !== '' ? $subject : 'Message from voice assistant',
                    'body' => '',
                ],
            ];

            $aiCommandLogService->log($spokenText, $result, $userId);

            return $result;
        }

        if ($subject === '') {
            $subject = 'Message from voice assistant';
        }

        $session->set('pending_email_to_send', [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ]);

        $result = [
            'success' => true,
            'intent' => 'EMAIL_ACTION',
            'mode' => 'EMAIL_PREPARED_NEEDS_CONFIRMATION',
            'requiresConfirmation' => true,
            'message' => sprintf(
                'I prepared an email to %s with subject "%s". Say confirm send email to send it.',
                $to,
                $subject
            ),
            'speech' => sprintf(
                'I prepared an email to %s. Say confirm send email to send it.',
                $to
            ),
            'emailPreview' => [
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
            ],
        ];

        $aiCommandLogService->log($spokenText, $result, $userId);

        return $result;
    }

    private function getAllowedExternalActionsForLlm(): array
    {
        return [
            'websites' => [
                ['website' => 'youtube', 'name' => 'YouTube', 'actions' => ['open', 'search']],
                ['website' => 'google', 'name' => 'Google', 'actions' => ['open', 'search']],
                ['website' => 'facebook', 'name' => 'Facebook', 'actions' => ['open', 'search']],
                ['website' => 'gmail', 'name' => 'Gmail', 'actions' => ['open']],
            ],
            'browserActions' => [
                'CLICK',
                'TYPE',
                'READ_PAGE',
                'SUMMARIZE_PAGE',
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
                'SELECT_RESULT',
                'SWITCH_TO_ASSISTANT',
            ],
            'emailActions' => [
                'PREPARE_EMAIL',
                'SEND_PENDING_EMAIL',
            ],
        ];
    }

    private function tryLocalEmailCommand(string $spokenText, string $language): ?array
    {
        $text = mb_strtolower(trim($spokenText));

        if (!$this->looksLikeEmailCommand($text)) {
            return null;
        }

        if ($this->looksLikeEmailConfirmation($text)) {
            return [
                'intent' => 'EMAIL_ACTION',
                'website' => null,
                'action' => 'SEND_PENDING_EMAIL',
                'query' => null,
                'target' => null,
                'resultPosition' => null,
                'nextAction' => null,
                'email' => null,
                'confidence' => 0.95,
                'requiresExtension' => false,
                'speech' => $this->emailSpeech('Sending the pending email.', $language),
                'reason' => 'Local fallback recognized email confirmation.',
            ];
        }

        $emailAddress = $this->extractEmailAddressFromSpeech($spokenText);
        $body = $this->extractEmailBodyFromSpeech($spokenText);
        $subject = $body !== '' ? $this->generateEmailSubject($body) : 'Message from voice assistant';

        return [
            'intent' => 'EMAIL_ACTION',
            'website' => null,
            'action' => 'PREPARE_EMAIL',
            'query' => null,
            'target' => null,
            'resultPosition' => null,
            'nextAction' => null,
            'email' => [
                'to' => $emailAddress,
                'recipientName' => null,
                'subject' => $subject,
                'body' => $body,
                'requiresConfirmation' => true,
            ],
            'confidence' => 0.78,
            'requiresExtension' => false,
            'speech' => $emailAddress === ''
                ? $this->emailSpeech('I need the recipient email address. Say it like name at gmail dot com.', $language)
                : ($body === ''
                    ? $this->emailSpeech('What should the email say?', $language)
                    : $this->emailSpeech('I prepared the email. Say confirm send email to send it.', $language)),
            'reason' => 'Local fallback recognized email preparation command.',
        ];
    }

    private function tryLocalExternalCommand(string $spokenText, string $language, mixed $browserContext): ?array
    {
        $text = mb_strtolower(trim($spokenText));

        /*
         * 1. Browser/page commands first.
         * Never let memory convert "scroll down in Gmail" into "open Gmail".
         */
        $localBrowserAction = $this->detectLocalBrowserAction($text);

        if ($localBrowserAction !== null) {
            return $this->buildBrowserActionResult([
                'intent' => 'BROWSER_ACTION',
                'website' => $this->detectWebsiteFromBrowserContext($browserContext),
                'action' => $localBrowserAction,
                'query' => null,
                'target' => $this->targetForLocalBrowserAction($localBrowserAction),
                'resultPosition' => null,
                'nextAction' => null,
                'speech' => $this->localBrowserSpeech($localBrowserAction, $language),
                'confidence' => 0.92,
                'reason' => 'Local fallback recognized a browser page control command.',
            ], $language, $browserContext);
        }

        /*
         * 2. Email commands must not become website commands.
         */
        if ($this->looksLikeEmailCommand($text)) {
            return null;
        }

        /*
         * 3. Website open/search fallback.
         */
        $explicitWebsite = $this->detectWebsiteFromText($text);
        $contextWebsite = $this->detectWebsiteFromBrowserContext($browserContext);
        $website = $explicitWebsite;

        $isSearch = $this->looksLikeSearchCommand($text);

        if ($website === null && $isSearch) {
            $website = $contextWebsite;
        }

        if ($website === null) {
            return null;
        }

        if ($website === 'youtube' && $this->looksLikeOpenVideoByName($text)) {
            $videoTitle = $this->extractVideoTitleQuery($spokenText);

            if ($videoTitle !== '') {
                return $this->buildWebsiteActionResult([
                    'intent' => 'WEBSITE_ACTION',
                    'website' => 'youtube',
                    'action' => 'search',
                    'query' => $videoTitle,
                    'resultPosition' => null,
                    'nextAction' => [
                        'intent' => 'BROWSER_ACTION',
                        'action' => 'CLICK',
                        'target' => $videoTitle,
                        'resultPosition' => null,
                    ],
                    'speech' => $this->localSpeech(
                        'Searching YouTube for ' . $videoTitle . ', then I will open the matching video.',
                        $language,
                        ['website' => 'youtube', 'query' => $videoTitle, 'type' => 'open_video_by_name']
                    ),
                    'confidence' => 0.88,
                    'reason' => 'Local fallback recognized a YouTube video opening command by title.',
                ], $language, $browserContext);
            }
        }

        if ($website === 'gmail') {
            return $this->buildWebsiteActionResult([
                'intent' => 'WEBSITE_ACTION',
                'website' => 'gmail',
                'action' => 'open',
                'query' => null,
                'resultPosition' => null,
                'nextAction' => null,
                'speech' => $this->localSpeech(
                    'Opening Gmail.',
                    $language,
                    ['website' => 'gmail', 'type' => 'open']
                ),
                'confidence' => 0.86,
                'reason' => 'Local fallback recognized Gmail opening command.',
            ], $language, $browserContext);
        }

        if (!$isSearch) {
            if ($explicitWebsite === null) {
                return null;
            }

            return $this->buildWebsiteActionResult([
                'intent' => 'WEBSITE_ACTION',
                'website' => $website,
                'action' => 'open',
                'query' => null,
                'resultPosition' => null,
                'nextAction' => null,
                'speech' => $this->localSpeech(
                    'Opening ' . ucfirst($website) . '.',
                    $language,
                    ['website' => $website, 'type' => 'open']
                ),
                'confidence' => 0.86,
                'reason' => 'Local fallback recognized explicit website opening command.',
            ], $language, $browserContext);
        }

        $query = $this->extractLocalSearchQuery($spokenText);

        if ($query === '') {
            return null;
        }

        return $this->buildWebsiteActionResult([
            'intent' => 'WEBSITE_ACTION',
            'website' => $website,
            'action' => 'search',
            'query' => $query,
            'resultPosition' => null,
            'nextAction' => null,
            'speech' => $this->localSpeech(
                'Searching ' . ucfirst($website) . ' for ' . $query . '.',
                $language,
                ['website' => $website, 'query' => $query, 'type' => 'search']
            ),
            'confidence' => 0.84,
            'reason' => 'Local fallback recognized website search command.',
        ], $language, $browserContext);
    }

    private function looksLikeEmailCommand(string $text): bool
    {
        return (
            str_contains($text, 'send email') ||
            str_contains($text, 'send an email') ||
            str_contains($text, 'write email') ||
            str_contains($text, 'write an email') ||
            str_contains($text, 'compose email') ||
            str_contains($text, 'compose an email') ||
            str_starts_with($text, 'email ') ||
            str_contains($text, ' email ') ||
            str_contains($text, ' e-mail ') ||
            str_contains($text, 'confirm send email') ||
            str_contains($text, 'send the pending email') ||
            str_contains($text, 'yes send it') ||
            str_contains($text, 'confirm it') ||
            $text === 'confirm' ||
            $text === 'i confirm' ||
            str_contains($text, 'i confirm send it') ||
            str_contains($text, 'confirm send it') ||
            str_contains($text, 'send it now') ||
            $text === 'send it' ||
            str_contains($text, 'envoyer un email') ||
            str_contains($text, 'envoie un email') ||
            str_contains($text, 'ecris un email') ||
            str_contains($text, 'écris un email') ||
            str_contains($text, 'confirme l envoi') ||
            str_contains($text, 'اكتب ايميل') ||
            str_contains($text, 'ارسل ايميل') ||
            str_contains($text, 'ابعث ايميل')
        );
    }

    private function looksLikeEmailCancellation(string $text): bool
    {
        return (
            str_contains($text, 'cancel') ||
            str_contains($text, 'do not send') ||
            str_contains($text, "don't send") ||
            str_contains($text, 'dont send') ||
            str_contains($text, 'not confirming') ||
            str_contains($text, 'no confirm') ||
            str_contains($text, 'no i am not confirming') ||
            str_contains($text, "no i'm not confirming") ||
            str_contains($text, 'stop email') ||
            str_contains($text, 'annuler') ||
            str_contains($text, 'ne pas envoyer') ||
            str_contains($text, 'الغاء') ||
            str_contains($text, 'إلغاء') ||
            str_contains($text, 'لا ترسل')
        );
    }

    private function looksLikeEmailConfirmation(string $text): bool
    {
        return (
            str_contains($text, 'confirm send email') ||
            str_contains($text, 'send the pending email') ||
            str_contains($text, 'yes send it') ||
            str_contains($text, 'confirm it') ||
            $text === 'confirm' ||
            $text === 'i confirm' ||
            str_contains($text, 'i confirm send it') ||
            str_contains($text, 'confirm send it') ||
            str_contains($text, 'send it now') ||
            $text === 'send it' ||
            str_contains($text, 'confirme l envoi') ||
            str_contains($text, 'ارسل الرسالة') ||
            str_contains($text, 'ابعث الرسالة')
        );
    }

    private function extractEmailAddressFromSpeech(string $spokenText): string
    {
        $text = trim($spokenText);

        $patterns = [
            '/send\s+an\s+email\s+to\s+/i',
            '/send\s+email\s+to\s+/i',
            '/write\s+an\s+email\s+to\s+/i',
            '/write\s+email\s+to\s+/i',
            '/compose\s+an\s+email\s+to\s+/i',
            '/compose\s+email\s+to\s+/i',
            '/email\s+/i',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text, 1);
        }

        $parts = preg_split('/\s+saying\s+|\s+that\s+says\s+|\s+message\s+|\s+body\s+/i', (string) $text);
        $candidate = trim((string) ($parts[0] ?? ''));
        $candidate = preg_replace('/\s+(?:to\s+)?gmail\s+a\s+gmail\.com$/i', ' gmail.com', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s+(?:to\s+)?gmail\s+at\s+gmail\.com$/i', ' gmail.com', $candidate) ?? $candidate;

        return $this->normalizeSpokenEmailAddress($candidate);
    }

    private function normalizeSpokenEmailAddress(string $value): string
    {
        $email = mb_strtolower(trim($value));
        $email = preg_replace('/\b(?:to\s+)?gmail\s+a\s+gmail\.com\b/u', ' gmail.com', $email) ?? $email;
        $email = preg_replace('/\b(?:to\s+)?gmail\s+at\s+gmail\.com\b/u', ' gmail.com', $email) ?? $email;

        $email = str_replace([' at ', ' arobase ', ' underscore ', ' dash ', ' hyphen '], ['@', '@', '_', '-', '-'], $email);
        $email = str_replace([' dot ', ' point ', ' period '], '.', $email);
        $email = preg_replace('/\s+/', '', $email);
        $email = str_replace(['،', ','], '.', (string) $email);

        /*
         * Handle common speech case:
         * "kimo abdullah gmail.com" -> "kimoabdullah@gmail.com"
         */
        if (!str_contains($email, '@')) {
            $providers = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com'];

            foreach ($providers as $provider) {
                if (str_ends_with($email, $provider)) {
                    $local = substr($email, 0, -strlen($provider));
                    $local = trim($local, '.');
                    $local = preg_replace('/(?:to)?gmaila$/', '', $local) ?? $local;

                    if ($local !== '') {
                        return $local . '@' . $provider;
                    }
                }
            }
        }

        return $email;
    }

    private function extractEmailBodyFromSpeech(string $spokenText): string
    {
        $parts = preg_split('/\s+saying\s+|\s+that\s+says\s+|\s+message\s+|\s+body\s+/i', $spokenText, 2);

        if (!is_array($parts) || count($parts) < 2) {
            return '';
        }

        return $this->cleanEmailBodyText((string) $parts[1]);
    }

    private function cleanEmailBodyText(string $body): string
    {
        $body = trim($body);
        $body = preg_replace('/^(please\s+)?(?:say|saying|write|tell\s+them|message\s+is|body\s+is)\s+/i', '', $body) ?? $body;

        return trim($body);
    }

    private function generateEmailSubject(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            return 'Message from voice assistant';
        }

        $words = preg_split('/\s+/', $body);
        $short = implode(' ', array_slice($words ?: [], 0, 5));

        return ucfirst($short);
    }

    private function emailSpeech(string $englishSpeech, string $language): string
    {
        if ($language === 'fr-FR') {
            return match ($englishSpeech) {
                'I need the recipient email address. Say it like name at gmail dot com.' => 'J’ai besoin de l’adresse email du destinataire. Dites-la comme nom arobase gmail point com.',
                'What should the email say?' => 'Que doit dire l’email ?',
                'I prepared the email. Say confirm send email to send it.' => 'J’ai préparé l’email. Dites confirmer l’envoi pour l’envoyer.',
                'Sending the pending email.' => 'J’envoie l’email en attente.',
                default => $englishSpeech,
            };
        }

        if ($language === 'ar-SA' || $language === 'ar-TN') {
            return match ($englishSpeech) {
                'I need the recipient email address. Say it like name at gmail dot com.' => 'أحتاج إلى عنوان البريد الإلكتروني للمستلم.',
                'What should the email say?' => 'ماذا يجب أن أكتب في البريد؟',
                'I prepared the email. Say confirm send email to send it.' => 'حضّرت البريد الإلكتروني. قل تأكيد الإرسال لإرساله.',
                'Sending the pending email.' => 'سأرسل البريد الإلكتروني المعلّق.',
                default => $englishSpeech,
            };
        }

        return $englishSpeech;
    }

    private function detectWebsiteFromText(string $text): ?string
    {
        if (str_contains($text, 'youtube') || str_contains($text, 'you tube') || str_contains($text, 'يوتيوب')) {
            return 'youtube';
        }

        if (str_contains($text, 'google') || str_contains($text, 'جوجل') || str_contains($text, 'غوغل')) {
            return 'google';
        }

        if (str_contains($text, 'facebook') || str_contains($text, 'فيسبوك')) {
            return 'facebook';
        }

        if (str_contains($text, 'gmail') || str_contains($text, 'جيميل')) {
            return 'gmail';
        }

        return null;
    }

    private function detectWebsiteFromBrowserContext(mixed $browserContext): ?string
    {
        if (!is_array($browserContext)) {
            return null;
        }

        $website = strtolower((string) ($browserContext['website'] ?? ''));

        if ($website === '' && isset($browserContext['memory']) && is_array($browserContext['memory'])) {
            $website = strtolower((string) ($browserContext['memory']['activeWebsite'] ?? ''));
        }

        if (str_contains($website, 'youtube') || $website === 'youtube') {
            return 'youtube';
        }

        if (str_contains($website, 'google') || $website === 'google') {
            return 'google';
        }

        if (str_contains($website, 'facebook') || $website === 'facebook') {
            return 'facebook';
        }

        if (str_contains($website, 'gmail') || str_contains($website, 'mail.google') || $website === 'gmail') {
            return 'gmail';
        }

        return null;
    }

    private function detectLocalBrowserAction(string $text): ?string
    {
        if (str_contains($text, 'scroll down') || str_contains($text, 'go down') || str_contains($text, 'show me more') || str_contains($text, 'move down') || str_contains($text, 'descends') || str_contains($text, 'descendre') || str_contains($text, 'انزل')) {
            return 'SCROLL_DOWN';
        }

        if (str_contains($text, 'scroll up') || str_contains($text, 'go up') || str_contains($text, 'move up') || str_contains($text, 'monte') || str_contains($text, 'monter') || str_contains($text, 'اطلع')) {
            return 'SCROLL_UP';
        }

        if (str_contains($text, 'pause') || str_contains($text, 'pause video') || str_contains($text, 'stop video') || str_contains($text, 'stop this') || str_contains($text, 'hold on') || str_contains($text, 'wait a second') || str_contains($text, 'mets pause') || str_contains($text, 'met pause') || str_contains($text, 'وقف الفيديو') || str_contains($text, 'حبس الفيديو')) {
            return 'PAUSE_VIDEO';
        }

        if (str_contains($text, 'play') || str_contains($text, 'resume') || str_contains($text, 'continue') || str_contains($text, 'start video') || str_contains($text, 'joue la video') || str_contains($text, 'joue la vidéo') || str_contains($text, 'lance la video') || str_contains($text, 'lance la vidéo') || str_contains($text, 'شغل الفيديو') || str_contains($text, 'كمل الفيديو')) {
            return 'PLAY_VIDEO';
        }

        if (str_contains($text, 'stop muting') || str_contains($text, 'stop the mute') || str_contains($text, 'unmute') || str_contains($text, 'sound on') || str_contains($text, 'turn sound on') || str_contains($text, 'enable sound') || str_contains($text, 'remets le son') || str_contains($text, 'remet le son') || str_contains($text, 'active le son') || str_contains($text, 'activer le son') || str_contains($text, 'شغل الصوت') || str_contains($text, 'رجع الصوت')) {
            return 'UNMUTE';
        }

        if (str_contains($text, 'mute') || str_contains($text, 'sound off') || str_contains($text, 'turn sound off') || str_contains($text, 'disable sound') || str_contains($text, 'coupe le son') || str_contains($text, 'couper le son') || str_contains($text, 'désactive le son') || str_contains($text, 'desactive le son') || str_contains($text, 'كتم الصوت') || str_contains($text, 'اطفي الصوت')) {
            return 'MUTE';
        }

        if (str_contains($text, 'go back') || str_contains($text, 'previous page') || str_contains($text, 'back page') || str_contains($text, 'retour') || str_contains($text, 'رجوع') || str_contains($text, 'ارجع')) {
            return 'GO_BACK';
        }

        if (str_contains($text, 'read page') || str_contains($text, 'read this page') || str_contains($text, 'lis cette page') || str_contains($text, 'lire cette page') || str_contains($text, 'اقرأ')) {
            return 'READ_PAGE';
        }

        if (str_contains($text, 'summarize page') || str_contains($text, 'summarize this page') || str_contains($text, 'resume page') || str_contains($text, 'résume cette page') || str_contains($text, 'لخص')) {
            return 'SUMMARIZE_PAGE';
        }

        if (str_contains($text, 'return to assistant') || str_contains($text, 'back to assistant') || str_contains($text, 'voice assistant') || str_contains($text, 'where i talk to you') || str_contains($text, 'رجعني للمساعد')) {
            return 'SWITCH_TO_ASSISTANT';
        }

        return null;
    }

    private function targetForLocalBrowserAction(string $action): ?string
    {
        return match ($action) {
            'PLAY_VIDEO', 'PAUSE_VIDEO', 'MUTE', 'UNMUTE' => 'current video',
            'SCROLL_DOWN', 'SCROLL_UP', 'READ_PAGE', 'SUMMARIZE_PAGE' => 'current page',
            'GO_BACK' => 'browser history',
            'SWITCH_TO_ASSISTANT' => 'voice assistant tab',
            default => null,
        };
    }

    private function localBrowserSpeech(string $action, string $language): string
    {
        if ($language === 'fr-FR') {
            return match ($action) {
                'SCROLL_DOWN' => 'Je fais défiler la page vers le bas.',
                'SCROLL_UP' => 'Je fais défiler la page vers le haut.',
                'PAUSE_VIDEO' => 'Je mets la vidéo en pause.',
                'PLAY_VIDEO' => 'Je lance la vidéo.',
                'MUTE' => 'Je coupe le son.',
                'UNMUTE' => 'Je remets le son.',
                'GO_BACK' => 'Je retourne à la page précédente.',
                'READ_PAGE' => 'Je lis la page.',
                'SUMMARIZE_PAGE' => 'Je résume la page.',
                'SWITCH_TO_ASSISTANT' => 'Je retourne à l’assistant vocal.',
                default => 'J’exécute l’action dans le navigateur.',
            };
        }

        if ($language === 'ar-SA' || $language === 'ar-TN') {
            return match ($action) {
                'SCROLL_DOWN' => 'سأمرر الصفحة إلى الأسفل.',
                'SCROLL_UP' => 'سأمرر الصفحة إلى الأعلى.',
                'PAUSE_VIDEO' => 'سأوقف الفيديو مؤقتاً.',
                'PLAY_VIDEO' => 'سأشغل الفيديو.',
                'MUTE' => 'سأكتم الصوت.',
                'UNMUTE' => 'سأعيد تشغيل الصوت.',
                'GO_BACK' => 'سأرجع إلى الصفحة السابقة.',
                'READ_PAGE' => 'سأقرأ الصفحة.',
                'SUMMARIZE_PAGE' => 'سألخص الصفحة.',
                'SWITCH_TO_ASSISTANT' => 'سأعود إلى المساعد الصوتي.',
                default => 'سأنفذ الأمر في المتصفح.',
            };
        }

        return match ($action) {
            'SCROLL_DOWN' => 'Scrolling down.',
            'SCROLL_UP' => 'Scrolling up.',
            'PAUSE_VIDEO' => 'Pausing the video.',
            'PLAY_VIDEO' => 'Playing the video.',
            'MUTE' => 'Muting the video.',
            'UNMUTE' => 'Turning the sound back on.',
            'GO_BACK' => 'Going back.',
            'READ_PAGE' => 'Reading the page.',
            'SUMMARIZE_PAGE' => 'Summarizing the page.',
            'SWITCH_TO_ASSISTANT' => 'Returning to the voice assistant.',
            default => 'Executing browser action.',
        };
    }

    private function looksLikeSearchCommand(string $text): bool
    {
        return (
            str_contains($text, 'search') ||
            str_contains($text, 'find') ||
            str_contains($text, 'look for') ||
            str_contains($text, 'chercher') ||
            str_contains($text, 'cherche') ||
            str_contains($text, 'recherche') ||
            str_contains($text, 'trouve') ||
            str_contains($text, 'ابحث') ||
            str_contains($text, 'دور') ||
            str_contains($text, 'فتش') ||
            str_contains($text, 'song') ||
            str_contains($text, 'songs') ||
            str_contains($text, 'music') ||
            str_contains($text, 'musique') ||
            str_contains($text, 'chanson') ||
            str_contains($text, 'chansons')
        );
    }

    private function looksLikeOpenVideoByName(string $text): bool
    {
        return (
            str_contains($text, 'open the video') ||
            str_contains($text, 'open video') ||
            str_contains($text, 'play the video') ||
            str_contains($text, 'play video') ||
            str_contains($text, 'video named') ||
            str_contains($text, 'video called') ||
            (str_contains($text, 'open') && str_contains($text, 'youtube')) ||
            (str_contains($text, 'play') && str_contains($text, 'youtube')) ||
            str_contains($text, 'ouvre la video') ||
            str_contains($text, 'ouvre la vidéo') ||
            str_contains($text, 'joue la video') ||
            str_contains($text, 'joue la vidéo')
        );
    }

    private function extractVideoTitleQuery(string $spokenText): string
    {
        $query = trim($spokenText);

        $patterns = [
            '/open\s+the\s+video\s+named\s+/i',
            '/open\s+the\s+video\s+called\s+/i',
            '/open\s+video\s+named\s+/i',
            '/open\s+video\s+called\s+/i',
            '/play\s+the\s+video\s+named\s+/i',
            '/play\s+the\s+video\s+called\s+/i',
            '/play\s+video\s+named\s+/i',
            '/play\s+video\s+called\s+/i',
            '/open\s+/i',
            '/play\s+/i',
            '/ouvre\s+la\s+vid[eé]o\s+/i',
            '/joue\s+la\s+vid[eé]o\s+/i',
            '/on\s+youtube/i',
            '/in\s+youtube/i',
            '/dans\s+youtube/i',
            '/sur\s+youtube/i',
            '/youtube/i',
        ];

        foreach ($patterns as $pattern) {
            $query = preg_replace($pattern, '', $query);
        }

        return trim((string) $query);
    }

    private function extractLocalSearchQuery(string $spokenText): string
    {
        $query = trim($spokenText);

        $patterns = [
            '/search\s+youtube\s+for\s+/i',
            '/search\s+google\s+for\s+/i',
            '/search\s+facebook\s+for\s+/i',
            '/search\s+for\s+/i',
            '/search\s+/i',
            '/find\s+/i',
            '/look\s+for\s+/i',
            '/chercher\s+/i',
            '/cherche\s+/i',
            '/recherche\s+/i',
            '/trouve\s+/i',
            '/songs?\s+/i',
            '/music\s+/i',
            '/musique\s+/i',
            '/chansons?\s+/i',
            '/on\s+youtube/i',
            '/in\s+youtube/i',
            '/dans\s+youtube/i',
            '/sur\s+youtube/i',
            '/on\s+google/i',
            '/sur\s+google/i',
            '/on\s+facebook/i',
            '/sur\s+facebook/i',
            '/youtube/i',
            '/google/i',
            '/facebook/i',
        ];

        foreach ($patterns as $pattern) {
            $query = preg_replace($pattern, '', $query);
        }

        return trim((string) $query);
    }

    private function localSpeech(string $englishSpeech, string $language, array $context = []): string
    {
        $website = $context['website'] ?? null;
        $query = $context['query'] ?? null;
        $type = $context['type'] ?? null;

        if ($language === 'fr-FR') {
            if ($type === 'open_video_by_name' && $query !== null) {
                return sprintf('Je cherche %s sur YouTube, puis j’ouvre la vidéo correspondante.', $query);
            }

            if ($type === 'search' && $website !== null && $query !== null) {
                return sprintf('Je cherche %s sur %s.', $query, ucfirst($website));
            }

            if ($type === 'open' && $website !== null) {
                return sprintf('J’ouvre %s.', ucfirst($website));
            }
        }

        if ($language === 'ar-SA' || $language === 'ar-TN') {
            if ($type === 'open_video_by_name' && $query !== null) {
                return sprintf('سأبحث عن %s في YouTube ثم أفتح الفيديو المطابق.', $query);
            }

            if ($type === 'search' && $website !== null && $query !== null) {
                return sprintf('سأبحث عن %s في %s.', $query, ucfirst($website));
            }

            if ($type === 'open' && $website !== null) {
                return sprintf('سأفتح %s.', ucfirst($website));
            }
        }

        return $englishSpeech;
    }

    private function buildExternalWebsiteUrl(string $website, string $action, ?string $query): ?string
    {
        $website = strtolower($website);
        $action = strtolower($action);
        $query = trim((string) $query);

        if ($action === 'search' && $query !== '') {
            return match ($website) {
                'youtube' => 'https://www.youtube.com/results?search_query=' . rawurlencode($query),
                'google' => 'https://www.google.com/search?q=' . rawurlencode($query),
                'facebook' => 'https://www.facebook.com/search/top?q=' . rawurlencode($query),
                default => null,
            };
        }

        return match ($website) {
            'youtube' => 'https://www.youtube.com',
            'google' => 'https://www.google.com',
            'facebook' => 'https://www.facebook.com',
            'gmail' => 'https://mail.google.com',
            default => null,
        };
    }

    private function normalizeResultPosition(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $position = (int) $value;

        if ($position < 1 || $position > 10) {
            return null;
        }

        return $position;
    }

    private function normalizeBrowserAction(?string $action): ?string
    {
        if ($action === null || trim($action) === '') {
            return null;
        }

        return strtoupper(trim($action));
    }

    private function normalizeWebsiteActionForExtension(?string $action): ?string
    {
        if ($action === null || trim($action) === '') {
            return null;
        }

        $normalized = strtolower(trim($action));

        return match ($normalized) {
            'open', 'open_url', 'open-url' => 'OPEN_URL',
            'search', 'search_url', 'search-url' => 'SEARCH',
            default => strtoupper($normalized),
        };
    }

    private function normalizeNextAction(mixed $nextAction): ?array
    {
        if (!is_array($nextAction)) {
            return null;
        }

        $action = $this->normalizeBrowserAction($nextAction['action'] ?? null);

        if ($action === null) {
            return null;
        }

        return [
            'intent' => $nextAction['intent'] ?? 'BROWSER_ACTION',
            'action' => $action,
            'target' => $nextAction['target'] ?? null,
            'resultPosition' => $this->normalizeResultPosition($nextAction['resultPosition'] ?? null),
        ];
    }
}