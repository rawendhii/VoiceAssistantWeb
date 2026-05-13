<?php

namespace App\Controller;

use App\Service\GmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GoogleOAuthController extends AbstractController
{
    public function __construct(
        private readonly GmailService $gmailService
    ) {
    }

    #[Route('/google/oauth/connect', name: 'google_oauth_connect')]
    public function connect(): RedirectResponse
    {
        return new RedirectResponse($this->gmailService->getConnectUrl());
    }

    #[Route('/google/oauth/callback', name: 'google_oauth_callback')]
    public function callback(Request $request): Response
    {
        $error = $request->query->get('error');
        $errorDescription = $request->query->get('error_description');

        if ($error) {
            return new Response(
                '<h1>Google OAuth error</h1>' .
                '<p><strong>Error:</strong> ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>' .
                '<p><strong>Description:</strong> ' . htmlspecialchars($errorDescription ?? 'No description provided.', ENT_QUOTES, 'UTF-8') . '</p>' .
                '<p><a href="/google/oauth/connect">Try connecting again</a></p>',
                400
            );
        }

        $code = $request->query->get('code');

        if (!$code) {
            return new Response(
                '<h1>Google did not return an authorization code.</h1>' .
                '<p>This usually happens because you opened the callback URL manually.</p>' .
                '<p>Do not open <code>/google/oauth/callback</code> directly.</p>' .
                '<p>Start the connection from here instead:</p>' .
                '<p><a href="/google/oauth/connect">Connect Gmail</a></p>' .
                '<hr>' .
                '<p><strong>Current query parameters:</strong></p>' .
                '<pre>' . htmlspecialchars(print_r($request->query->all(), true), ENT_QUOTES, 'UTF-8') . '</pre>',
                400
            );
        }

        $result = $this->gmailService->saveOAuthTokenFromCode($code);

        if (!$result['success']) {
            return new Response(
                '<h1>Gmail connection failed</h1>' .
                '<p>' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</p>' .
                '<p><strong>Error:</strong> ' . htmlspecialchars($result['error'] ?? 'Unknown error', ENT_QUOTES, 'UTF-8') . '</p>' .
                '<p><strong>Description:</strong> ' . htmlspecialchars($result['errorDescription'] ?? 'No description', ENT_QUOTES, 'UTF-8') . '</p>' .
                '<p><a href="/google/oauth/connect">Try connecting again</a></p>',
                400
            );
        }

        return $this->redirectToRoute('gmail_connected_success');
    }

    #[Route('/google/oauth/success', name: 'gmail_connected_success')]
    public function success(): Response
    {
        return new Response(
            '<h1>Gmail connected successfully ✅</h1>' .
            '<p>You can now close this page and ask the assistant to send emails.</p>'
        );
    }
}