<?php

namespace App\Tests\Controller;

class WorkspaceControllerTest extends AbstractApiTestCase
{
    // ─── GET /api/workspaces ─────────────────────────────────────────────────

    public function testListWorkspacesRequiresAuth(): void
    {
        $this->jsonRequest('GET', '/api/workspaces');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListWorkspacesReturnsOwnWorkspaces(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $other = $this->createUser('other@test.com');
        $this->createWorkspaceForUser($other, 'Other WS');

        $this->jsonRequest('GET', '/api/workspaces', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertCount(1, $data);
        $this->assertSame($workspace->getName(), $data[0]['name']);
    }

    public function testListWorkspacesMarksCurrent(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/workspaces', headers: $this->authHeaders($user, $workspace));

        $data = $this->responseData();
        $this->assertTrue($data[0]['current']);
    }

    // ─── POST /api/workspaces ────────────────────────────────────────────────

    public function testCreateWorkspaceRequiresAuth(): void
    {
        $this->jsonRequest('POST', '/api/workspaces', ['name' => 'New WS']);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateWorkspace(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/workspaces',
            ['name' => 'Second WS'],
            $this->authHeaders($user, $workspace),
        );

        $this->assertResponseStatusCodeSame(201);
        $data = $this->responseData();
        $this->assertSame('Second WS', $data['name']);
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('slug', $data);
    }

    public function testCreateWorkspaceRequiresName(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/workspaces',
            ['name' => ''],
            $this->authHeaders($user, $workspace),
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWorkspaceAppearsInList(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/workspaces',
            ['name' => 'Second WS'],
            $this->authHeaders($user, $workspace),
        );

        $this->jsonRequest('GET', '/api/workspaces', headers: $this->authHeaders($user, $workspace));

        $data  = $this->responseData();
        $names = array_column($data, 'name');
        $this->assertContains('Second WS', $names);
    }

    // ─── GET /api/workspaces/current ─────────────────────────────────────────

    public function testCurrentWorkspace(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/workspaces/current', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertSame((string) $workspace->getId(), $data['id']);
    }

    public function testCurrentWorkspaceWithoutWorkspaceInToken(): void
    {
        $user = $this->createUser();

        $this->jsonRequest('GET', '/api/workspaces/current', headers: $this->authHeaders($user));

        $this->assertResponseStatusCodeSame(404);
    }

    // ─── POST /api/workspaces/{id}/switch ────────────────────────────────────

    public function testSwitchWorkspace(): void
    {
        $user = $this->createUser();
        $ws1  = $this->createWorkspaceForUser($user, 'WS One');
        $ws2  = $this->createWorkspaceForUser($user, 'WS Two');

        $this->jsonRequest('POST', "/api/workspaces/{$ws2->getId()}/switch",
            headers: $this->authHeaders($user, $ws1),
        );

        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertArrayHasKey('token', $data);
    }

    public function testSwitchToForbiddenWorkspace(): void
    {
        $user  = $this->createUser();
        $ws    = $this->createWorkspaceForUser($user);
        $other = $this->createUser('other@test.com');
        $wsOther = $this->createWorkspaceForUser($other, 'Other WS');

        $this->jsonRequest('POST', "/api/workspaces/{$wsOther->getId()}/switch",
            headers: $this->authHeaders($user, $ws),
        );

        $this->assertResponseStatusCodeSame(403);
    }

    // ─── GET /api/workspaces/members ─────────────────────────────────────────

    public function testListMembers(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/workspaces/members', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertCount(1, $data);
        $this->assertSame('owner', $data[0]['role']);
        $this->assertSame($user->getEmail(), $data[0]['email']);
    }
}
