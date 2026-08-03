<?php

namespace App\Adapter\Outbound;

use App\Port\SeatPublisherPort;

/**
 * Publie les statuts de sièges dans Cloud Firestore via l'API REST.
 * Aucune dépendance grpc requise — utilise curl + JWT Service Account.
 * Structure : events/{eventId}/seats/{seatKey}
 */
readonly class FirestoreSeatPublisher implements SeatPublisherPort
{
    private array $serviceAccount;

    public function __construct(string $credentialsPath, private string $projectId)
    {
        $json = file_get_contents($credentialsPath);
        if ($json === false) {
            throw new \RuntimeException("Firebase credentials not found: $credentialsPath");
        }
        $this->serviceAccount = json_decode($json, true);
    }

    public function publishSeatChanges(string $eventId, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $token = $this->getAccessToken();
        $base  = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents";
        $now   = gmdate('Y-m-d\TH:i:s\Z');

        $writes = [];
        foreach ($changes as $change) {
            $seatKey = $change['seatKey'];
            $writes[] = [
                'update' => [
                    'name'   => "{$base}/events/{$eventId}/seats/{$seatKey}",
                    'fields' => [
                        'seatKey'   => ['stringValue' => $seatKey],
                        'status'    => ['stringValue' => $change['status']],
                        'updatedAt' => ['timestampValue' => $now],
                    ],
                ],
                'updateMask' => ['fieldPaths' => ['seatKey', 'status', 'updatedAt']],
            ];
        }

        $url  = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents:batchWrite";
        $body = json_encode(['writes' => $writes]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 400) {
            error_log('[Firestore] batchWrite failed (' . $code . '): ' . $response);
        }
    }

    private function getAccessToken(): string
    {
        $now    = time();
        $header = $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim  = $this->base64url(json_encode([
            'iss'   => $this->serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/datastore',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $toSign = "$header.$claim";
        openssl_sign($toSign, $signature, $this->serviceAccount['private_key'], 'sha256WithRSAEncryption');
        $jwt = "$toSign." . $this->base64url($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);
        $res   = curl_exec($ch);
        curl_close($ch);
        $data  = json_decode($res, true);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('[Firestore] Failed to get access token: ' . $res);
        }

        return $data['access_token'];
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
