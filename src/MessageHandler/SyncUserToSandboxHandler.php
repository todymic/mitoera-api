<?php

namespace App\MessageHandler;

use App\Message\SyncUserToSandboxMessage;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncUserToSandboxHandler
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly string $sandboxInternalUrl,
        private readonly string $internalSyncSecret,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SyncUserToSandboxMessage $message): void
    {
        if (empty($this->sandboxInternalUrl)) {
            return;
        }

        $user = $this->userRepository->find($message->userId);
        if (!$user) {
            return;
        }

        $payload = json_encode([
            'id'          => $user->getId(),
            'email'       => $user->getEmail(),
            'password'    => $user->getPassword(),
            'firstName'   => $user->getFirstName(),
            'lastName'    => $user->getLastName(),
            'displayName' => $user->getDisplayName(),
            'roles'       => $user->getRoles(),
            'validated'   => $user->isValidated(),
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nX-Internal-Secret: {$this->internalSyncSecret}\r\n",
                'content' => $payload,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $url = rtrim($this->sandboxInternalUrl, '/') . '/api/internal/users/sync';

        try {
            $result = file_get_contents($url, false, $ctx);
            if ($result === false) {
                $this->logger->warning('SyncUserToSandbox: could not reach sandbox', ['userId' => $message->userId]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('SyncUserToSandbox: error', ['error' => $e->getMessage(), 'userId' => $message->userId]);
        }
    }
}
