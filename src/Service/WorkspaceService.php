<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\WorkspaceMemberRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;

class WorkspaceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMemberRepository $memberRepository,
    ) {}

    public function createForUser(User $user, string $name): Workspace
    {
        $workspace = new Workspace();
        $workspace->setName($name);
        $workspace->setSlug($this->generateSlug($name));

        $member = new WorkspaceMember();
        $member->setWorkspace($workspace);
        $member->setUser($user);
        $member->setRole('owner');

        $this->em->persist($workspace);
        $this->em->persist($member);
        $this->em->flush();

        return $workspace;
    }

    public function getForUser(User $user): ?Workspace
    {
        return $this->workspaceRepository->findOneByUser($user);
    }

    /** @return Workspace[] */
    public function getAllForUser(User $user): array
    {
        return $this->workspaceRepository->findByUser($user);
    }

    public function addMember(Workspace $workspace, User $user, string $role = 'member'): WorkspaceMember
    {
        $existing = $this->memberRepository->findOneByWorkspaceAndUser($workspace, $user);
        if ($existing) {
            return $existing;
        }

        $member = new WorkspaceMember();
        $member->setWorkspace($workspace);
        $member->setUser($user);
        $member->setRole($role);

        $this->em->persist($member);
        $this->em->flush();

        return $member;
    }

    public function removeMember(Workspace $workspace, User $user): void
    {
        $member = $this->memberRepository->findOneByWorkspaceAndUser($workspace, $user);
        if ($member) {
            $this->em->remove($member);
            $this->em->flush();
        }
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $slug = trim($slug, '-');
        $base = $slug;
        $i = 1;
        while ($this->workspaceRepository->findOneBy(['slug' => $slug])) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
