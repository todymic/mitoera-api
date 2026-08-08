<?php

namespace App\EventListener;

use App\Adapter\Outbound\FirestoreSeatPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::TERMINATE)]
class FirestoreFlushListener
{
    public function __construct(private FirestoreSeatPublisher $publisher) {}

    public function __invoke(TerminateEvent $event): void
    {
        $this->publisher->flush();
    }
}
