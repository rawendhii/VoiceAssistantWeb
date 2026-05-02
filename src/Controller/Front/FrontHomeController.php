<?php

namespace App\Controller\Front;

use App\Service\YouTubeSearchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FrontHomeController extends AbstractFrontController
{
    #[Route('/front/home', name: 'front_home', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessStrictUser();

        return $this->render('front/home/index.html.twig');
    }

    #[Route('/front/youtube-assistant', name: 'front_youtube_assistant', methods: ['GET'])]
    public function youtubeAssistant(Request $request, YouTubeSearchService $youtubeSearchService): Response
    {
        $this->denyUnlessStrictUser();

        $query = trim((string) $request->query->get('q', ''));

        /*
         * autoplay is used for compound commands like:
         * "search YouTube for cats and open the second video"
         *
         * /front/youtube-assistant?q=cats&autoplay=2
         */
        $autoplay = (int) $request->query->get('autoplay', 0);

        if ($autoplay < 0 || $autoplay > 10) {
            $autoplay = 0;
        }

        $youtubeResult = [
            'success' => false,
            'error' => null,
            'items' => [],
        ];

        if ($query !== '') {
            $youtubeResult = $youtubeSearchService->search($query, 6);
        }

        return $this->render('front/youtube_assistant.html.twig', [
            'query' => $query,
            'youtubeError' => $youtubeResult['error'] ?? null,
            'videos' => $youtubeResult['items'] ?? [],
            'autoplay' => $autoplay,
        ]);
    }
}