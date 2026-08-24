<?php

namespace App\Tests\Controller;

class AuthControllerTest extends AbstractApiTestCase
{
    // ─── POST /api/auth/login ────────────────────────────────────────────────

    public function testLoginSuccess(): void
    {
        $this->createUser('user@test.com');

        $this->jsonRequest('POST', '/api/auth/login', [
            'email'    => 'user@test.com',
            'password' => 'password123',
        ]);

        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEmpty($data['token']);
    }

    public function testLoginWrongPassword(): void
    {
        $this->createUser('user@test.com');

        $this->jsonRequest('POST', '/api/auth/login', [
            'email'    => 'user@test.com',
            'password' => 'wrong',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginUnknownUser(): void
    {
        $this->jsonRequest('POST', '/api/auth/login', [
            'email'    => 'nobody@test.com',
            'password' => 'password123',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    // ─── GET /api/auth/me ────────────────────────────────────────────────────

    public function testMeReturnsCurrentUser(): void
    {
        $user = $this->createUser('me@test.com');

        $this->jsonRequest('GET', '/api/auth/me', headers: $this->authHeaders($user));

        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertSame('me@test.com', $data['email']);
    }

    public function testMeRequiresAuth(): void
    {
        $this->jsonRequest('GET', '/api/auth/me');

        $this->assertResponseStatusCodeSame(401);
    }

    // ─── POST /api/auth/embed-token ──────────────────────────────────────────

    public function testEmbedTokenWithBackofficeKey(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        // Create a backoffice API key
        $this->jsonRequest('POST', '/api/api-keys',
            ['name' => 'BO key', 'scope' => 'backoffice'],
            $this->authHeaders($user, $workspace),
        );
        $key = $this->responseData();

        $this->jsonRequest('POST', '/api/auth/embed-token', [
            'keyId'  => $key['keyId'],
            'secret' => $key['secret'],
        ]);

        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('workspaceId', $data);
    }

    public function testEmbedTokenWithPublicKeyIsRejected(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/api-keys',
            ['name' => 'Pub key', 'scope' => 'public'],
            $this->authHeaders($user, $workspace),
        );
        $key = $this->responseData();

        $this->jsonRequest('POST', '/api/auth/embed-token', [
            'keyId'  => $key['keyId'],
            'secret' => $key['secret'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testEmbedTokenMissingFields(): void
    {
        $this->jsonRequest('POST', '/api/auth/embed-token');

        $this->assertResponseStatusCodeSame(400);
    }
}
