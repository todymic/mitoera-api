<?php

namespace App\Controller;

use App\Dto\UserResponse;
use App\Entity\User;
use App\Service\UserService;
use OpenApi\Attributes as OA;
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
    #[OA\Tag(name: 'Auth')]
    #[OA\Post(summary: 'Créer un compte back-office', security: [])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['email', 'password'], properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'password', type: 'string', minLength: 8),
        new OA\Property(property: 'firstName', type: 'string'),
        new OA\Property(property: 'lastName', type: 'string'),
    ]))]
    #[OA\Response(response: 201, description: 'Compte créé')]
    #[OA\Response(response: 400, description: 'Email déjà utilisé ou mot de passe trop court')]
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
    #[OA\Tag(name: 'Auth')]
    #[OA\Post(summary: 'Connexion back-office — retourne un JWT', security: [])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['email', 'password'], properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@mitoera.com'),
        new OA\Property(property: 'password', type: 'string', example: 'motdepasse'),
    ]))]
    #[OA\Response(response: 200, description: 'JWT retourné', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'token', type: 'string', description: 'JWT à passer dans Authorization: Bearer'),
    ]))]
    #[OA\Response(response: 401, description: 'Identifiants invalides')]
    public function login(): JsonResponse
    {
        return $this->json(['message' => 'Login endpoint']);
    }

    #[Route('/me', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Auth')]
    #[OA\Get(summary: 'Profil de l\'utilisateur connecté')]
    #[OA\Response(response: 200, description: 'Profil utilisateur')]
    #[OA\Response(response: 401, description: 'Non authentifié')]
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
