<?php

namespace App\Tests\Controller;

class ResolveKeyControllerTest extends AbstractApiTestCase
{
    public function testResolveKeyReturnsEnvironment(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        // Create a public API key
        $this->jsonRequest('POST', '/api/api-keys',
            ['name' => 'Test key', 'scope' => 'public'],
            $this->authHeaders($user, $workspace),
        );
        $this->assertResponseStatusCodeSame(201);
        $keyId = $this->responseData()['keyId'];

        // Resolve without authentication
        $this->jsonRequest('GET', '/api/resolve-key?keyId=' . $keyId);

        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertArrayHasKey('environment', $data);
        $this->assertContains($data['environment'], ['live', 'test']);
        $this->assertArrayHasKey('scope', $data);
        $this->assertSame('public', $data['scope']);
    }

    public function testResolveKeyReturns404ForUnknownKey(): void
    {
        $this->jsonRequest('GET', '/api/resolve-key?keyId=pk_live_unknown');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testResolveKeyReturns400WhenKeyIdMissing(): void
    {
        $this->jsonRequest('GET', '/api/resolve-key');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testResolveKeyDoesNotExposeSecretOrHash(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/api-keys',
            ['name' => 'No leak', 'scope' => 'public'],
            $this->authHeaders($user, $workspace),
        );
        $keyId = $this->responseData()['keyId'];

        $this->jsonRequest('GET', '/api/resolve-key?keyId=' . $keyId);

        $data = $this->responseData();
        $this->assertArrayNotHasKey('secret', $data);
        $this->assertArrayNotHasKey('secretHash', $data);
        $this->assertArrayNotHasKey('keyId', $data);
    }
}
