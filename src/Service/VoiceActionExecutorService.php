<?php

namespace App\Service;

use App\Entity\User;

class VoiceActionExecutorService
{
    public function execute(string $spokenText, string $commandKeyword, ?User $user = null): array
    {
        $spokenText = mb_strtolower(trim($spokenText));
        $commandKeyword = mb_strtolower(trim($commandKeyword));

        return match ($commandKeyword) {
            'open profile' => [
                'success' => true,
                'action' => 'open_profile',
                'redirect' => '/front/profile',
                'message' => 'Opening profile page.',
                'speech' => 'Opening your profile page now.'
            ],

            'show my files' => [
                'success' => true,
                'action' => 'show_files',
                'redirect' => '/front/files',
                'message' => 'Opening your files page.',
                'speech' => 'Opening your files page now.'
            ],

            'go home' => [
                'success' => true,
                'action' => 'go_home',
                'redirect' => '/front/home',
                'message' => 'Going back to the home page.',
                'speech' => 'Returning to the home page.'
            ],

            'logout' => [
                'success' => true,
                'action' => 'logout',
                'redirect' => '/logout',
                'message' => 'Logging out.',
                'speech' => 'Logging you out now.'
            ],

            default => [
                'success' => true,
                'action' => 'matched_only',
                'message' => 'Command recognized, but no real action is implemented yet.',
                'speech' => 'I recognised your command, but this action is not fully implemented yet.'
            ],
        };
    }
}