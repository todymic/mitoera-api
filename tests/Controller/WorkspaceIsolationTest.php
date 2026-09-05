<?php

namespace App\Tests\Controller;

/**
 * A valid API key must not reach another workspace's rows.
 *
 * Before WorkspaceGuard, every route below loaded its entity with a bare
 * find($id) and never checked the owner: knowing a UUID was enough for one
 * tenant to read, modify or delete another tenant's event or chart. The
 * answer is 404 rather than 403 — a 403 would confirm the id exists.
 */
class WorkspaceIsolationTest extends AbstractApiTestCase
{
    /** @return array{0: array<string, string>, 1: string, 2: string} intruder headers, event id, chart id */
    private function eventAndChartOwnedBySomeoneElse(): array
    {
        $owner = $this->createUser('owner@mitoera.com');
        $ownerAuth = $this->authHeaders($owner, $this->createWorkspaceForUser($owner, 'Owner Workspace'));

        $this->jsonRequest('POST', '/api/charts', ['name' => 'Hall', 'slug' => 'owner-hall'], $ownerAuth);
        $chartId = $this->responseData()['id'];

        $this->jsonRequest('POST', '/api/events', ['title' => 'Concert', 'identifier' => 'owner-concert'], $ownerAuth);
        $eventId = $this->responseData()['id'];

        $intruder = $this->createUser('intruder@mitoera.com');
        $intruderAuth = $this->authHeaders($intruder, $this->createWorkspaceForUser($intruder, 'Intruder Workspace'));

        return [$intruderAuth, $eventId, $chartId];
    }

    public function testCannotReadAnotherWorkspaceEvent(): void
    {
        [$auth, $eventId] = $this->eventAndChartOwnedBySomeoneElse();

        $this->jsonRequest('GET', "/api/events/$eventId", headers: $auth);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCannotUpdateAnotherWorkspaceEvent(): void
    {
        [$auth, $eventId] = $this->eventAndChartOwnedBySomeoneElse();

        $this->jsonRequest('PUT', "/api/events/$eventId", ['title' => 'Volé', 'identifier' => 'stolen'], $auth);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCannotDeleteAnotherWorkspaceEvent(): void
    {
        [$auth, $eventId] = $this->eventAndChartOwnedBySomeoneElse();

        $this->jsonRequest('DELETE', "/api/events/$eventId", headers: $auth);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCannotReadAnotherWorkspaceChart(): void
    {
        [$auth, , $chartId] = $this->eventAndChartOwnedBySomeoneElse();

        $this->jsonRequest('GET', "/api/charts/$chartId", headers: $auth);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCannotDeleteAnotherWorkspaceChart(): void
    {
        [$auth, , $chartId] = $this->eventAndChartOwnedBySomeoneElse();

        $this->jsonRequest('DELETE', "/api/charts/$chartId", headers: $auth);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCannotLinkAnotherWorkspaceChartToOwnEvent(): void
    {
        [$auth, , $chartId] = $this->eventAndChartOwnedBySomeoneElse();

        $this->jsonRequest('POST', '/api/events', ['title' => 'Mien', 'identifier' => 'mine'], $auth);
        $ownEventId = $this->responseData()['id'];

        $this->jsonRequest('POST', "/api/events/$ownEventId/link-chart/$chartId", headers: $auth);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCannotHoldSeatsOnAnotherWorkspaceEvent(): void
    {
        [$auth, $eventId] = $this->eventAndChartOwnedBySomeoneElse();

        $this->jsonRequest('POST', "/api/events/$eventId/hold", ['seatKeys' => ['A-1'], 'holdToken' => 'tok'], $auth);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testOwnerStillReachesItsOwnEvent(): void
    {
        $owner = $this->createUser('owner@mitoera.com');
        $auth = $this->authHeaders($owner, $this->createWorkspaceForUser($owner, 'Owner Workspace'));

        $this->jsonRequest('POST', '/api/events', ['title' => 'Concert', 'identifier' => 'owner-concert'], $auth);
        $eventId = $this->responseData()['id'];

        $this->jsonRequest('GET', "/api/events/$eventId", headers: $auth);

        $this->assertJsonStatus(200);
    }
}
