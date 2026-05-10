<?php

namespace App\Service;

use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Symfony\Component\HttpFoundation\RequestStack;

class GmailService
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly string $googleClientId,
        private readonly string $googleClientSecret,
        private readonly string $googleRedirectUri
    ) {
    }

    public function isConnected(): bool
    {
        return is_array($this->getToken());
    }

    public function sendEmail(string $to, string $subject, string $body): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'The recipient email address is invalid.',
            ];
        }

        $token = $this->getToken();

        if (!is_array($token)) {
            return [
                'success' => false,
                'message' => 'Gmail is not connected. Please connect Gmail first.',
                'connectUrl' => '/google/oauth/connect',
            ];
        }

        $client = $this->createGoogleClient();
        $client->setAccessToken($token);

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $client->getRefreshToken();

            if (!$refreshToken && isset($token['refresh_token'])) {
                $refreshToken = $token['refresh_token'];
            }

            if (!$refreshToken) {
                return [
                    'success' => false,
                    'message' => 'Gmail session expired. Please reconnect Gmail.',
                    'connectUrl' => '/google/oauth/connect',
                ];
            }

            $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

            if (isset($newToken['error'])) {
                return [
                    'success' => false,
                    'message' => 'Could not refresh Gmail access. Please reconnect Gmail.',
                    'connectUrl' => '/google/oauth/connect',
                ];
            }

            $token = array_merge($token, $newToken);

            if (!isset($token['refresh_token'])) {
                $token['refresh_token'] = $refreshToken;
            }

            $this->saveToken($token);
            $client->setAccessToken($token);
        }

        $service = new Gmail($client);

        $rawMessage = $this->createRawMessage($to, $subject, $body);

        $message = new Message();
        $message->setRaw($rawMessage);

        $sentMessage = $service->users_messages->send('me', $message);

        return [
            'success' => true,
            'message' => 'Email sent successfully.',
            'gmailMessageId' => $sentMessage->getId(),
        ];
    }

    private function createRawMessage(string $to, string $subject, string $body): string
    {
        $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');

        $message = "To: {$to}\r\n";
        $message .= "Subject: {$encodedSubject}\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $body;

        return rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
    }

    private function createGoogleClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId($this->googleClientId);
        $client->setClientSecret($this->googleClientSecret);
        $client->setRedirectUri($this->googleRedirectUri);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->addScope('https://www.googleapis.com/auth/gmail.send');

        return $client;
    }

    private function getToken(): ?array
    {
        return $this->requestStack->getSession()->get('google_access_token');
    }

    private function saveToken(array $token): void
    {
        $this->requestStack->getSession()->set('google_access_token', $token);
    }
}