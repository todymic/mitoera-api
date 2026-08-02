<?php

namespace App\Controller;

use App\Dto\UserResponse;
use App\Entity\User;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private UserService $userService,
    ) {
    }

    #[Route('/register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->userService->register(
                $data['email'] ?? '',
                $data['password'] ?? '',
                $data['firstName'] ?? null,
                $data['lastName'] ?? null,
            );

            return $this->json($this->toResponse($user), Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return $this->json(['message' => 'Login endpoint']);
    }

    #[Route('/me', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function me(): JsonResponse
    {
        return $this->json($this->toResponse($this->getUser()));
    }

    #[Route('/profile', methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function updateProfile(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $user = $this->getUser();
        assert($user instanceof User);

        try {
            $updated = $this->userService->updateProfile(
                $user,
                $data['firstName'] ?? null,
                $data['lastName'] ?? null,
                $data['email'] ?? null,
            );
            return $this->json($this->toResponse($updated));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/change-password', methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function changePassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $user = $this->getUser();
        assert($user instanceof User);

        try {
            $this->userService->changePassword(
                $user,
                $data['currentPassword'] ?? '',
                $data['newPassword'] ?? '',
            );
            return $this->json(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/forgot-password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $data  = json_decode($request->getContent(), true) ?? [];
        $email = $data['email'] ?? '';

        $token = $this->userService->createPasswordResetToken($email);

        // En production, envoyer le token par email.
        // En dev, on le retourne directement pour faciliter les tests.
        if ($_ENV['APP_ENV'] === 'dev' && $token) {
            return $this->json(['resetToken' => $token]);
        }

        return $this->json(['success' => true]);
    }

    #[Route('/reset-password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        try {
            $this->userService->resetPassword(
                $data['token'] ?? '',
                $data['password'] ?? '',
            );
            return $this->json(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    private function toResponse($user): UserResponse
    {
        return new UserResponse(
            $user->getId(),
            $user->getEmail(),
            $user->getDisplayName(),
            $user->getFirstName(),
            $user->getLastName(),
            $user->getRoles(),
            $user->getCreatedAt(),
            $user->getLastLoginAt(),
        );
    }
}
