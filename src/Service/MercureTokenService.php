<?php

namespace App\Service;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class MercureTokenService
{
    private Configuration $config;

    public function __construct(string $mercureJwtSecret)
    {
        $this->config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($mercureJwtSecret),
        );
    }

    /** Generate a subscriber-only JWT for the given Mercure topics. */
    public function buildSubscriberToken(array $topics): string
    {
        $now = new \DateTimeImmutable();

        return $this->config->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+2 hours'))
            ->withClaim('mercure', ['subscribe' => $topics])
            ->getToken($this->config->signer(), $this->config->signingKey())
            ->toString();
    }
}
