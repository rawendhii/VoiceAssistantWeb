<?php

namespace App\Controller\Front;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

abstract class AbstractFrontController extends AbstractController
{
    protected function denyUnlessStrictUser(): User
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('User not authenticated.');
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw new AccessDeniedHttpException('Admins cannot access the front user area.');
        }

        return $user;
    }
}