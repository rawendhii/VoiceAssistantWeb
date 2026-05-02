<?php

namespace App\Service;

use App\Entity\VoiceCommand;
use App\Repository\VoiceCommandRepository;

class VoiceCommandMatcherService
{
    public function __construct(
        private VoiceCommandRepository $voiceCommandRepository
    ) {
    }

    public function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    public function match(string $spokenText): ?VoiceCommand
    {
        $normalizedText = $this->normalizeText($spokenText);

        if ($normalizedText === '') {
            return null;
        }

        if ($this->isBlockedFileSystemCommand($normalizedText)) {
            return null;
        }

        $commands = $this->voiceCommandRepository->findBy(['active' => true]);

        $aliasKeyword = $this->detectAliasKeyword($normalizedText);

        if ($aliasKeyword !== null) {
            foreach ($commands as $command) {
                $keyword = $this->normalizeText((string) $command->getKeyword());

                if ($keyword === $aliasKeyword) {
                    return $command;
                }
            }
        }

        $bestMatch = null;
        $bestScore = 0;

        foreach ($commands as $command) {
            $keyword = $this->normalizeText((string) $command->getKeyword());

            if ($keyword === '') {
                continue;
            }

            if ($this->isBlockedCommandKeyword($keyword)) {
                continue;
            }

            $score = 0;

            if ($normalizedText === $keyword) {
                $score = 100;
            } elseif (str_contains($normalizedText, $keyword)) {
                $score = 85;
            } else {
                similar_text($normalizedText, $keyword, $percent);
                $score = (int) $percent;
            }

            if ($score > $bestScore && $score >= 60) {
                $bestScore = $score;
                $bestMatch = $command;
            }
        }

        return $bestMatch;
    }

    public function findActiveCommandByKeyword(string $keyword): ?VoiceCommand
    {
        $normalizedKeyword = $this->normalizeText($keyword);

        if ($normalizedKeyword === '' || $this->isBlockedCommandKeyword($normalizedKeyword)) {
            return null;
        }

        $commands = $this->voiceCommandRepository->findBy(['active' => true]);

        foreach ($commands as $command) {
            $commandKeyword = $this->normalizeText((string) $command->getKeyword());

            if ($commandKeyword === $normalizedKeyword) {
                return $command;
            }
        }

        return null;
    }

    private function detectAliasKeyword(string $normalizedText): ?string
    {
        $aliases = [
            'edit profile' => [
                'edit profile',
                'change profile',
                'update profile',
                'modify profile',
                'edit my profile',
                'change my profile',
                'update my profile',
                'modify my profile',
                'edit account',
                'change account',
                'update account',
                'modify account',
                'edit my account',
                'change my account',
                'update my account',
                'modify my account',
                'change my name',
                'update my name',
                'edit my name',
                'modify my name',
                'change name',
                'update name',
                'change my email',
                'update my email',
                'edit my email',
                'modify my email',
                'change email',
                'update email',
                'change my name and my email',
                'change my name and email',
                'change my name or my email',
                'update my name and email',
                'update my personal information',
                'edit my personal information',
                'change my personal information',
                'i want to change my name',
                'i want to change my email',
                'i want to change my name and my email',
                'i want to change my name or my email',
            ],

            'open profile' => [
                'open profile',
                'show profile',
                'my profile',
                'show my profile',
                'go to profile',
                'go to my profile',
                'profile page',
            ],

            'change password' => [
                'change password',
                'update password',
                'edit password',
                'modify password',
                'change my password',
                'update my password',
                'i want to change my password',
                'password page',
            ],

            'delete account' => [
                'delete account',
                'delete my account',
                'remove account',
                'remove my account',
                'close account',
                'close my account',
            ],

            'show my files' => [
                'show my files',
                'open my files',
                'go to my files',
                'files',
                'my files',
                'file page',
                'documents',
                'my documents',
                'uploads',
                'my uploads',
            ],

            'show voice commands' => [
                'show voice commands',
                'open voice commands',
                'available commands',
                'show commands',
                'command list',
                'voice command list',
            ],

            'go home' => [
                'go home',
                'home',
                'homepage',
                'go to home',
                'back home',
                'return home',
                'main page',
            ],

            'logout' => [
                'logout',
                'log out',
                'sign out',
                'disconnect',
                'exit account',
            ],
        ];

        foreach ($aliases as $canonicalKeyword => $phrases) {
            foreach ($phrases as $phrase) {
                $normalizedPhrase = $this->normalizeText($phrase);

                if ($normalizedText === $normalizedPhrase) {
                    return $canonicalKeyword;
                }

                if (str_contains($normalizedText, $normalizedPhrase)) {
                    return $canonicalKeyword;
                }
            }
        }

        return null;
    }

    private function isBlockedCommandKeyword(string $keyword): bool
    {
        return in_array($keyword, [
            'create file',
            'delete file',
            'rename file',
            'read file',
            'write file',
            'update file',
            'edit file',
        ], true);
    }

    private function isBlockedFileSystemCommand(string $normalizedText): bool
    {
        $fileWords =
            str_contains($normalizedText, 'file') ||
            str_contains($normalizedText, 'document') ||
            str_contains($normalizedText, 'folder');

        $dangerousActions =
            str_contains($normalizedText, 'create') ||
            str_contains($normalizedText, 'make') ||
            str_contains($normalizedText, 'delete') ||
            str_contains($normalizedText, 'remove') ||
            str_contains($normalizedText, 'rename') ||
            str_contains($normalizedText, 'write') ||
            str_contains($normalizedText, 'update') ||
            str_contains($normalizedText, 'edit') ||
            str_contains($normalizedText, 'modify');

        return $fileWords && $dangerousActions;
    }
}