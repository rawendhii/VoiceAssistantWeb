<?php

namespace App\Controller\Front;

use App\Entity\User;
use App\Service\ManagedFileService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/front/files')]
class FrontFilesController extends AbstractFrontController
{
    public function __construct(
        private ManagedFileService $managedFileService
    ) {
    }

    #[Route('/', name: 'front_files', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->denyUnlessStrictUser();

        $search = trim((string) $request->query->get('search', ''));
        $typeFilter = trim((string) $request->query->get('type', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $result = $this->managedFileService->getPaginatedUserFiles(
            $user,
            $search,
            $typeFilter,
            $page,
            5
        );

        return $this->render('front/files/index.html.twig', [
            'files' => $result['files'],
            'search' => $search,
            'typeFilter' => $typeFilter,
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
        ]);
    }
}