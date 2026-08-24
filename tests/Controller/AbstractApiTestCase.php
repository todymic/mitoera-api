<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class AbstractApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function createUser(string $email = 'test@mitoera.com', string $password = 'password123', array $roles = ['ROLE_USER', 'ROLE_BACKOFFICE']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, $password));
        $user->setValidated(true);
        $user->setActive(true);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function createWorkspaceForUser(User $user, string $name = 'Test Workspace'): Workspace
    {
        $workspace = new Workspace();
        $workspace->setName($name);
        $workspace->setSlug(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name)));

        $member = new WorkspaceMember();
        $member->setWorkspace($workspace);
        $member->setUser($user);
        $member->setRole('owner');

        $this->em->persist($workspace);
        $this->em->persist($member);
        $this->em->flush();

        return $workspace;
    }

    protected function jwtFor(User $user, ?Workspace $workspace = null): string
    {
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $payload = [];
        if ($workspace) {
            $payload['workspaceId'] = (string) $workspace->getId();
        }
        return $jwtManager->createFromPayload($user, $payload);
    }

    protected function authHeaders(User $user, ?Workspace $workspace = null): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($user, $workspace)];
    }

    protected function jsonRequest(string $method, string $uri, array $data = [], array $headers = []): void
    {
        $this->client->request(
            $method,
            $uri,
            [],
            [],
            array_merge(['CONTENT_TYPE' => 'application/json'], $headers),
            $data ? json_encode($data) : null,
        );
    }

    protected function responseData(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    protected function assertJsonStatus(int $status): void
    {
        $this->assertResponseStatusCodeSame($status);
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
