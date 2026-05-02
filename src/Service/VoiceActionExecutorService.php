<?php

namespace App\Service;

use App\Entity\User;

class VoiceActionExecutorService
{
    public function execute(string $spokenText, string $commandKeyword, ?User $user = null): array
    {
        $commandKeyword = mb_strtolower(trim($commandKeyword));

        return match ($commandKeyword) {
            'open profile' => [
                'success' => true,
                'action' => 'open_profile',
                'redirect' => '/front/profile',
                'message' => 'Opening profile page.',
                'speech' => 'Opening your profile page now.',
            ],

            'edit profile' => [
                'success' => true,
                'action' => 'edit_profile',
                'redirect' => '/front/profile/edit',
                'message' => 'Opening profile edit page.',
                'speech' => 'Opening your profile edit page now.',
            ],

            'change password' => [
                'success' => true,
                'action' => 'change_password',
                'redirect' => '/front/profile/change-password',
                'message' => 'Opening change password page.',
                'speech' => 'Opening the change password page now.',
            ],

            'delete account' => [
                'success' => true,
                'action' => 'delete_account_page',
                'redirect' => '/front/profile/delete',
                'message' => 'Opening delete account confirmation page.',
                'speech' => 'Opening the delete account confirmation page. Please confirm manually before deleting.',
            ],

            'show my files' => [
                'success' => true,
                'action' => 'show_files',
                'redirect' => '/front/files',
                'message' => 'Opening your files page.',
                'speech' => 'Opening your files page now.',
            ],

            'show voice commands' => [
                'success' => true,
                'action' => 'show_voice_commands',
                'redirect' => '/front/voice-commands',
                'message' => 'Opening the voice commands page.',
                'speech' => 'Opening the list of available voice commands.',
            ],

            'go home' => [
                'success' => true,
                'action' => 'go_home',
                'redirect' => '/front/home',
                'message' => 'Going back to the home page.',
                'speech' => 'Returning to the home page.',
            ],

            'logout' => [
                'success' => true,
                'action' => 'logout',
                'redirect' => '/logout',
                'message' => 'Logging out.',
                'speech' => 'Logging you out now.',
            ],

            default => [
                'success' => false,
                'action' => 'unknown',
                'message' => 'Command recognized, but no safe web action is implemented for it.',
                'speech' => 'I understood the command, but this action is not available in the web assistant.',
            ],
        };
    }
}