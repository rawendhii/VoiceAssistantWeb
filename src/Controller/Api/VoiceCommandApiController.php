<?php

namespace App\Controller\Api;

use App\Entity\VoiceCommand;
use App\Repository\VoiceCommandRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/voice-commands')]
class VoiceCommandApiController extends AbstractController
{
    #[Route('', name: 'api_voice_commands_index', methods: ['GET'])]
    public function index(Request $request, VoiceCommandRepository $voiceCommandRepository): JsonResponse
    {
        $search = trim((string) $request->query->get('search', ''));

        $qb = $voiceCommandRepository->createQueryBuilder('vc');

        if ($search !== '') {
            $qb->andWhere(
                'LOWER(vc.label) LIKE :search 
                 OR LOWER(vc.keyword) LIKE :search 
                 OR LOWER(vc.description) LIKE :search'
            )
            ->setParameter('search', '%' . strtolower($search) . '%');
        }

        $commands = $qb->orderBy('vc.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->json([
            'success' => true,
            'commands' => array_map([$this, 'serializeVoiceCommand'], $commands),
        ]);
    }

    #[Route('/{id}', name: 'api_voice_commands_show', methods: ['GET'])]
    public function show(int $id, VoiceCommandRepository $voiceCommandRepository): JsonResponse
    {
        $command = $voiceCommandRepository->find($id);

        if (!$command instanceof VoiceCommand) {
            return $this->json([
                'success' => false,
                'message' => 'Voice command not found.',
            ], 404);
        }

        return $this->json([
            'success' => true,
            'command' => $this->serializeVoiceCommand($command),
        ]);
    }

    #[Route('', name: 'api_voice_commands_create', methods: ['POST'])]
    public function create(
        Request $request,
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

        $label = trim((string) ($data['label'] ?? ''));
        $keyword = strtolower(trim((string) ($data['keyword'] ?? '')));
        $description = trim((string) ($data['description'] ?? ''));
        $active = (bool) ($data['active'] ?? true);

        $error = $this->validateVoiceCommandData($label, $keyword, $description);

        if ($error !== null) {
            return $this->json([
                'success' => false,
                'message' => $error,
            ], 400);
        }

        $existingCommand = $voiceCommandRepository->findOneBy([
            'keyword' => $keyword,
        ]);

        if ($existingCommand instanceof VoiceCommand) {
            return $this->json([
                'success' => false,
                'message' => 'This keyword already exists.',
            ], 409);
        }

        $command = new VoiceCommand();
        $command->setLabel($label);
        $command->setKeyword($keyword);
        $command->setDescription($description !== '' ? $description : null);
        $command->setActive($active);

        $em->persist($command);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Voice command created successfully.',
            'command' => $this->serializeVoiceCommand($command),
        ], 201);
    }

    #[Route('/{id}', name: 'api_voice_commands_update', methods: ['PUT'])]
    public function update(
        int $id,
        Request $request,
        VoiceCommandRepository $voiceCommandRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $command = $voiceCommandRepository->find($id);

        if (!$command instanceof VoiceCommand) {
            return $this->json([
                'success' => false,
                'message' => 'Voice command not found.',
            ], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $label = trim((string) ($data['label'] ?? ''));
        $keyword = strtolower(trim((string) ($data['keyword'] ?? '')));
        $description = trim((string) ($data['description'] ?? ''));
        $active = (bool) ($data['active'] ?? true);

        $error = $this->validateVoiceCommandData($label, $keyword, $description);

        if ($error !== null) {
            return $this->json([
                'success' => false,
                'message' => $error,
            ], 400);
        }

        $existingCommand = $voiceCommandRepository->findOneBy([
            'keyword' => $keyword,
        ]);

        if ($existingCommand instanceof VoiceCommand && $existingCommand->getId() !== $command->getId()) {
            return $this->json([
                'success' => false,
                'message' => 'This keyword is already used by another command.',
            ], 409);
        }

        $command->setLabel($label);
        $command->setKeyword($keyword);
        $command->setDescription($description !== '' ? $description : null);
        $command->setActive($active);

        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Voice command updated successfully.',
            'command' => $this->serializeVoiceCommand($command),
        ]);
    }

    #[Route('/{id}', name: 'api_voice_commands_delete', methods: ['DELETE'])]
public function delete(
    int $id,
    VoiceCommandRepository $voiceCommandRepository,
    EntityManagerInterface $em
): JsonResponse {
    $command = $voiceCommandRepository->find($id);

    if (!$command instanceof VoiceCommand) {
        return $this->json([
            'success' => false,
            'message' => 'Voice command not found.',
        ], 404);
    }

    try {
        $em->remove($command);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Voice command deleted successfully.',
        ]);

    } catch (\Throwable $e) {
        return $this->json([
            'success' => false,
            'message' => 'Cannot delete this voice command because it is already used in command history or another table.',
            'error' => $e->getMessage(),
        ], 400);
    }
}

    private function validateVoiceCommandData(string $label, string $keyword, string $description): ?string
    {
        if (mb_strlen($label) < 3) {
            return 'Label must be at least 3 characters.';
        }

        if (mb_strlen($keyword) < 3) {
            return 'Keyword must be at least 3 characters.';
        }

        if (mb_strlen($description) > 255) {
            return 'Description must not exceed 255 characters.';
        }

        return null;
    }

    private function serializeVoiceCommand(VoiceCommand $command): array
    {
        return [
            'id' => $command->getId(),
            'label' => $command->getLabel(),
            'keyword' => $command->getKeyword(),
            'description' => $command->getDescription() ?? '',
            'active' => (bool) $command->isActive(),
        ];
    }
}