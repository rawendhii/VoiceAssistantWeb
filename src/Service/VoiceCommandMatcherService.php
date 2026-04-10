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

        $commands = $this->voiceCommandRepository->findBy(['active' => true]);

        $bestMatch = null;
        $bestScore = 0;

        foreach ($commands as $command) {
            $keyword = $this->normalizeText((string) $command->getKeyword());

            if ($keyword === '') {
                continue;
            }

            $score = 0;

            if ($normalizedText === $keyword) {
                $score = 100;
            } elseif (str_contains($normalizedText, $keyword)) {
                $score = 80;
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
}