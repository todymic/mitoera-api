<?php

namespace App\Tests\Controller;

class ApiKeyControllerTest extends AbstractApiTestCase
{
    // ─── GET /api/api-keys ───────────────────────────────────────────────────

    public function testListRequiresAuth(): void
    {
        $this->jsonRequest('GET', '/api/api-keys');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListIsInitiallyEmpty(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/api-keys', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $this->assertSame([], $this->responseData());
    }

    // ─── POST /api/api-keys ──────────────────────────────────────────────────

    public function testCreateBackofficeKey(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/api-keys',
            ['name' => 'My key', 'scope' => 'backoffice'],
            $this->authHeaders($user, $workspace),
        );

        $this->assertResponseStatusCodeSame(201);
        $data = $this->responseData();
        $this->assertSame('My key', $data['name']);
        $this->assertSame('backoffice', $data['scope']);
        $this->assertStringStartsWith('pk_', $data['keyId']);
        $this->assertStringStartsWith('sk_', $data['secret']);
        $this->assertStringNotContainsString('_bo_', $data['keyId']);
    }

    public function testCreatePublicKey(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/api-keys',
            ['name' => 'Widget key', 'scope' => 'public'],
            $this->authHeaders($user, $workspace),
        );

        $this->assertResponseStatusCodeSame(201);
        $data = $this->responseData();
        $this->assertSame('public', $data['scope']);
        // En env de test (APP_ENV=test), les nouvelles clés publiques ont le préfixe pk_live_
        // (test != sandbox : seul APP_ENV=sandbox génère pk_test_)
        $this->assertMatchesRegularExpression('/^pk_(live|test|pub)_/', $data['keyId']);
        $this->assertMatchesRegularExpression('/^sk_[0-9a-f]+$/', $data['secret']);
    }

    public function testCreatedKeyAppearsInList(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);

        $this->jsonRequest('POST', '/api/api-keys', ['name' => 'K1', 'scope' => 'backoffice'], $auth);
        $this->jsonRequest('POST', '/api/api-keys', ['name' => 'K2', 'scope' => 'public'], $auth);

        $this->jsonRequest('GET', '/api/api-keys', headers: $auth);

        $data  = $this->responseData();
        $names = array_column($data, 'name');
        $this->assertContains('K1', $names);
        $this->assertContains('K2', $names);
    }

    public function testKeysAreIsolatedPerWorkspace(): void
    {
        $user  = $this->createUser();
        $ws1   = $this->createWorkspaceForUser($user, 'WS1');
        $ws2   = $this->createWorkspaceForUser($user, 'WS2');

        $this->jsonRequest('POST', '/api/api-keys',
            ['name' => 'WS1 key', 'scope' => 'backoffice'],
            $this->authHeaders($user, $ws1),
        );

        $this->jsonRequest('GET', '/api/api-keys', headers: $this->authHeaders($user, $ws2));

        $this->assertSame([], $this->responseData());
    }

    // ─── DELETE /api/api-keys/{id} ───────────────────────────────────────────

    public function testDeleteKey(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);

        $this->jsonRequest('POST', '/api/api-keys', ['name' => 'To delete', 'scope' => 'backoffice'], $auth);
        $keyId = $this->responseData()['id'];

        $this->jsonRequest('DELETE', "/api/api-keys/{$keyId}", headers: $auth);

        $this->assertJsonStatus(200);

        $this->jsonRequest('GET', '/api/api-keys', headers: $auth);
        $active = array_filter($this->responseData(), fn($k) => $k['active']);
        $this->assertEmpty($active);
    }
}
