<?php

namespace App\Controller\Front;

use App\Repository\VoiceCommandRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FrontVoiceCommandsController extends AbstractFrontController
{
    #[Route('/front/voice-commands', name: 'front_voice_commands')]
    public function index(VoiceCommandRepository $voiceCommandRepository): Response
    {
        $this->denyUnlessStrictUser();

        $commands = $voiceCommandRepository->findBy(
            ['active' => true],
            ['id' => 'ASC']
        );

        return $this->render('front/voice_commands/index.html.twig', [
            'commands' => $commands,
        ]);
    }
}