<?php

namespace App\Message;

final class SyncUserToSandboxMessage
{
    public function __construct(public readonly string $userId) {}
}
