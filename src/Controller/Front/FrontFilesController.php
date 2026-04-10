<?php

namespace App\Controller\Front;

use App\Entity\ManagedFile;
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

        $result = $this->managedFileService->getPaginatedUserFiles($user, $search, $typeFilter, $page, 5);

        return $this->render('front/files/index.html.twig', [
            'files' => $result['files'],
            'search' => $search,
            'typeFilter' => $typeFilter,
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    #[Route('/create', name: 'front_files_create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyUnlessStrictUser();

        return $this->render('front/files/create.html.twig');
    }

    #[Route('/store', name: 'front_files_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        /** @var User $user */
        $user = $this->denyUnlessStrictUser();

        if (!$this->isCsrfTokenValid('create_front_file', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $fileName = trim((string) $request->request->get('fileName'));
        $filePath = trim((string) $request->request->get('filePath'));
        $fileType = trim((string) $request->request->get('fileType'));

        $validationError = $this->managedFileService->validateFileData($fileName, $filePath, $fileType);
        if ($validationError !== null) {
            $this->addFlash('error', $validationError);
            return $this->redirectToRoute('front_files_create');
        }

        $this->managedFileService->createForUser($user, $fileName, $filePath, $fileType);

        $this->addFlash('success', 'File created successfully.');
        return $this->redirectToRoute('front_files');
    }

    #[Route('/edit/{id}', name: 'front_files_edit', methods: ['GET'])]
    public function edit(ManagedFile $file): Response
    {
        /** @var User $user */
        $user = $this->denyUnlessStrictUser();

        $this->managedFileService->assertOwnership($file, $user);

        return $this->render('front/files/edit.html.twig', [
            'file' => $file,
        ]);
    }

    #[Route('/update/{id}', name: 'front_files_update', methods: ['POST'])]
    public function update(ManagedFile $file, Request $request): Response
    {
        /** @var User $user */
        $user = $this->denyUnlessStrictUser();

        if (!$this->isCsrfTokenValid('update_front_file_' . $file->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->managedFileService->assertOwnership($file, $user);

        $fileName = trim((string) $request->request->get('fileName'));
        $filePath = trim((string) $request->request->get('filePath'));
        $fileType = trim((string) $request->request->get('fileType'));

        $validationError = $this->managedFileService->validateFileData($fileName, $filePath, $fileType);
        if ($validationError !== null) {
            $this->addFlash('error', $validationError);
            return $this->redirectToRoute('front_files_edit', ['id' => $file->getId()]);
        }

        $this->managedFileService->updateFile($file, $fileName, $filePath, $fileType);

        $this->addFlash('success', 'File updated successfully.');
        return $this->redirectToRoute('front_files');
    }

    #[Route('/delete/{id}', name: 'front_files_delete', methods: ['POST'])]
    public function delete(Request $request, ManagedFile $file): Response
    {
        /** @var User $user */
        $user = $this->denyUnlessStrictUser();

        if (!$this->isCsrfTokenValid('delete_front_file_' . $file->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->managedFileService->assertOwnership($file, $user);
        $this->managedFileService->deleteFile($file);

        $this->addFlash('success', 'File deleted successfully.');
        return $this->redirectToRoute('front_files');
    }
}