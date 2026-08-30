<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use App\Service\UserService;
use App\Service\WorkspaceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class UserServiceTest extends TestCase
{
    private UserRepository&MockObject             $userRepo;
    private PasswordResetTokenRepository&MockObject $resetTokenRepo;
    private EntityManagerInterface&MockObject     $em;
    private UserPasswordHasherInterface&MockObject $hasher;
    private WorkspaceService&MockObject           $workspaceService;
    private MailerInterface&MockObject            $mailer;
    private UserService                           $service;

    protected function setUp(): void
    {
        $this->userRepo         = $this->createMock(UserRepository::class);
        $this->resetTokenRepo   = $this->createMock(PasswordResetTokenRepository::class);
        $this->em               = $this->createMock(EntityManagerInterface::class);
        $this->hasher           = $this->createMock(UserPasswordHasherInterface::class);
        $this->workspaceService = $this->createMock(WorkspaceService::class);
        $this->mailer           = $this->createMock(MailerInterface::class);

        $this->service = new UserService(
            userRepository:       $this->userRepo,
            resetTokenRepository: $this->resetTokenRepo,
            em:                   $this->em,
            passwordHasher:       $this->hasher,
            workspaceService:     $this->workspaceService,
            mailer:               $this->mailer,
            appUrl:               'https://api.mitoera.com',
        );
    }

    private function makeUser(string $email = 'user@test.com'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($email);
        return $user;
    }

    // ── sendVerificationEmail() ───────────────────────────────────────────────

    public function testSendVerificationEmailSetsToken(): void
    {
        $user = $this->makeUser();
        $this->em->expects($this->once())->method('flush');
        $this->mailer->expects($this->once())->method('send');

        $this->service->sendVerificationEmail($user);

        $this->assertNotNull($user->getEmailVerificationToken());
        $this->assertNotNull($user->getEmailVerificationSentAt());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $user->getEmailVerificationToken());
    }

    public function testSendVerificationEmailSendsToCorrectAddress(): void
    {
        $user = $this->makeUser('recipient@test.com');
        $this->em->method('flush');

        $sentEmail = null;
        $this->mailer->expects($this->once())->method('send')
            ->willReturnCallback(function ($email) use (&$sentEmail) { $sentEmail = $email; });

        $this->service->sendVerificationEmail($user);

        $this->assertNotNull($sentEmail);
        $to = $sentEmail->getTo();
        $this->assertCount(1, $to);
        $this->assertSame('recipient@test.com', $to[0]->getAddress());
    }

    public function testSendVerificationEmailIncludesVerifyLink(): void
    {
        $user = $this->makeUser();
        $this->em->method('flush');

        $body = null;
        $this->mailer->method('send')->willReturnCallback(function ($email) use (&$body) {
            $body = $email->getHtmlBody();
        });

        $this->service->sendVerificationEmail($user);

        $this->assertStringContainsString('/api/auth/verify-email?token=', $body);
        $this->assertStringContainsString($user->getEmailVerificationToken(), $body);
    }

    // ── verifyEmail() ─────────────────────────────────────────────────────────

    public function testVerifyEmailSetsValidatedTrue(): void
    {
        $user = $this->makeUser();
        $user->setEmailVerificationToken('goodtoken');
        $user->setEmailVerificationSentAt(new \DateTimeImmutable('-1 hour'));

        $this->userRepo->method('findOneBy')->with(['emailVerificationToken' => 'goodtoken'])->willReturn($user);
        $this->em->expects($this->once())->method('flush');

        $result = $this->service->verifyEmail('goodtoken');

        $this->assertTrue($result->isValidated());
        $this->assertNull($result->getEmailVerificationToken());
        $this->assertNull($result->getEmailVerificationSentAt());
    }

    public function testVerifyEmailThrowsOnInvalidToken(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->verifyEmail('invalidtoken');
    }

    public function testVerifyEmailThrowsOnExpiredToken(): void
    {
        $user = $this->makeUser();
        $user->setEmailVerificationToken('expiredtoken');
        $user->setEmailVerificationSentAt(new \DateTimeImmutable('-25 hours'));

        $this->userRepo->method('findOneBy')->willReturn($user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expiré');

        $this->service->verifyEmail('expiredtoken');
    }

    public function testVerifyEmailThrowsWhenSentAtIsNull(): void
    {
        $user = $this->makeUser();
        $user->setEmailVerificationToken('nodate');
        // sentAt is null — treat as expired

        $this->userRepo->method('findOneBy')->willReturn($user);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->verifyEmail('nodate');
    }
}
