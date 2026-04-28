<?php

namespace App\Service;

use App\Repository\CommandHistoryRepository;
use App\Repository\DesktopActionRepository;
use App\Repository\ManagedFileRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Repository\VoiceCommandRepository;

class AdminDashboardStatsService
{
    public function __construct(
        private UserRepository $userRepository,
        private RoleRepository $roleRepository,
        private ManagedFileRepository $managedFileRepository,
        private VoiceCommandRepository $voiceCommandRepository,
        private CommandHistoryRepository $commandHistoryRepository,
        private DesktopActionRepository $desktopActionRepository
    ) {
    }

    public function getStats(): array
    {
        return [
            'usersCount' => $this->userRepository->count([]),
            'rolesCount' => $this->roleRepository->count([]),
            'filesCount' => $this->managedFileRepository->count([]),
            'voiceCommandsCount' => $this->voiceCommandRepository->count([]),
            'historyCount' => $this->commandHistoryRepository->count([]),

            'successfulCommandsCount' => $this->commandHistoryRepository->countByStatus('SUCCESS'),
            'failedCommandsCount' => $this->commandHistoryRepository->countByStatus('FAILED'),
            'pendingContentCount' => $this->commandHistoryRepository->countByStatus('PENDING_CONTENT'),
            'pendingConfirmationCount' => $this->commandHistoryRepository->countByStatus('PENDING_CONFIRMATION'),

            'desktopPendingCount' => $this->desktopActionRepository->countByStatus('PENDING'),
            'desktopSuccessCount' => $this->desktopActionRepository->countByStatus('SUCCESS'),
            'desktopFailedCount' => $this->desktopActionRepository->countByStatus('FAILED'),

            'latestCommands' => $this->commandHistoryRepository->findLatest(5),
            'mostUsedCommands' => $this->commandHistoryRepository->findMostUsedCommands(5),
            'latestDesktopActions' => $this->desktopActionRepository->findLatest(5),
        ];
    }
}