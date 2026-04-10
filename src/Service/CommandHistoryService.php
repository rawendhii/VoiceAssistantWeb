<?php

namespace App\Service;

use App\Entity\CommandHistory;
use App\Entity\User;
use App\Entity\VoiceCommand;
use Doctrine\ORM\EntityManagerInterface;

class CommandHistoryService
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    public function log(?User $user, ?VoiceCommand $command, string $executedText, string $status): ?CommandHistory
    {
        if (!$user instanceof User) {
            return null;
        }

        $history = new CommandHistory();
        $history->setUser($user);
        $history->setCommand($command);
        $history->setExecutedText($executedText);
        $history->setStatus(strtoupper($status));
        $history->setExecutedAt(new \DateTimeImmutable());

        $this->em->persist($history);
        $this->em->flush();

        return $history;
    }
}