<?php

namespace App\Controller;

use Google\Client as GoogleClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GoogleOAuthController extends AbstractController
{
    public function __construct(
        private readonly string $googleClientId,
        private readonly string $googleClientSecret,
        private readonly string $googleRedirectUri
    ) {
    }

    #[Route('/google/oauth/connect', name: 'google_oauth_connect', methods: ['GET'])]
    public function connect(): RedirectResponse
    {
        $client = $this->createGoogleClient();

        return new RedirectResponse($client->createAuthUrl());
    }

    #[Route('/google/oauth/callback', name: 'google_oauth_callback', methods: ['GET'])]
    public function callback(Request $request, RequestStack $requestStack): Response
    {
        $code = $request->query->get('code');

        if (!$code) {
            return new Response(
                $this->buildHtmlResponse(
                    'Gmail connection failed',
                    'Google authorization code is missing. Please return to the assistant and try connecting Gmail again.',
                    false
                ),
                400
            );
        }

        $client = $this->createGoogleClient();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            return new Response(
                $this->buildHtmlResponse(
                    'Gmail connection failed',
                    $token['error_description'] ?? $token['error'],
                    false
                ),
                400
            );
        }

        $requestStack->getSession()->set('google_access_token', $token);

        return new Response(
            $this->buildHtmlResponse(
                'Gmail connected successfully',
                'Gmail is now connected. You can close this tab and return to the voice assistant.',
                true
            )
        );
    }

    #[Route('/google/oauth/status', name: 'google_oauth_status', methods: ['GET'])]
    public function status(RequestStack $requestStack): Response
    {
        $token = $requestStack->getSession()->get('google_access_token');

        $connected = is_array($token);

        return $this->json([
            'success' => true,
            'connected' => $connected,
            'message' => $connected ? 'Gmail is connected.' : 'Gmail is not connected.',
        ]);
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

    private function buildHtmlResponse(string $title, string $message, bool $success): string
    {
        $color = $success ? '#166534' : '#991b1b';
        $background = $success ? '#dcfce7' : '#fee2e2';
        $border = $success ? '#86efac' : '#fca5a5';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{$title}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            background: #f8fafc;
            font-family: Arial, sans-serif;
            color: #0f172a;
        }

        .card {
            width: min(560px, calc(100vw - 32px));
            padding: 28px;
            border-radius: 24px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.12);
            text-align: center;
        }

        .badge {
            display: inline-block;
            margin-bottom: 16px;
            padding: 9px 14px;
            border-radius: 999px;
            color: {$color};
            background: {$background};
            border: 1px solid {$border};
            font-weight: 800;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 28px;
        }

        p {
            margin: 0 0 22px;
            color: #475569;
            line-height: 1.6;
            font-size: 16px;
        }

        button {
            min-height: 48px;
            padding: 12px 18px;
            border: none;
            border-radius: 14px;
            background: #2563eb;
            color: white;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="badge">{$title}</div>
        <h1>{$title}</h1>
        <p>{$message}</p>
        <button onclick="window.close()">Close this tab</button>
    </main>
</body>
</html>
HTML;
    }
}