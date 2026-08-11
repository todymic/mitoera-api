<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\WorkspaceInvitation;
use App\Service\WorkspaceContext;
use App\Service\WorkspaceService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/workspaces')]
#[IsGranted('ROLE_BACKOFFICE')]
#[OA\Tag(name: 'Workspace')]
class WorkspaceController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceService $workspaceService,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
    ) {}

    #[Route('', methods: ['GET'])]
    #[OA\Get(summary: 'Liste tous les workspaces de l\'utilisateur connecté')]
    #[OA\Response(response: 200, description: 'Liste des workspaces')]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspaces = $this->workspaceService->getAllForUser($user);
        $current = $this->workspaceContext->getWorkspace();

        $data = array_map(fn($w) => [
            'id'        => (string) $w->getId(),
            'name'      => $w->getName(),
            'slug'      => $w->getSlug(),
            'createdAt' => $w->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'current'   => $current && (string) $w->getId() === (string) $current->getId(),
        ], $workspaces);

        return $this->json($data);
    }

    #[Route('/current', methods: ['GET'])]
    #[OA\Get(summary: 'Workspace actif (extrait du JWT)')]
    #[OA\Response(response: 200, description: 'Workspace courant')]
    public function current(): JsonResponse
    {
        $workspace = $this->workspaceContext->getWorkspace();
        if (!$workspace) {
            return $this->json(['error' => 'No workspace found'], 404);
        }

        return $this->json([
            'id'        => (string) $workspace->getId(),
            'name'      => $workspace->getName(),
            'slug'      => $workspace->getSlug(),
            'createdAt' => $workspace->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}/switch', methods: ['POST'])]
    #[OA\Post(summary: 'Changer de workspace actif — retourne un nouveau JWT')]
    #[OA\Response(response: 200, description: 'Nouveau token JWT')]
    #[OA\Response(response: 403, description: 'Workspace non autorisé')]
    public function switch(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspaces = $this->workspaceService->getAllForUser($user);

        $target = null;
        foreach ($workspaces as $w) {
            if ((string) $w->getId() === $id) {
                $target = $w;
                break;
            }
        }

        if (!$target) {
            return $this->json(['error' => 'Workspace not found or not accessible'], 403);
        }

        $token = $this->jwtManager->createFromPayload($user, [
            'workspaceId' => (string) $target->getId(),
        ]);

        return $this->json(['token' => $token]);
    }

    #[Route('/invite', methods: ['POST'])]
    #[OA\Post(summary: 'Inviter un membre par email')]
    #[OA\Response(response: 200, description: 'Invitation envoyée')]
    #[OA\Response(response: 400, description: 'Email invalide ou déjà membre')]
    public function invite(Request $request): JsonResponse
    {
        $workspace = $this->workspaceContext->getWorkspace();
        if (!$workspace) {
            return $this->json(['error' => 'No workspace found'], 404);
        }

        $data  = json_decode($request->getContent(), true);
        $email = trim($data['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Email invalide'], 400);
        }

        // Vérifie si déjà membre
        foreach ($workspace->getMembers() as $member) {
            if ($member->getUser()->getEmail() === $email) {
                return $this->json(['error' => 'Cet utilisateur est déjà membre du workspace'], 409);
            }
        }

        /** @var User $inviter */
        $inviter    = $this->getUser();
        $invitation = new WorkspaceInvitation($workspace, $email);
        $this->em->persist($invitation);
        $this->em->flush();

        $invitationUrl = sprintf(
            'https://mitoera.com/admin/accept-invitation?token=%s',
            $invitation->getToken()
        );

        $mail = new TemplatedEmail();
        $mail->from('noreply@mitoera.com')
             ->to($email)
             ->subject(sprintf('[Mitoera] %s vous invite à rejoindre %s', $inviter->getDisplayName(), $workspace->getName()))
             ->htmlTemplate('emails/invitation.html.twig')
             ->context([
                 'inviterName'   => $inviter->getDisplayName(),
                 'workspaceName' => $workspace->getName(),
                 'invitationUrl' => $invitationUrl,
             ]);

        try {
            $this->mailer->send($mail);
        } catch (TransportExceptionInterface) {
            return $this->json(['error' => 'Erreur d\'envoi de l\'email'], 500);
        }

        return $this->json(['message' => 'Invitation envoyée à ' . $email]);
    }

    #[Route('/members', methods: ['GET'])]
    #[OA\Get(summary: 'Membres du workspace courant')]
    #[OA\Response(response: 200, description: 'Liste des membres')]
    public function members(): JsonResponse
    {
        $workspace = $this->workspaceContext->getWorkspace();
        if (!$workspace) {
            return $this->json(['error' => 'No workspace found'], 404);
        }

        $members = array_map(fn($m) => [
            'id'       => (string) $m->getId(),
            'email'    => $m->getUser()->getEmail(),
            'name'     => $m->getUser()->getDisplayName(),
            'role'     => $m->getRole(),
            'joinedAt' => $m->getJoinedAt()->format(\DateTimeInterface::ATOM),
        ], $workspace->getMembers()->toArray());

        return $this->json($members);
    }
}
