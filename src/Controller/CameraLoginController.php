<?php

namespace App\Controller;

use App\Entity\FaceLoginProfile;
use App\Entity\User;
use App\Repository\FaceLoginProfileRepository;
use App\Repository\UserRepository;
use App\Security\LoginFormAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class CameraLoginController extends AbstractController
{
    #[IsGranted('ROLE_USER')]
    #[Route('/front/profile/camera-login/test', name: 'front_camera_login_test', methods: ['GET'])]
    public function test(): Response
    {
        return $this->render('front/profile/camera_login_setup.html.twig');
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/front/profile/camera-login/setup', name: 'front_camera_login_setup', methods: ['GET'])]
    public function setup(): Response
    {
        return $this->render('front/profile/camera_login_setup.html.twig');
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/front/profile/camera-login/save', name: 'front_camera_login_save', methods: ['POST'])]
    public function save(
        Request $request,
        EntityManagerInterface $entityManager,
        FaceLoginProfileRepository $faceLoginProfileRepository
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'You must be logged in to enable camera login.',
            ], 401);
        }

        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON request.',
            ], 400);
        }

        $csrfToken = (string) ($payload['_token'] ?? '');

        if (!$this->isCsrfTokenValid('camera_face_save', $csrfToken)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid security token. Please refresh the page and try again.',
            ], 400);
        }

        $livenessPassed = (bool) ($payload['livenessPassed'] ?? false);
        $templateData = $payload['templateData'] ?? null;

        if (!$livenessPassed) {
            return $this->json([
                'success' => false,
                'message' => 'Liveness check was not completed.',
            ], 400);
        }

        if (!is_array($templateData)) {
            return $this->json([
                'success' => false,
                'message' => 'Face template is missing.',
            ], 400);
        }

        $vector = $templateData['vector'] ?? null;

        if (!is_array($vector) || count($vector) < 30) {
            return $this->json([
                'success' => false,
                'message' => 'Face template is incomplete.',
            ], 400);
        }

        $faceLoginProfile = $faceLoginProfileRepository->findOneForUser($user);

        if (!$faceLoginProfile instanceof FaceLoginProfile) {
            $faceLoginProfile = new FaceLoginProfile();
            $faceLoginProfile->setUser($user);
            $entityManager->persist($faceLoginProfile);
        }

        $faceLoginProfile->setTemplateData([
            'version' => 1,
            'type' => 'mediapipe_landmark_geometry',
            'template' => $templateData,
            'savedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        $faceLoginProfile->setIsEnabled(true);
        $faceLoginProfile->setUpdatedAt(new \DateTimeImmutable());

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Camera login has been enabled successfully.',
        ]);
    }

    #[Route('/camera-login', name: 'camera_login', methods: ['GET'])]
    public function loginPage(): Response
    {
        return $this->render('security/camera_login.html.twig');
    }

    #[Route('/camera-login/verify', name: 'camera_login_verify', methods: ['POST'])]
    public function verify(
        Request $request,
        UserRepository $userRepository,
        FaceLoginProfileRepository $faceLoginProfileRepository,
        Security $security
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON request.',
            ], 400);
        }

        $csrfToken = (string) ($payload['_token'] ?? '');

        if (!$this->isCsrfTokenValid('camera_face_verify', $csrfToken)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid security token. Please refresh the page and try again.',
            ], 400);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json([
                'success' => false,
                'message' => 'Please enter the email address for the account you want to open.',
            ], 400);
        }

        $user = $userRepository->findOneBy([
            'email' => $email,
        ]);

        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'No account was found with this email address.',
            ], 404);
        }

        if ($user->isBlocked()) {
            return $this->json([
                'success' => false,
                'message' => 'This account is blocked.',
            ], 403);
        }

        $faceLoginProfile = $faceLoginProfileRepository->findOneEnabledForUser($user);

        if (!$faceLoginProfile instanceof FaceLoginProfile) {
            return $this->json([
                'success' => false,
                'message' => 'Camera login is not enabled for this account.',
            ], 404);
        }

        $livenessPassed = (bool) ($payload['livenessPassed'] ?? false);
        $templateData = $payload['templateData'] ?? null;

        if (!$livenessPassed) {
            return $this->json([
                'success' => false,
                'message' => 'Liveness check was not completed.',
            ], 400);
        }

        if (!is_array($templateData)) {
            return $this->json([
                'success' => false,
                'message' => 'Face template is missing.',
            ], 400);
        }

        $loginVector = $templateData['vector'] ?? null;

        if (!is_array($loginVector) || count($loginVector) < 30) {
            return $this->json([
                'success' => false,
                'message' => 'Face template is incomplete.',
            ], 400);
        }

        $storedData = $faceLoginProfile->getTemplateData();
        $storedVector = $storedData['template']['vector'] ?? null;

        if (!is_array($storedVector) || count($storedVector) !== count($loginVector)) {
            return $this->json([
                'success' => false,
                'message' => 'Saved camera login template is not compatible. Please enable camera login again from your profile.',
            ], 400);
        }

        $distance = $this->calculateVectorDistance($loginVector, $storedVector);

        /*
         * Important:
         * Lower = stricter.
         * 0.08 is stricter than the old prototype value.
         * If your real face is rejected too often, try 0.10.
         * If wrong faces/photos pass, try 0.06 or 0.07.
         */
        $maximumAllowedDistance = 0.08;

        if ($distance > $maximumAllowedDistance) {
            return $this->json([
                'success' => false,
                'message' => 'Face was not recognized for this email account. Please try again with better lighting.',
                'distance' => $distance,
            ], 403);
        }

        $security->login($user, LoginFormAuthenticator::class, 'main');

        return $this->json([
            'success' => true,
            'message' => 'Camera login successful.',
            'redirectUrl' => $this->generateUrl('app_redirect_after_login'),
            'distance' => $distance,
        ]);
    }

    private function calculateVectorDistance(array $vectorA, array $vectorB): float
    {
        $count = min(count($vectorA), count($vectorB));

        if ($count === 0) {
            return 999.0;
        }

        $sum = 0.0;

        for ($index = 0; $index < $count; $index++) {
            $a = (float) $vectorA[$index];
            $b = (float) $vectorB[$index];

            $difference = $a - $b;
            $sum += $difference * $difference;
        }

        return sqrt($sum / $count);
    }
}