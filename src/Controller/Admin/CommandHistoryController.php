<?php

namespace App\Controller\Admin;

use App\Entity\CommandHistory;
use App\Repository\CommandHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/command-history')]
class CommandHistoryController extends AbstractController
{
    #[Route('/', name: 'admin_command_history', methods: ['GET'])]
    public function index(Request $request, CommandHistoryRepository $commandHistoryRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $search = trim((string) $request->query->get('search', ''));
        $statusFilter = trim((string) $request->query->get('status', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $limit = 8;
        $offset = ($page - 1) * $limit;

        $qb = $commandHistoryRepository->createQueryBuilder('ch')
            ->leftJoin('ch.user', 'u')
            ->leftJoin('ch.command', 'vc')
            ->addSelect('u', 'vc');

        if ($search !== '') {
            $qb->andWhere('UPPER(ch.executedText) LIKE UPPER(:search) OR UPPER(u.email) LIKE UPPER(:search) OR UPPER(vc.label) LIKE UPPER(:search)')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($statusFilter !== '') {
            $qb->andWhere('UPPER(ch.status) = UPPER(:status)')
               ->setParameter('status', $statusFilter);
        }

        $total = (clone $qb)
            ->select('COUNT(ch.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $histories = $qb
            ->orderBy('ch.executedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = max(1, (int) ceil($total / $limit));

        return $this->render('admin/command_history/index.html.twig', [
            'histories' => $histories,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/delete/{id}', name: 'admin_command_history_delete', methods: ['POST'])]
    public function delete(Request $request, CommandHistory $history, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_history_' . $history->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $em->remove($history);
        $em->flush();

        $this->addFlash('success', 'Command history deleted successfully.');
        return $this->redirectToRoute('admin_command_history');
    }
}