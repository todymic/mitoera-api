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

    // ─── GET /api/auth/verify-email ──────────────────────────────────────────

    public function testVerifyEmailWithValidToken(): void
    {
        $user = $this->createUser('verify@test.com');
        $user->setValidated(false);
        $user->setEmailVerificationToken('validtoken123');
        $user->setEmailVerificationSentAt(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        $this->client->request('GET', '/api/auth/verify-email?token=validtoken123');

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('verified=1', $this->client->getResponse()->getContent());
    }

    public function testVerifyEmailWithInvalidToken(): void
    {
        $this->client->request('GET', '/api/auth/verify-email?token=nonexistent');

        $this->assertResponseStatusCodeSame(302);
        $body = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('error=', $body);
    }

    public function testVerifyEmailWithExpiredToken(): void
    {
        $user = $this->createUser('expired@test.com');
        $user->setValidated(false);
        $user->setEmailVerificationToken('expiredtoken123');
        $user->setEmailVerificationSentAt(new \DateTimeImmutable('-25 hours'));
        $this->em->flush();

        $this->client->request('GET', '/api/auth/verify-email?token=expiredtoken123');

        $this->assertResponseStatusCodeSame(302);
        $body = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('error=', $body);
    }

    public function testVerifyEmailSetsUserAsValidated(): void
    {
        $user = $this->createUser('tovalidate@test.com');
        $user->setValidated(false);
        $user->setEmailVerificationToken('mytoken456');
        $user->setEmailVerificationSentAt(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        $this->client->request('GET', '/api/auth/verify-email?token=mytoken456');

        $this->em->refresh($user);
        $this->assertTrue($user->isValidated());
        $this->assertNull($user->getEmailVerificationToken());
    }

    // ─── POST /api/auth/resend-verification ──────────────────────────────────

    public function testResendVerificationAlwaysReturns200(): void
    {
        $this->jsonRequest('POST', '/api/auth/resend-verification', ['email' => 'nobody@test.com']);

        $this->assertJsonStatus(200);
    }

    public function testResendVerificationForUnvalidatedUser(): void
    {
        $user = $this->createUser('unvalidated@test.com');
        $user->setValidated(false);
        $this->em->flush();

        $this->jsonRequest('POST', '/api/auth/resend-verification', ['email' => 'unvalidated@test.com']);

        $this->assertJsonStatus(200);
        $this->assertStringContainsString('envoyé', $this->responseData()['message']);
    }

    public function testResendVerificationForValidatedUserStillReturns200(): void
    {
        $this->createUser('validated@test.com');

        $this->jsonRequest('POST', '/api/auth/resend-verification', ['email' => 'validated@test.com']);

        // Anti-enumeration: same 200 response regardless
        $this->assertJsonStatus(200);
    }
}
