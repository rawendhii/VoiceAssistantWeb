<?php

namespace App\Controller\Admin;

use App\Entity\VoiceCommand;
use App\Repository\VoiceCommandRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/voice-commands')]
class VoiceCommandController extends AbstractController
{
    #[Route('/', name: 'admin_voice_commands', methods: ['GET'])]
    public function index(Request $request, VoiceCommandRepository $voiceCommandRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $search = trim((string) $request->query->get('search', ''));
        $status = trim((string) $request->query->get('status', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $limit = 5;
        $offset = ($page - 1) * $limit;

        $qb = $voiceCommandRepository->createQueryBuilder('vc');

        if ($search !== '') {
            $qb->andWhere('UPPER(vc.label) LIKE UPPER(:search) OR UPPER(vc.keyword) LIKE UPPER(:search) OR UPPER(vc.description) LIKE UPPER(:search)')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($status !== '') {
            if ($status === 'active') {
                $qb->andWhere('vc.active = true');
            } elseif ($status === 'inactive') {
                $qb->andWhere('vc.active = false');
            }
        }

        $total = (clone $qb)
            ->select('COUNT(vc.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $commands = $qb
            ->orderBy('vc.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = max(1, (int) ceil($total / $limit));

        return $this->render('admin/voice_commands/index.html.twig', [
            'commands' => $commands,
            'search' => $search,
            'status' => $status,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/create', name: 'admin_voice_commands_create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/voice_commands/create.html.twig');
    }

    #[Route('/store', name: 'admin_voice_commands_store', methods: ['POST'])]
    public function store(
        Request $request,
        EntityManagerInterface $em,
        VoiceCommandRepository $voiceCommandRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('create_voice_command', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $label = trim((string) $request->request->get('label'));
        $keyword = strtolower(trim((string) $request->request->get('keyword')));
        $description = trim((string) $request->request->get('description'));
        $active = $request->request->get('active') === '1';

        if (strlen($label) < 3) {
            $this->addFlash('error', 'Label must be at least 3 characters.');
            return $this->redirectToRoute('admin_voice_commands_create');
        }

        if (strlen($keyword) < 2) {
            $this->addFlash('error', 'Keyword must be at least 2 characters.');
            return $this->redirectToRoute('admin_voice_commands_create');
        }

        $existing = $voiceCommandRepository->findOneBy(['keyword' => $keyword]);
        if ($existing) {
            $this->addFlash('error', 'This keyword already exists.');
            return $this->redirectToRoute('admin_voice_commands_create');
        }

        $command = new VoiceCommand();
        $command->setLabel($label);
        $command->setKeyword($keyword);
        $command->setDescription($description !== '' ? $description : null);
        $command->setActive($active);

        $em->persist($command);
        $em->flush();

        $this->addFlash('success', 'Voice command created successfully.');
        return $this->redirectToRoute('admin_voice_commands');
    }

    #[Route('/edit/{id}', name: 'admin_voice_commands_edit', methods: ['GET'])]
    public function edit(VoiceCommand $command): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/voice_commands/edit.html.twig', [
            'command' => $command,
        ]);
    }

    #[Route('/update/{id}', name: 'admin_voice_commands_update', methods: ['POST'])]
    public function update(
        VoiceCommand $command,
        Request $request,
        EntityManagerInterface $em,
        VoiceCommandRepository $voiceCommandRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('update_voice_command_' . $command->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $label = trim((string) $request->request->get('label'));
        $keyword = strtolower(trim((string) $request->request->get('keyword')));
        $description = trim((string) $request->request->get('description'));
        $active = $request->request->get('active') === '1';

        if (strlen($label) < 3) {
            $this->addFlash('error', 'Label must be at least 3 characters.');
            return $this->redirectToRoute('admin_voice_commands_edit', ['id' => $command->getId()]);
        }

        if (strlen($keyword) < 2) {
            $this->addFlash('error', 'Keyword must be at least 2 characters.');
            return $this->redirectToRoute('admin_voice_commands_edit', ['id' => $command->getId()]);
        }

        $existing = $voiceCommandRepository->findOneBy(['keyword' => $keyword]);
        if ($existing && $existing->getId() !== $command->getId()) {
            $this->addFlash('error', 'This keyword is already used by another command.');
            return $this->redirectToRoute('admin_voice_commands_edit', ['id' => $command->getId()]);
        }

        $command->setLabel($label);
        $command->setKeyword($keyword);
        $command->setDescription($description !== '' ? $description : null);
        $command->setActive($active);

        $em->flush();

        $this->addFlash('success', 'Voice command updated successfully.');
        return $this->redirectToRoute('admin_voice_commands');
    }

    #[Route('/delete/{id}', name: 'admin_voice_commands_delete', methods: ['POST'])]
    public function delete(Request $request, VoiceCommand $command, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_voice_command_' . $command->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $em->remove($command);
        $em->flush();

        $this->addFlash('success', 'Voice command deleted successfully.');
        return $this->redirectToRoute('admin_voice_commands');
    }
}