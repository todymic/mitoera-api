<?php

namespace App\Tests\Controller;

class CategoryControllerTest extends AbstractApiTestCase
{
    private function createChart(array $auth): string
    {
        $this->jsonRequest('POST', '/api/charts', ['name' => 'Hall', 'slug' => 'hall'], $auth);
        return $this->responseData()['id'];
    }

    // ─── GET /api/categories ─────────────────────────────────────────────────

    public function testListRequiresAuth(): void
    {
        $this->jsonRequest('GET', '/api/categories');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListIsInitiallyEmpty(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/categories', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $this->assertSame([], $this->responseData());
    }

    // ─── POST /api/categories ────────────────────────────────────────────────

    public function testCreateRequiresBackoffice(): void
    {
        $user      = $this->createUser('readonly@test.com', 'password123', ['ROLE_USER']);
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/categories',
            ['name' => 'VIP', 'key' => 'vip', 'color' => '#ff0000'],
            $this->authHeaders($user, $workspace),
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateCategory(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);
        $chartId   = $this->createChart($auth);

        $this->jsonRequest('POST', '/api/categories',
            ['name' => 'VIP', 'key' => 42, 'color' => '#ff0000', 'chartId' => $chartId],
            $auth,
        );

        $this->assertResponseStatusCodeSame(201);
        $data = $this->responseData();
        $this->assertSame('VIP', $data['name']);
        $this->assertSame(42, $data['key']);
        $this->assertSame('#ff0000', $data['color']);
    }

    public function testCreateCategoryAppearsInList(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);
        $chartId   = $this->createChart($auth);

        $this->jsonRequest('POST', '/api/categories',
            ['name' => 'Standard', 'key' => 'std', 'color' => '#0000ff', 'chartId' => $chartId],
            $auth,
        );

        $this->jsonRequest('GET', '/api/categories', headers: $auth);

        $names = array_column($this->responseData(), 'name');
        $this->assertContains('Standard', $names);
    }

    // ─── PUT /api/categories/{id} ────────────────────────────────────────────

    public function testUpdateCategory(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);
        $chartId   = $this->createChart($auth);

        $this->jsonRequest('POST', '/api/categories',
            ['name' => 'Old', 'key' => 'old', 'color' => '#aaaaaa', 'chartId' => $chartId],
            $auth,
        );
        $id = $this->responseData()['id'];

        $this->jsonRequest('PUT', "/api/categories/$id",
            ['name' => 'Updated', 'key' => 'upd', 'color' => '#bbbbbb'],
            $auth,
        );

        $this->assertJsonStatus(200);
        $this->assertSame('Updated', $this->responseData()['name']);
    }

    // ─── DELETE /api/categories/{id} ─────────────────────────────────────────

    public function testDeleteCategory(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);
        $chartId   = $this->createChart($auth);

        $this->jsonRequest('POST', '/api/categories',
            ['name' => 'To Delete', 'key' => 'del', 'color' => '#cccccc', 'chartId' => $chartId],
            $auth,
        );
        $id = $this->responseData()['id'];

        $this->jsonRequest('DELETE', "/api/categories/$id", headers: $auth);
        $this->assertJsonStatus(200);

        $this->jsonRequest('GET', '/api/categories', headers: $auth);
        $ids = array_column($this->responseData(), 'id');
        $this->assertNotContains($id, $ids);
    }
}
