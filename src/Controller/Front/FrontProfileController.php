<?php

namespace App\Controller\Front;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
class FrontProfileController extends AbstractFrontController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/front/profile', name: 'front_profile')]
    public function index(): Response
    {
        $user = $this->denyUnlessStrictUser();

        return $this->render('front/profile/index.html.twig', [
            'user' => $user,
        ]);
    }

  #[Route('/front/profile/edit', name: 'front_profile_edit')]
public function edit(Request $request): Response
{
    $user = $this->denyUnlessStrictUser();

    if ($request->isMethod('POST')) {

        $fullName = trim($request->request->get('fullName'));
        $email = trim($request->request->get('email'));

        // ❌ conditions
        if (strlen($fullName) < 3) {
            $this->addFlash('error', 'Name must be at least 3 characters.');
            return $this->redirectToRoute('front_profile_edit');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Invalid email format.');
            return $this->redirectToRoute('front_profile_edit');
        }

        // check if email already exists (important)
        $existingUser = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        if ($existingUser && $existingUser !== $user) {
            $this->addFlash('error', 'Email already used.');
            return $this->redirectToRoute('front_profile_edit');
        }

        // ✔ update
        $user->setFullName($fullName);
        $user->setEmail($email);

        $this->entityManager->flush();

        $this->addFlash('success', 'Profile updated successfully.');
        return $this->redirectToRoute('front_profile');
    }

    return $this->render('front/profile/edit.html.twig', [
        'user' => $user
    ]);
}

 #[Route('/front/profile/change-password', name: 'front_profile_change_password')]
public function changePassword(Request $request, UserPasswordHasherInterface $hasher): Response
{
    $user = $this->denyUnlessStrictUser();

    if ($request->isMethod('POST')) {

        $current = $request->request->get('currentPassword');
        $new = $request->request->get('newPassword');
        $confirm = $request->request->get('confirmPassword');

        // ❌ check current password
        if (!$hasher->isPasswordValid($user, $current)) {
            $this->addFlash('error', 'Current password is incorrect.');
            return $this->redirectToRoute('front_profile_change_password');
        }

        // ❌ length
        if (strlen($new) < 6) {
            $this->addFlash('error', 'Password must be at least 6 characters.');
            return $this->redirectToRoute('front_profile_change_password');
        }

        // ❌ match confirmation
        if ($new !== $confirm) {
            $this->addFlash('error', 'Passwords do not match.');
            return $this->redirectToRoute('front_profile_change_password');
        }

        // ✔ update password
        $user->setPassword(
            $hasher->hashPassword($user, $new)
        );

        $this->entityManager->flush();

        $this->addFlash('success', 'Password changed successfully.');
        return $this->redirectToRoute('front_profile');
    }

    return $this->render('front/profile/change_password.html.twig');
}

   #[Route('/front/profile/delete', name: 'front_profile_delete')]
public function deleteAccount(Request $request): Response
{
    $user = $this->denyUnlessStrictUser();

    if ($request->isMethod('POST')) {

        // 1. remove user
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        // 2. clear security (important)
        $this->container->get('security.token_storage')->setToken(null);

        // 3. invalidate session
        $request->getSession()->invalidate();

        // 4. go to logout page
        return $this->redirectToRoute('app_logout');
    }

    return $this->render('front/profile/delete.html.twig');
}
}