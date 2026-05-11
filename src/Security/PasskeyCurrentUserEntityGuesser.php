<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Webauthn\Bundle\Security\Guesser\UserEntityGuesser;
use Webauthn\PublicKeyCredentialUserEntity;

class PasskeyCurrentUserEntityGuesser implements UserEntityGuesser
{
    public function __construct(
        private readonly Security $security
    ) {
    }

    public function findUserEntity(Request $request): PublicKeyCredentialUserEntity
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new BadRequestHttpException('No logged-in user found for Face Login setup.');
        }

        if ($user->getId() === null) {
            throw new BadRequestHttpException('The logged-in user has no valid ID.');
        }

        $email = (string) $user->getEmail();
        $displayName = $user->getFullName() ?: $email;

        return new PublicKeyCredentialUserEntity(
            $email,
            (string) $user->getId(),
            $displayName,
            null
        );
    }
}