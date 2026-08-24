<?php

namespace App\Tests\Controller;

use App\Entity\Chart;

class ChartControllerTest extends AbstractApiTestCase
{
    // ─── GET /api/charts ─────────────────────────────────────────────────────

    public function testListRequiresAuth(): void
    {
        $this->jsonRequest('GET', '/api/charts');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListIsInitiallyEmpty(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/charts', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $this->assertSame([], $this->responseData());
    }

    // ─── POST /api/charts ────────────────────────────────────────────────────

    public function testCreateRequiresBackoffice(): void
    {
        $user      = $this->createUser('readonly@test.com', 'password123', ['ROLE_USER']);
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/charts',
            ['name' => 'Hall', 'slug' => 'hall'],
            $this->authHeaders($user, $workspace),
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateChart(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/charts',
            ['name' => 'Main Hall', 'slug' => 'main-hall'],
            $this->authHeaders($user, $workspace),
        );

        $this->assertResponseStatusCodeSame(201);
        $data = $this->responseData();
        $this->assertSame('Main Hall', $data['name']);
        $this->assertSame('main-hall', $data['slug']);
        $this->assertArrayHasKey('id', $data);
    }

    public function testCreateChartAppearsInList(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);

        $this->jsonRequest('POST', '/api/charts', ['name' => 'Hall A', 'slug' => 'hall-a'], $auth);

        $this->jsonRequest('GET', '/api/charts', headers: $auth);

        $data  = $this->responseData();
        $names = array_column($data, 'name');
        $this->assertContains('Hall A', $names);
    }

    // ─── GET /api/charts/{id} ────────────────────────────────────────────────

    public function testGetChart(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);

        $this->jsonRequest('POST', '/api/charts', ['name' => 'Hall B', 'slug' => 'hall-b'], $auth);
        $id = $this->responseData()['id'];

        $this->jsonRequest('GET', "/api/charts/$id", headers: $auth);

        $this->assertJsonStatus(200);
        $this->assertSame('Hall B', $this->responseData()['name']);
    }

    public function testGetUnknownChartReturns404(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/charts/00000000-0000-0000-0000-000000000000',
            headers: $this->authHeaders($user, $workspace),
        );

        $this->assertResponseStatusCodeSame(404);
    }

    // ─── PUT /api/charts/{id} ────────────────────────────────────────────────

    public function testUpdateChart(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);

        $this->jsonRequest('POST', '/api/charts', ['name' => 'Old Name', 'slug' => 'old-name'], $auth);
        $id = $this->responseData()['id'];

        $this->jsonRequest('PUT', "/api/charts/$id", ['name' => 'New Name', 'slug' => 'new-name'], $auth);

        $this->assertJsonStatus(200);
        $this->assertSame('New Name', $this->responseData()['name']);
    }

    // ─── DELETE /api/charts/{id} ─────────────────────────────────────────────

    public function testDeleteChart(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);

        $this->jsonRequest('POST', '/api/charts', ['name' => 'To Delete', 'slug' => 'to-delete'], $auth);
        $id = $this->responseData()['id'];

        $this->jsonRequest('DELETE', "/api/charts/$id", headers: $auth);
        $this->assertJsonStatus(200);

        $this->jsonRequest('GET', "/api/charts/$id", headers: $auth);
        $this->assertResponseStatusCodeSame(404);
    }

    // ─── Legacy null-workspace charts appear in list ──────────────────────────

    public function testLegacyNullWorkspaceChartAppearsInList(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        // Simulate a chart created before workspace support (workspace = null)
        $chart = new Chart();
        $chart->setName('Legacy Hall');
        $chart->setSlug('legacy-hall');
        // workspace intentionally left null
        $this->em->persist($chart);
        $this->em->flush();

        $this->jsonRequest('GET', '/api/charts', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $names = array_column($this->responseData(), 'name');
        $this->assertContains('Legacy Hall', $names);
    }

    public function testWorkspacedChartNotVisibleToOtherWorkspace(): void
    {
        $userA      = $this->createUser('a@test.com');
        $workspaceA = $this->createWorkspaceForUser($userA, 'Workspace A');

        $userB      = $this->createUser('b@test.com');
        $workspaceB = $this->createWorkspaceForUser($userB, 'Workspace B');

        // Create chart scoped to workspace A
        $this->jsonRequest('POST', '/api/charts',
            ['name' => 'A-only Hall', 'slug' => 'a-only'],
            $this->authHeaders($userA, $workspaceA),
        );
        $this->assertResponseStatusCodeSame(201);

        // Workspace B should not see workspace A's chart
        $this->jsonRequest('GET', '/api/charts', headers: $this->authHeaders($userB, $workspaceB));
        $names = array_column($this->responseData(), 'name');
        $this->assertNotContains('A-only Hall', $names);
    }
}
