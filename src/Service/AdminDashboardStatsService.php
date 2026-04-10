<?php

namespace App\Service;

use App\Repository\CommandHistoryRepository;
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
        private CommandHistoryRepository $commandHistoryRepository
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
        ];
    }
}