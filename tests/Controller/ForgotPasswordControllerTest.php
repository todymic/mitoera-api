<?php

namespace App\Tests\Controller;

use App\Entity\PasswordResetToken;
use Symfony\Component\Mime\Email;

class ForgotPasswordControllerTest extends AbstractApiTestCase
{
    // ─── POST /api/auth/forgot-password ─────────────────────────────────────

    public function testForgotPasswordSendsEmailAndReturnsSuccess(): void
    {
        $this->createUser('reset@test.com');

        $this->jsonRequest('POST', '/api/auth/forgot-password', [
            'email' => 'reset@test.com',
        ]);

        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);

        // Vérifier que l'email a été mis en file d'envoi
        $mailerEvents = self::getMailerEvents();
        $this->assertCount(1, $mailerEvents);

        /** @var Email $email */
        $email = $mailerEvents[0]->getMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertStringContainsString('reset@test.com', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('mot de passe', strtolower($email->getSubject()));
        $this->assertStringContainsString('/login?token=', $email->getHtmlBody());
    }

    public function testForgotPasswordUnknownEmailReturnsSilentSuccess(): void
    {
        $this->jsonRequest('POST', '/api/auth/forgot-password', [
            'email' => 'nobody@test.com',
        ]);

        // Ne pas révéler si l'email existe ou non
        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertArrayHasKey('success', $data);

        // Aucun email envoyé
        $this->assertCount(0, self::getMailerEvents());
    }

    public function testForgotPasswordCreatesTokenInDb(): void
    {
        $this->createUser('dbtoken@test.com');

        $this->jsonRequest('POST', '/api/auth/forgot-password', [
            'email' => 'dbtoken@test.com',
        ]);

        $this->assertJsonStatus(200);

        $token = $this->em->getRepository(PasswordResetToken::class)->findOneBy([]);
        $this->assertNotNull($token);
        $this->assertFalse($token->isUsed());
        $this->assertNotEmpty($token->getToken());
    }

    // ─── POST /api/auth/reset-password ──────────────────────────────────────

    public function testResetPasswordSuccess(): void
    {
        $this->createUser('reset2@test.com', 'oldpassword');

        // Déclencher la création du token
        $this->jsonRequest('POST', '/api/auth/forgot-password', [
            'email' => 'reset2@test.com',
        ]);

        // Récupérer le token depuis la DB
        $resetToken = $this->em->getRepository(PasswordResetToken::class)->findOneBy([]);
        $this->assertNotNull($resetToken);
        $tokenValue = $resetToken->getToken();

        // Réinitialiser le mot de passe
        $this->jsonRequest('POST', '/api/auth/reset-password', [
            'token'    => $tokenValue,
            'password' => 'newpassword123',
        ]);

        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertTrue($data['success']);

        // Vérifier que le token est marqué comme utilisé (re-fetch depuis DB)
        $this->em->clear();
        $freshToken = $this->em->getRepository(PasswordResetToken::class)->findOneBy(['token' => $tokenValue]);
        $this->assertNotNull($freshToken);
        $this->assertTrue($freshToken->isUsed());
    }

    public function testResetPasswordAllowsLoginWithNewPassword(): void
    {
        $this->createUser('flow@test.com', 'oldpassword');

        $this->jsonRequest('POST', '/api/auth/forgot-password', [
            'email' => 'flow@test.com',
        ]);

        $resetToken = $this->em->getRepository(PasswordResetToken::class)->findOneBy([]);
        $tokenValue = $resetToken->getToken();

        $this->jsonRequest('POST', '/api/auth/reset-password', [
            'token'    => $tokenValue,
            'password' => 'mynewpassword',
        ]);
        $this->assertJsonStatus(200);

        // Se connecter avec le nouveau mot de passe
        $this->jsonRequest('POST', '/api/auth/login', [
            'email'    => 'flow@test.com',
            'password' => 'mynewpassword',
        ]);
        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertArrayHasKey('token', $data);
    }

    public function testResetPasswordOldPasswordNoLongerWorks(): void
    {
        $this->createUser('flow2@test.com', 'oldpassword');

        $this->jsonRequest('POST', '/api/auth/forgot-password', [
            'email' => 'flow2@test.com',
        ]);

        $resetToken = $this->em->getRepository(PasswordResetToken::class)->findOneBy([]);

        $this->jsonRequest('POST', '/api/auth/reset-password', [
            'token'    => $resetToken->getToken(),
            'password' => 'brandnewpass',
        ]);

        $this->jsonRequest('POST', '/api/auth/login', [
            'email'    => 'flow2@test.com',
            'password' => 'oldpassword',
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testResetPasswordInvalidToken(): void
    {
        $this->jsonRequest('POST', '/api/auth/reset-password', [
            'token'    => 'invalidtoken',
            'password' => 'newpassword123',
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = $this->responseData();
        $this->assertArrayHasKey('error', $data);
    }

    public function testResetPasswordTokenCanOnlyBeUsedOnce(): void
    {
        $this->createUser('once@test.com');

        $this->jsonRequest('POST', '/api/auth/forgot-password', [
            'email' => 'once@test.com',
        ]);

        $resetToken = $this->em->getRepository(PasswordResetToken::class)->findOneBy([]);
        $tokenValue = $resetToken->getToken();

        $this->jsonRequest('POST', '/api/auth/reset-password', [
            'token'    => $tokenValue,
            'password' => 'firstreset123',
        ]);
        $this->assertJsonStatus(200);

        // Deuxième utilisation du même token
        $this->jsonRequest('POST', '/api/auth/reset-password', [
            'token'    => $tokenValue,
            'password' => 'secondreset456',
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testResetPasswordTooShort(): void
    {
        $this->createUser('short@test.com');

        $this->jsonRequest('POST', '/api/auth/forgot-password', [
            'email' => 'short@test.com',
        ]);

        $resetToken = $this->em->getRepository(PasswordResetToken::class)->findOneBy([]);

        $this->jsonRequest('POST', '/api/auth/reset-password', [
            'token'    => $resetToken->getToken(),
            'password' => 'abc',
        ]);
        $this->assertResponseStatusCodeSame(400);
        $data = $this->responseData();
        $this->assertArrayHasKey('error', $data);
    }


}
