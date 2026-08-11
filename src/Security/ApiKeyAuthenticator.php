<?php

namespace App\Security;

use App\Exception\UnauthorizedException;
use App\Service\ApiKeyService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private ApiKeyService $apiKeyService,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if ($request->headers->has('X-Api-Key-Id') && $request->headers->has('X-Api-Key-Secret')) {
            return true;
        }
        $auth = $request->headers->get('Authorization', '');
        if (str_starts_with($auth, 'ApiKey ')) {
            return true;
        }
        // Authorization: Basic base64(keyId:secret)
        if (str_starts_with($auth, 'Basic ')) {
            $decoded = base64_decode(substr($auth, 6), true);
            return $decoded !== false && str_contains($decoded, ':');
        }
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        $keyId = $request->headers->get('X-Api-Key-Id');
        $secret = $request->headers->get('X-Api-Key-Secret');

        if (!$keyId || !$secret) {
            $auth = $request->headers->get('Authorization', '');
            if (str_starts_with($auth, 'ApiKey ')) {
                $raw = substr($auth, strlen('ApiKey '));
                [$keyId, $secret] = explode(':', $raw, 2) + [null, null];
            } elseif (str_starts_with($auth, 'Basic ')) {
                $decoded = base64_decode(substr($auth, 6), true);
                [$keyId, $secret] = explode(':', $decoded, 2) + [null, null];
            }
        }

        if (!$keyId || !$secret) {
            throw new AuthenticationException('Missing API credentials');
        }

        try {
            $apiKey = $this->apiKeyService->validate($keyId, $secret);

            $roles = ['ROLE_' . strtoupper($apiKey->getScope()->value)];

            return new SelfValidatingPassport(
                new UserBadge($keyId, function() use ($apiKey, $roles) {
                    return new ApiKeyUser($apiKey->getKeyId(), $roles);
                })
            );
        } catch (UnauthorizedException $e) {
            throw new AuthenticationException($e->getMessage());
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new Response($exception->getMessage(), Response::HTTP_UNAUTHORIZED);
    }
}


