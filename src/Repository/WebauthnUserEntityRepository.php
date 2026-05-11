<?php

namespace App\Repository;

use App\Entity\User;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface;
use Webauthn\PublicKeyCredentialUserEntity;

class WebauthnUserEntityRepository implements PublicKeyCredentialUserEntityRepositoryInterface
{
    public function __construct(
        private readonly UserRepository $userRepository
    ) {
    }

    public function findOneByUsername(string $username): ?PublicKeyCredentialUserEntity
    {
        $user = $this->userRepository->findOneBy([
            'email' => trim($username),
        ]);

        return $this->createUserEntity($user);
    }

    public function findOneByUserHandle(string $userHandle): ?PublicKeyCredentialUserEntity
    {
        if (!ctype_digit($userHandle)) {
            return null;
        }

        $user = $this->userRepository->find((int) $userHandle);

        return $this->createUserEntity($user);
    }

    private function createUserEntity(?User $user): ?PublicKeyCredentialUserEntity
    {
        if (!$user instanceof User || $user->getId() === null) {
            return null;
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