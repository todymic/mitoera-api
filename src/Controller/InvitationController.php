<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\WorkspaceMember;
use App\Repository\UserRepository;
use App\Repository\WorkspaceInvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/invitations')]
class InvitationController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceInvitationRepository $invitationRepo,
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    #[Route('/{token}', methods: ['GET'])]
    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitationRepo->findValidByToken($token);
        if (!$invitation) {
            return $this->json(['error' => 'Invitation invalide ou expirée'], 404);
        }

        $existingUser = $this->userRepo->findByEmail($invitation->getEmail());

        return $this->json([
            'email'         => $invitation->getEmail(),
            'workspaceName' => $invitation->getWorkspace()->getName(),
            'userExists'    => $existingUser !== null,
        ]);
    }

    #[Route('/{token}/accept', methods: ['POST'])]
    public function accept(string $token, Request $request): JsonResponse
    {
        $invitation = $this->invitationRepo->findValidByToken($token);
        if (!$invitation) {
            return $this->json(['error' => 'Invitation invalide ou expirée'], 404);
        }

        $data     = json_decode($request->getContent(), true);
        $password = $data['password'] ?? '';

        if (strlen($password) < 8) {
            return $this->json(['error' => 'Le mot de passe doit contenir au moins 8 caractères'], 400);
        }

        $workspace = $invitation->getWorkspace();
        $email     = $invitation->getEmail();

        $user = $this->userRepo->findByEmail($email);

        if ($user) {
            // Utilisateur existant — vérifie le mot de passe
            if (!$this->passwordHasher->isPasswordValid($user, $password)) {
                return $this->json(['error' => 'Mot de passe incorrect'], 401);
            }
        } else {
            // Nouvel utilisateur
            $firstName = trim($data['firstName'] ?? '');
            $lastName  = trim($data['lastName'] ?? '');

            $user = new User();
            $user->setEmail($email);
            $user->setRoles(['ROLE_BACKOFFICE']);
            $user->setFirstName($firstName ?: null);
            $user->setLastName($lastName ?: null);
            $hashed = $this->passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashed);
            $this->em->persist($user);
        }

        // Vérifie qu'il n'est pas déjà membre
        $alreadyMember = false;
        foreach ($workspace->getMembers() as $m) {
            if ($m->getUser()->getEmail() === $email) {
                $alreadyMember = true;
                break;
            }
        }

        if (!$alreadyMember) {
            $member = new WorkspaceMember();
            $member->setWorkspace($workspace);
            $member->setUser($user);
            $member->setRole('member');
            $this->em->persist($member);
        }

        $invitation->accept();
        $this->em->flush();

        $jwtToken = $this->jwtManager->createFromPayload($user, [
            'workspaceId' => (string) $workspace->getId(),
        ]);

        return $this->json(['token' => $jwtToken]);
    }
}
