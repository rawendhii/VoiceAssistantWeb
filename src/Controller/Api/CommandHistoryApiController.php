<?php

namespace App\Controller\Api;

use App\Entity\CommandHistory;
use App\Entity\User;
use App\Entity\VoiceCommand;
use App\Repository\CommandHistoryRepository;
use App\Repository\UserRepository;
use App\Repository\VoiceCommandRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/command-history')]
class CommandHistoryApiController extends AbstractController
{
    #[Route('', name: 'api_command_history_index', methods: ['GET'])]
    public function index(Request $request, CommandHistoryRepository $commandHistoryRepository): JsonResponse
    {
        $search = trim((string) $request->query->get('search', ''));
        $status = strtoupper(trim((string) $request->query->get('status', 'ALL')));
        $userId = (int) $request->query->get('userId', 0);

        $qb = $commandHistoryRepository->createQueryBuilder('h')
            ->leftJoin('h.user', 'u')
            ->addSelect('u')
            ->leftJoin('h.command', 'c')
            ->addSelect('c');

        if ($userId > 0) {
            $qb->andWhere('u.id = :userId')
                ->setParameter('userId', $userId);
        }

        if ($search !== '') {
            $qb->andWhere(
                'LOWER(u.fullName) LIKE :search 
                 OR LOWER(c.label) LIKE :search 
                 OR LOWER(c.keyword) LIKE :search 
                 OR LOWER(h.executedText) LIKE :search'
            )
            ->setParameter('search', '%' . strtolower($search) . '%');
        }

        if ($status !== '' && $status !== 'ALL') {
            $qb->andWhere('UPPER(h.status) = :status')
                ->setParameter('status', $status);
        }

        $history = $qb->orderBy('h.executedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->json([
            'success' => true,
            'history' => array_map([$this, 'serializeHistory'], $history),
        ]);
    }

    #[Route('/{id}', name: 'api_command_history_show', methods: ['GET'])]
    public function show(int $id, CommandHistoryRepository $commandHistoryRepository): JsonResponse
    {
        $history = $commandHistoryRepository->createQueryBuilder('h')
            ->leftJoin('h.user', 'u')
            ->addSelect('u')
            ->leftJoin('h.command', 'c')
            ->addSelect('c')
            ->where('h.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$history instanceof CommandHistory) {
            return $this->json([
                'success' => false,
                'message' => 'Command history record not found.',
            ], 404);
        }

        return $this->json([
            'success' => true,
            'record' => $this->serializeHistory($history),
        ]);
    }

    #[Route('', name: 'api_command_history_create', methods: ['POST'])]
    public function create(
        Request $request,
        UserRepository $userRepository,
        VoiceCommandRepository $voiceCommandRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $userId = (int) ($data['userId'] ?? 0);
        $commandId = (int) ($data['commandId'] ?? 0);
        $executedText = trim((string) ($data['executedText'] ?? ''));
        $status = strtoupper(trim((string) ($data['status'] ?? 'SUCCESS')));

        if ($userId <= 0) {
            return $this->json([
                'success' => false,
                'message' => 'User ID is required.',
            ], 400);
        }

        if (mb_strlen($executedText) < 1) {
            return $this->json([
                'success' => false,
                'message' => 'Executed text is required.',
            ], 400);
        }

        if (!in_array($status, ['SUCCESS', 'FAILED'], true)) {
            return $this->json([
                'success' => false,
                'message' => 'Status must be SUCCESS or FAILED.',
            ], 400);
        }

        $user = $userRepository->find($userId);

        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $command = null;

        if ($commandId > 0) {
            $command = $voiceCommandRepository->find($commandId);

            if (!$command instanceof VoiceCommand) {
                return $this->json([
                    'success' => false,
                    'message' => 'Voice command not found.',
                ], 404);
            }
        }

        $history = new CommandHistory();
        $history->setUser($user);
        $history->setCommand($command);
        $history->setExecutedText($executedText);
        $history->setStatus($status);
        $history->setExecutedAt(new \DateTimeImmutable());

        $em->persist($history);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Command history record created successfully.',
            'record' => $this->serializeHistory($history),
        ], 201);
    }

    #[Route('/{id}', name: 'api_command_history_delete', methods: ['DELETE'])]
    public function delete(
        int $id,
        CommandHistoryRepository $commandHistoryRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $history = $commandHistoryRepository->find($id);

        if (!$history instanceof CommandHistory) {
            return $this->json([
                'success' => false,
                'message' => 'Command history record not found.',
            ], 404);
        }

        $em->remove($history);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Command history record deleted successfully.',
        ]);
    }

    private function serializeHistory(CommandHistory $history): array
    {
        return [
            'id' => $history->getId(),
            'userId' => $history->getUser()?->getId(),
            'userName' => $history->getUser()?->getFullName(),
            'commandId' => $history->getCommand()?->getId(),
            'commandLabel' => $history->getCommand()?->getLabel() ?? 'Unknown command',
            'commandKeyword' => $history->getCommand()?->getKeyword() ?? '',
            'executedText' => $history->getExecutedText(),
            'status' => $history->getStatus(),
            'executedAt' => $history->getExecutedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}