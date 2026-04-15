<?php

namespace App\Entity;

use App\Repository\CommandHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommandHistoryRepository::class)]
#[ORM\Table(name: 'command_history')]
class CommandHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?VoiceCommand $command = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $executedText = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $executedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getCommand(): ?VoiceCommand
    {
        return $this->command;
    }

    public function setCommand(?VoiceCommand $command): static
    {
        $this->command = $command;
        return $this;
    }

    public function getExecutedText(): ?string
    {
        return $this->executedText;
    }

    public function setExecutedText(string $executedText): static
    {
        $this->executedText = $executedText;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getExecutedAt(): ?\DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function setExecutedAt(\DateTimeImmutable $executedAt): static
    {
        $this->executedAt = $executedAt;
        return $this;
    }
}