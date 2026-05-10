<?php

namespace App\Controller;

use Google\Client as GoogleClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
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
    public function callback(RequestStack $requestStack): JsonResponse
    {
        $code = $_GET['code'] ?? null;

        if (!$code) {
            return $this->json([
                'success' => false,
                'message' => 'Google authorization code missing.',
            ], 400);
        }

        $client = $this->createGoogleClient();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            return $this->json([
                'success' => false,
                'message' => $token['error_description'] ?? $token['error'],
            ], 400);
        }

        $requestStack->getSession()->set('google_access_token', $token);

        return $this->json([
            'success' => true,
            'message' => 'Gmail connected successfully. You can close this tab and return to the assistant.',
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
}