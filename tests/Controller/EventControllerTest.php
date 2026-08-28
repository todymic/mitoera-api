<?php

namespace App\Tests\Controller;

use App\Entity\Event;

class EventControllerTest extends AbstractApiTestCase
{
    // ─── GET /api/events ─────────────────────────────────────────────────────

    public function testListRequiresAuth(): void
    {
        $this->jsonRequest('GET', '/api/events');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListIsInitiallyEmpty(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/events', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $this->assertSame([], $this->responseData());
    }

    // ─── POST /api/events ────────────────────────────────────────────────────

    public function testCreateRequiresBackoffice(): void
    {
        $user      = $this->createUser('readonly@test.com', 'password123', ['ROLE_USER']);
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/events',
            ['title' => 'Concert', 'identifier' => 'concert-2026'],
            $this->authHeaders($user, $workspace),
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateEvent(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/events',
            ['title' => 'Grand Concert', 'identifier' => 'grand-concert'],
            $this->authHeaders($user, $workspace),
        );

        $this->assertResponseStatusCodeSame(201);
        $data = $this->responseData();
        $this->assertSame('Grand Concert', $data['title']);
        $this->assertSame('grand-concert', $data['identifier']);
        $this->assertArrayHasKey('id', $data);
    }

    public function testCreateEventAppearsInList(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);

        $this->jsonRequest('POST', '/api/events',
            ['title' => 'Festival', 'identifier' => 'festival-2026'],
            $auth,
        );

        $this->jsonRequest('GET', '/api/events', headers: $auth);

        $titles = array_column($this->responseData(), 'title');
        $this->assertContains('Festival', $titles);
    }

    // ─── GET /api/events/{id} ────────────────────────────────────────────────

    public function testGetEvent(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);

        $this->jsonRequest('POST', '/api/events',
            ['title' => 'Opera Night', 'identifier' => 'opera-night'],
            $auth,
        );
        $id = $this->responseData()['id'];

        $this->jsonRequest('GET', "/api/events/$id", headers: $auth);

        $this->assertJsonStatus(200);
        $this->assertSame('Opera Night', $this->responseData()['title']);
    }

    public function testGetUnknownEventReturns404(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/events/00000000-0000-0000-0000-000000000000',
            headers: $this->authHeaders($user, $workspace),
        );

        $this->assertResponseStatusCodeSame(404);
    }

    // ─── GET /api/events/lookup/{identifier} ─────────────────────────────────

    public function testLookupByIdentifier(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);

        $this->jsonRequest('POST', '/api/events',
            ['title' => 'Gala', 'identifier' => 'gala-2026'],
            $auth,
        );
        $id = $this->responseData()['id'];

        $this->jsonRequest('GET', '/api/events/lookup/gala-2026', headers: $auth);

        $this->assertJsonStatus(200);
        $this->assertSame($id, $this->responseData()['id']);
    }

    public function testLookupUnknownIdentifierReturns404(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/events/lookup/does-not-exist',
            headers: $this->authHeaders($user, $workspace),
        );

        $this->assertResponseStatusCodeSame(404);
    }

    // ─── DELETE /api/events/{id} ─────────────────────────────────────────────

    public function testDeleteEvent(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);

        $this->jsonRequest('POST', '/api/events',
            ['title' => 'To Delete', 'identifier' => 'to-delete-event'],
            $auth,
        );
        $id = $this->responseData()['id'];

        $this->jsonRequest('DELETE', "/api/events/$id", headers: $auth);
        $this->assertJsonStatus(200);

        $this->jsonRequest('GET', "/api/events/$id", headers: $auth);
        $this->assertResponseStatusCodeSame(404);
    }

    // ─── Legacy null-workspace events appear in list ──────────────────────────

    public function testLegacyNullWorkspaceEventAppearsInList(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        // Simulate an event created before workspace support (workspace = null)
        $event = new Event();
        $event->setTitle('Legacy Concert');
        $event->setIdentifier('legacy-concert');
        // workspace intentionally left null
        $this->em->persist($event);
        $this->em->flush();

        $this->jsonRequest('GET', '/api/events', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $titles = array_column($this->responseData(), 'title');
        $this->assertContains('Legacy Concert', $titles);
    }

    // ─── hold duration endpoint removed ──────────────────────────────────────

    public function testHoldDurationEndpointDoesNotExist(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);

        $this->jsonRequest('POST', '/api/events',
            ['title' => 'Hold Test', 'identifier' => 'hold-test'],
            $auth,
        );
        $id = $this->responseData()['id'];

        $this->jsonRequest('PATCH', "/api/events/$id/hold-duration",
            ['holdDurationMinutes' => 30],
            $auth,
        );
        $this->assertResponseStatusCodeSame(404);
    }

    public function testEventResponseDoesNotContainHoldDurationMinutes(): void
    {
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $auth      = $this->authHeaders($user, $workspace);

        $this->jsonRequest('POST', '/api/events',
            ['title' => 'Minimal Event', 'identifier' => 'minimal-event'],
            $auth,
        );
        $id = $this->responseData()['id'];

        $this->jsonRequest('GET', "/api/events/$id", headers: $auth);
        $data = $this->responseData();
        $this->assertArrayNotHasKey('holdDurationMinutes', $data);
    }

    public function testWorkspacedEventNotVisibleToOtherWorkspace(): void
    {
        $userA      = $this->createUser('a@test.com');
        $workspaceA = $this->createWorkspaceForUser($userA, 'Workspace A');

        $userB      = $this->createUser('b@test.com');
        $workspaceB = $this->createWorkspaceForUser($userB, 'Workspace B');

        $this->jsonRequest('POST', '/api/events',
            ['title' => 'A-only Event', 'identifier' => 'a-only-event'],
            $this->authHeaders($userA, $workspaceA),
        );
        $this->assertResponseStatusCodeSame(201);

        $this->jsonRequest('GET', '/api/events', headers: $this->authHeaders($userB, $workspaceB));
        $titles = array_column($this->responseData(), 'title');
        $this->assertNotContains('A-only Event', $titles);
    }
}
