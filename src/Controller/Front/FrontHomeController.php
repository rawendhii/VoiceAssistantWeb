<?php

namespace App\Controller\Front;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FrontHomeController extends AbstractFrontController
{
    #[Route('/front/home', name: 'front_home')]
    public function index(): Response
    {
        $this->denyUnlessStrictUser();

        return $this->render('front/home/index.html.twig');
    }
}