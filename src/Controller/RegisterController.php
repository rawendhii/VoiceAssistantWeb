<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegisterController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_redirect_after_login');
        }

        if ($request->isMethod('POST')) {
            $fullName = trim((string) $request->request->get('fullName'));
            $email = trim((string) $request->request->get('email'));
            $password = (string) $request->request->get('password');
            $passwordConfirmation = (string) $request->request->get('password_confirmation');
            $termsAccepted = $request->request->getBoolean('terms');

            if (mb_strlen($fullName) < 3) {
                $this->addFlash('error', 'Full name must be at least 3 characters.');
                return $this->redirectToRoute('app_register');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Please enter a valid email address.');
                return $this->redirectToRoute('app_register');
            }

            if ($userRepository->findOneBy(['email' => $email])) {
                $this->addFlash('error', 'This email is already registered.');
                return $this->redirectToRoute('app_register');
            }

            if (mb_strlen($password) < 6) {
                $this->addFlash('error', 'Password must be at least 6 characters.');
                return $this->redirectToRoute('app_register');
            }

            if ($password !== $passwordConfirmation) {
                $this->addFlash('error', 'Passwords do not match.');
                return $this->redirectToRoute('app_register');
            }

            if (!$termsAccepted) {
                $this->addFlash('error', 'You must accept the terms of service.');
                return $this->redirectToRoute('app_register');
            }

            $defaultRole = $roleRepository->findOneBy(['name' => 'USER']);

            if (!$defaultRole) {
                $this->addFlash('error', 'Default USER role was not found in the database.');
                return $this->redirectToRoute('app_register');
            }

            $user = new User();
            $user->setFullName($fullName);
            $user->setEmail($email);
            $user->setRole($defaultRole);
            $user->setPassword(
                $passwordHasher->hashPassword($user, $password)
            );

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Your account has been created successfully. Please sign in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig');
    }
}  