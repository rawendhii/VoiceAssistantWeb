<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

class VoiceEmailCommandService
{
    private const SESSION_PENDING_EMAIL_KEY = 'voice_pending_email';

    public function __construct(
        private readonly GmailService $gmailService,
        private readonly RequestStack $requestStack
    ) {
    }

    public function handle(string $text): ?array
    {
        $text = trim($text);
        $normalized = $this->normalizeText($text);

        if ($text === '') {
            return null;
        }

        if ($this->isCancelCommand($normalized)) {
            $this->clearPendingEmail();

            return [
                'success' => true,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_CANCELLED',
                'requiresExtension' => false,
                'message' => 'Email cancelled.',
                'speech' => 'Email cancelled.',
            ];
        }

        if ($this->isConfirmSendCommand($normalized)) {
            return $this->sendPendingEmail();
        }

        if ($this->isRepeatPendingEmailCommand($normalized)) {
            return $this->repeatPendingEmail();
        }

        if ($this->isChangeRecipientCommand($normalized)) {
            return $this->changePendingRecipient($text);
        }

        if ($this->isChangeBodyCommand($normalized)) {
            return $this->changePendingBody($text);
        }

        /*
         * Important:
         * If the user starts a NEW email while another draft is pending,
         * replace the old draft instead of treating the sentence as the old email body.
         */
        if ($this->looksLikeEmailCommand($normalized)) {
            return $this->startOrReplaceEmailDraft($text);
        }

        /*
         * If we are waiting for the email body, treat the next normal sentence
         * as the body instead of sending it to Gemini chat.
         */
        $pendingEmail = $this->getPendingEmail();

        if (
            is_array($pendingEmail) &&
            ($pendingEmail['status'] ?? null) === 'WAITING_FOR_BODY'
        ) {
            return $this->completePendingEmailBody($text, $pendingEmail);
        }

        return null;
    }

    private function startOrReplaceEmailDraft(string $text): array
    {
        $parsedEmail = $this->parseEmailCommand($text);

        if (($parsedEmail['success'] ?? false) !== true) {
            return [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_PARSE_FAILED',
                'requiresExtension' => false,
                'message' => $parsedEmail['message'] ?? 'I could not understand the email command.',
                'speech' => $parsedEmail['message'] ?? 'I could not understand the email command.',
            ];
        }

        if (($parsedEmail['needsBody'] ?? false) === true) {
            $pendingEmail = [
                'status' => 'WAITING_FOR_BODY',
                'to' => $parsedEmail['to'],
                'subject' => $parsedEmail['subject'],
                'body' => null,
            ];

            $this->savePendingEmail($pendingEmail);

            return [
                'success' => true,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_WAITING_FOR_BODY',
                'requiresExtension' => false,
                'message' => 'What message should I write to ' . $pendingEmail['to'] . '?',
                'speech' => 'What message should I write to ' . $pendingEmail['to'] . '?',
                'email' => [
                    'to' => $pendingEmail['to'],
                    'subject' => $pendingEmail['subject'],
                ],
            ];
        }

        $pendingEmail = [
            'status' => 'READY_TO_SEND',
            'to' => $parsedEmail['to'],
            'subject' => $parsedEmail['subject'],
            'body' => $parsedEmail['body'],
        ];

        $this->savePendingEmail($pendingEmail);

        return [
            'success' => true,
            'intent' => 'EMAIL_ACTION',
            'mode' => 'EMAIL_CONFIRMATION_REQUIRED',
            'requiresExtension' => false,
            'message' => $this->buildPreparedMessage($pendingEmail),
            'speech' => $this->buildPreparedSpeech($pendingEmail),
            'email' => [
                'to' => $pendingEmail['to'],
                'subject' => $pendingEmail['subject'],
                'bodyPreview' => mb_substr($pendingEmail['body'], 0, 180),
            ],
        ];
    }

    private function completePendingEmailBody(string $text, array $pendingEmail): array
    {
        $body = $this->cleanBodyPrefix($text);

        if ($body === '') {
            return [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_BODY_EMPTY',
                'requiresExtension' => false,
                'message' => 'Please tell me the message you want to write in the email.',
                'speech' => 'Please tell me the message you want to write in the email.',
            ];
        }

        $pendingEmail['status'] = 'READY_TO_SEND';
        $pendingEmail['body'] = $body;

        $this->savePendingEmail($pendingEmail);

        return [
            'success' => true,
            'intent' => 'EMAIL_ACTION',
            'mode' => 'EMAIL_CONFIRMATION_REQUIRED',
            'requiresExtension' => false,
            'message' => $this->buildPreparedMessage($pendingEmail),
            'speech' => $this->buildPreparedSpeech($pendingEmail),
            'email' => [
                'to' => $pendingEmail['to'],
                'subject' => $pendingEmail['subject'],
                'bodyPreview' => mb_substr($pendingEmail['body'], 0, 180),
            ],
        ];
    }

    private function repeatPendingEmail(): array
    {
        $pendingEmail = $this->getPendingEmail();

        if (!is_array($pendingEmail)) {
            return [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'NO_PENDING_EMAIL',
                'requiresExtension' => false,
                'message' => 'There is no email draft to repeat.',
                'speech' => 'There is no email draft to repeat.',
            ];
        }

        $to = (string) ($pendingEmail['to'] ?? '');
        $subject = (string) ($pendingEmail['subject'] ?? 'Message from Voice Assistant');
        $body = (string) ($pendingEmail['body'] ?? '');

        if (($pendingEmail['status'] ?? null) === 'WAITING_FOR_BODY') {
            return [
                'success' => true,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_REPEAT_WAITING_FOR_BODY',
                'requiresExtension' => false,
                'message' => 'The email is addressed to ' . $to . ', but I still need the message.',
                'speech' => 'The email is addressed to ' . $to . ', but I still need the message. What should I write?',
                'email' => [
                    'to' => $to,
                    'subject' => $subject,
                    'bodyPreview' => '',
                ],
            ];
        }

        return [
            'success' => true,
            'intent' => 'EMAIL_ACTION',
            'mode' => 'EMAIL_REPEATED',
            'requiresExtension' => false,
            'message' => 'Email draft. To: ' . $to . '. Subject: ' . $subject . '. Message: ' . $body,
            'speech' => 'The email is to ' . $to . '. The message says: ' . $body . '. Say confirm send to send it, change message, change recipient, or cancel email.',
            'email' => [
                'to' => $to,
                'subject' => $subject,
                'bodyPreview' => mb_substr($body, 0, 180),
            ],
        ];
    }

    private function changePendingRecipient(string $text): array
    {
        $pendingEmail = $this->getPendingEmail();

        if (!is_array($pendingEmail)) {
            return [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'NO_PENDING_EMAIL',
                'requiresExtension' => false,
                'message' => 'There is no email draft to update.',
                'speech' => 'There is no email draft to update.',
            ];
        }

        $convertedText = $this->convertSpokenEmailAddress($text);
        preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $convertedText, $emailMatches);

        $to = $emailMatches[0] ?? null;

        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_INVALID_NEW_RECIPIENT',
                'requiresExtension' => false,
                'message' => 'Please say the new recipient email address. For example: change recipient to abdullah at gmail dot com.',
                'speech' => 'Please say the new recipient email address. For example: change recipient to abdullah at gmail dot com.',
            ];
        }

        $pendingEmail['to'] = strtolower($to);

        $this->savePendingEmail($pendingEmail);

        return [
            'success' => true,
            'intent' => 'EMAIL_ACTION',
            'mode' => 'EMAIL_RECIPIENT_CHANGED',
            'requiresExtension' => false,
            'message' => 'Recipient changed to ' . $pendingEmail['to'] . '.',
            'speech' => 'Recipient changed to ' . $pendingEmail['to'] . '. Say confirm send to send it, or say repeat email to review it.',
            'email' => [
                'to' => $pendingEmail['to'],
                'subject' => $pendingEmail['subject'] ?? 'Message from Voice Assistant',
                'bodyPreview' => mb_substr((string) ($pendingEmail['body'] ?? ''), 0, 180),
            ],
        ];
    }

    private function changePendingBody(string $text): array
    {
        $pendingEmail = $this->getPendingEmail();

        if (!is_array($pendingEmail)) {
            return [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'NO_PENDING_EMAIL',
                'requiresExtension' => false,
                'message' => 'There is no email draft to update.',
                'speech' => 'There is no email draft to update.',
            ];
        }

        $body = trim($text);
        $body = preg_replace('/^(change|replace|update)\s+(the\s+)?(message|body|email)\s+(to|with)\s+/i', '', $body) ?? $body;
        $body = preg_replace('/^(message|body)\s+(is|to)\s+/i', '', $body) ?? $body;
        $body = trim($body);

        if ($body === '') {
            $pendingEmail['status'] = 'WAITING_FOR_BODY';
            $pendingEmail['body'] = null;
            $this->savePendingEmail($pendingEmail);

            return [
                'success' => true,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_WAITING_FOR_NEW_BODY',
                'requiresExtension' => false,
                'message' => 'What should the new email message be?',
                'speech' => 'What should the new email message be?',
            ];
        }

        $pendingEmail['status'] = 'READY_TO_SEND';
        $pendingEmail['body'] = $body;

        $this->savePendingEmail($pendingEmail);

        return [
            'success' => true,
            'intent' => 'EMAIL_ACTION',
            'mode' => 'EMAIL_BODY_CHANGED',
            'requiresExtension' => false,
            'message' => 'Email message changed. Say confirm send to send it, or repeat email to review it.',
            'speech' => 'Email message changed. Say confirm send to send it, or repeat email to review it.',
            'email' => [
                'to' => $pendingEmail['to'] ?? null,
                'subject' => $pendingEmail['subject'] ?? 'Message from Voice Assistant',
                'bodyPreview' => mb_substr($body, 0, 180),
            ],
        ];
    }

    private function sendPendingEmail(): array
    {
        $pendingEmail = $this->getPendingEmail();

        if (!is_array($pendingEmail)) {
            return [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'NO_PENDING_EMAIL',
                'requiresExtension' => false,
                'message' => 'There is no email waiting for confirmation.',
                'speech' => 'There is no email waiting for confirmation.',
            ];
        }

        if (($pendingEmail['status'] ?? null) === 'WAITING_FOR_BODY') {
            return [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_BODY_REQUIRED',
                'requiresExtension' => false,
                'message' => 'Please tell me the email message first.',
                'speech' => 'Please tell me the email message first.',
                'email' => [
                    'to' => $pendingEmail['to'] ?? null,
                    'subject' => $pendingEmail['subject'] ?? null,
                ],
            ];
        }

        $to = (string) ($pendingEmail['to'] ?? '');
        $subject = (string) ($pendingEmail['subject'] ?? 'Message from Voice Assistant');
        $body = (string) ($pendingEmail['body'] ?? '');

        if ($to === '' || $body === '') {
            return [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'EMAIL_INCOMPLETE',
                'requiresExtension' => false,
                'message' => 'The email is incomplete. Please start again.',
                'speech' => 'The email is incomplete. Please start again.',
            ];
        }

        if (!$this->gmailService->isConnected()) {
            return [
                'success' => false,
                'intent' => 'EMAIL_ACTION',
                'mode' => 'GMAIL_NOT_CONNECTED',
                'requiresExtension' => false,
                'message' => 'Gmail is not connected. Please connect Gmail first.',
                'speech' => 'Gmail is not connected. Please connect Gmail first.',
                'connectUrl' => '/google/oauth/connect',
                'frontendAction' => [
                    'type' => 'NAVIGATE',
                    'url' => '/google/oauth/connect',
                ],
            ];
        }

        $sendResult = $this->gmailService->sendEmail($to, $subject, $body);

        if (($sendResult['success'] ?? false) === true) {
            $this->clearPendingEmail();
        }

        return [
            'success' => (bool) ($sendResult['success'] ?? false),
            'intent' => 'EMAIL_ACTION',
            'mode' => ($sendResult['success'] ?? false) ? 'EMAIL_SENT' : 'EMAIL_SEND_FAILED',
            'requiresExtension' => false,
            'message' => $sendResult['message'] ?? 'Email action failed.',
            'speech' => $sendResult['message'] ?? 'Email action failed.',
            'gmailMessageId' => $sendResult['gmailMessageId'] ?? null,
            'connectUrl' => $sendResult['connectUrl'] ?? null,
        ];
    }

    private function parseEmailCommand(string $text): array
    {
        $convertedText = $this->convertSpokenEmailAddress($text);

        preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $convertedText, $emailMatches);

        $to = $emailMatches[0] ?? null;

        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Please say a valid email address. For example: send an email to abdullah at gmail dot com.',
            ];
        }

        $subject = 'Message from Voice Assistant';
        $body = '';

        if (preg_match('/\bsubject\s+(.+?)\s+(?:saying|message|body|that says|and say)\s+(.+)$/i', $convertedText, $matches)) {
            $subject = trim($matches[1]);
            $body = trim($matches[2]);
        } elseif (preg_match('/(?:saying|message|body|that says|and say)\s+(.+)$/i', $convertedText, $matches)) {
            $body = trim($matches[1]);
        } else {
            $afterEmail = trim(substr($convertedText, strpos($convertedText, $to) + strlen($to)));
            $body = trim($afterEmail);
        }

        $body = $this->cleanBodyPrefix($body);

        if ($body === '') {
            return [
                'success' => true,
                'needsBody' => true,
                'to' => strtolower($to),
                'subject' => $subject !== '' ? $subject : 'Message from Voice Assistant',
                'body' => null,
            ];
        }

        return [
            'success' => true,
            'needsBody' => false,
            'to' => strtolower($to),
            'subject' => $subject !== '' ? $subject : 'Message from Voice Assistant',
            'body' => $body,
        ];
    }

    private function looksLikeEmailCommand(string $normalized): bool
    {
        return (
            str_starts_with($normalized, 'send email') ||
            str_starts_with($normalized, 'send an email') ||
            str_starts_with($normalized, 'send a mail') ||
            str_starts_with($normalized, 'email ') ||
            str_starts_with($normalized, 'mail ') ||
            str_contains($normalized, 'send an email to') ||
            str_contains($normalized, 'send email to')
        );
    }

    private function isConfirmSendCommand(string $normalized): bool
    {
        return in_array($normalized, [
            'confirm',
            'confirm send',
            'confirm send email',
            'send it',
            'yes send it',
            'yes send',
            'send the email',
            'send email',
            'confirm email',
            'confirm the email',
            'send now',
        ], true);
    }

    private function isCancelCommand(string $normalized): bool
    {
        return in_array($normalized, [
            'cancel',
            'cancel email',
            'cancel the email',
            'do not send',
            'dont send',
            'don t send',
            'stop email',
            'delete email draft',
            'clear email',
        ], true);
    }

    private function isRepeatPendingEmailCommand(string $normalized): bool
    {
        return (
            $normalized === 'repeat email' ||
            $normalized === 'read email' ||
            $normalized === 'read the email' ||
            $normalized === 'review email' ||
            $normalized === 'review the email' ||
            $normalized === 'what did i say' ||
            $normalized === 'what did i tell you to send' ||
            $normalized === 'what am i sending' ||
            $normalized === 'repeat what i told you to send' ||
            str_contains($normalized, 'repeat what i told you') ||
            str_contains($normalized, 'repeat what i said') ||
            str_contains($normalized, 'can you repeat') ||
            str_contains($normalized, 'read it back') ||
            str_contains($normalized, 'read back')
        );
    }

    private function isChangeRecipientCommand(string $normalized): bool
    {
        return (
            str_starts_with($normalized, 'change recipient') ||
            str_starts_with($normalized, 'change the recipient') ||
            str_starts_with($normalized, 'replace recipient') ||
            str_starts_with($normalized, 'send it to') ||
            str_starts_with($normalized, 'send email to another') ||
            str_contains($normalized, 'wrong recipient') ||
            str_contains($normalized, 'wrong email address')
        );
    }

    private function isChangeBodyCommand(string $normalized): bool
    {
        return (
            str_starts_with($normalized, 'change message') ||
            str_starts_with($normalized, 'change the message') ||
            str_starts_with($normalized, 'replace message') ||
            str_starts_with($normalized, 'update message') ||
            str_starts_with($normalized, 'change body') ||
            str_starts_with($normalized, 'replace body') ||
            str_starts_with($normalized, 'message is') ||
            str_starts_with($normalized, 'body is')
        );
    }

    private function convertSpokenEmailAddress(string $text): string
    {
        $text = trim($text);

        $replacements = [
            '/\s+at\s+/i' => '@',
            '/\s+arobase\s+/i' => '@',
            '/\s+dot\s+/i' => '.',
            '/\s+point\s+/i' => '.',
            '/\s+period\s+/i' => '.',
            '/\s+underscore\s+/i' => '_',
            '/\s+dash\s+/i' => '-',
            '/\s+hyphen\s+/i' => '-',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        $text = preg_replace('/\s*@\s*/', '@', $text) ?? $text;
        $text = preg_replace('/\s*\.\s*/', '.', $text) ?? $text;

        return $text;
    }

    private function cleanBodyPrefix(string $body): string
    {
        $body = trim($body);
        $body = preg_replace('/^(saying|say|message|body|that says|and say)\s+/i', '', $body) ?? $body;

        return trim($body);
    }

    private function buildPreparedMessage(array $pendingEmail): string
    {
        return 'I prepared the email to ' . $pendingEmail['to'] . '. Say confirm send to send it, repeat email to review it, change message, change recipient, or cancel email.';
    }

    private function buildPreparedSpeech(array $pendingEmail): string
    {
        return 'I prepared the email to ' . $pendingEmail['to'] . '. Say confirm send to send it, or repeat email to review it.';
    }

    private function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}@._\-\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function savePendingEmail(array $email): void
    {
        $this->requestStack->getSession()->set(self::SESSION_PENDING_EMAIL_KEY, $email);
    }

    private function getPendingEmail(): ?array
    {
        $email = $this->requestStack->getSession()->get(self::SESSION_PENDING_EMAIL_KEY);

        return is_array($email) ? $email : null;
    }

    private function clearPendingEmail(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_PENDING_EMAIL_KEY);
    }
}