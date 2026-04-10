<?php

namespace App\Controller\Front;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FrontProfileController extends AbstractFrontController
{
    #[Route('/front/profile', name: 'front_profile')]
    public function index(): Response
    {
        $user = $this->denyUnlessStrictUser();

        return $this->render('front/profile/index.html.twig', [
            'user' => $user,
        ]);
    }
}