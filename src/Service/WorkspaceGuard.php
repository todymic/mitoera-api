<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Chart;
use App\Entity\Event;
use App\Exception\ResourceNotFoundException;
use App\Repository\CategoryRepository;
use App\Repository\ChartRepository;
use App\Repository\EventRepository;

/**
 * Binds a request to the workspace its API key belongs to.
 *
 * The services below load entities by primary key — findById(), update(),
 * delete(), linkChart(), the booking calls — and none of them checked which
 * workspace the row belonged to. With any valid API key and a known UUID,
 * one tenant could read, modify or delete another tenant's events and
 * charts. This guard is the join that was missing: call it before acting on
 * an id that came from the request.
 *
 * It answers 404, never 403 — telling a caller "this exists but is not
 * yours" already leaks that the id is real.
 *
 * Deliberately NOT used by:
 *   - the public and widget controllers (/api/public/*, /render,
 *     WidgetEventController, WidgetBookingController). Those authenticate
 *     with a *public* key passed in the query string or body, so
 *     WorkspaceContext — which reads the security token — has nothing to
 *     resolve and would return null. Guarding them would answer 404 to every
 *     customer and take the whole ticket-selling flow down. They scope
 *     themselves from the public key they were given.
 *   - the Mitoera back-office (/api/admin/*), which spans workspaces on
 *     purpose (adminList takes a nullable workspaceId).
 */
readonly class WorkspaceGuard
{
    public function __construct(
        private WorkspaceContext $workspaceContext,
        private EventRepository $eventRepository,
        private ChartRepository $chartRepository,
        private CategoryRepository $categoryRepository,
    ) {
    }

    public function assertEvent(string $eventId): Event
    {
        $event = $this->eventRepository->find($eventId);

        if (!$event instanceof Event || !$this->belongsToCurrentWorkspace($event->getWorkspace()?->getId())) {
            throw new ResourceNotFoundException('Event not found');
        }

        return $event;
    }

    public function assertChart(string $chartId): Chart
    {
        $chart = $this->chartRepository->find($chartId);

        if (!$chart instanceof Chart || !$this->belongsToCurrentWorkspace($chart->getWorkspace()?->getId())) {
            throw new ResourceNotFoundException('Chart not found');
        }

        return $chart;
    }

    /** A category has no workspace of its own — it is reached through its chart. */
    public function assertCategory(string $categoryId): Category
    {
        $category = $this->categoryRepository->find($categoryId);

        if (!$category instanceof Category
            || !$this->belongsToCurrentWorkspace($category->getChart()?->getWorkspace()?->getId())) {
            throw new ResourceNotFoundException('Category not found');
        }

        return $category;
    }

    private function belongsToCurrentWorkspace(mixed $ownerWorkspaceId): bool
    {
        // Rows predating workspace support carry no workspace at all
        // (ChartControllerTest::testLegacyNullWorkspaceChartAppearsInList
        // pins that they stay reachable). They belong to no tenant, so
        // there is no boundary to cross — let them through rather than
        // making legacy data disappear.
        if ($ownerWorkspaceId === null) {
            return true;
        }

        $workspace = $this->workspaceContext->getWorkspace();

        // The row is owned but the caller's key resolves to no workspace:
        // nothing entitles it to reach this.
        if ($workspace === null) {
            return false;
        }

        return (string) $ownerWorkspaceId === (string) $workspace->getId();
    }
}
