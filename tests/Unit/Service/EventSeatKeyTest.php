<?php

namespace App\Tests\Unit\Service;

use App\Service\EventService;
use PHPUnit\Framework\TestCase;

/**
 * Les clés de sièges sont produites à quatre endroits (EventService, l'éditeur,
 * la vue événement et le widget acheteur). Elles doivent coïncider au caractère
 * près, sinon un siège cliqué par l'acheteur ne correspond à aucune ligne en base.
 * Ce test verrouille les formules d'EventService, la seule qui écrit en base.
 */
class EventSeatKeyTest extends TestCase
{
    /** Appelle une méthode privée d'EventService sans monter tout le conteneur. */
    private function call(string $method, array $args): mixed
    {
        $ref = new \ReflectionClass(EventService::class);
        $svc = $ref->newInstanceWithoutConstructor();
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($svc, $args);
    }

    public function testAxisLabelFormats(): void
    {
        $this->assertSame('A', $this->call('axisLabel', [0, 5, 'A-Z', 'normal']));
        $this->assertSame('E', $this->call('axisLabel', [4, 5, 'A-Z', 'normal']));
        $this->assertSame('1', $this->call('axisLabel', [0, 5, '1-9', 'normal']));
        $this->assertSame('III', $this->call('axisLabel', [2, 5, 'I-X', 'normal']));
        $this->assertSame('a', $this->call('axisLabel', [0, 5, 'a-z', 'normal']));
    }

    public function testAxisLabelReversed(): void
    {
        // sens inversé : le premier index porte le dernier libellé
        $this->assertSame('E', $this->call('axisLabel', [0, 5, 'A-Z', 'reversed']));
        $this->assertSame('A', $this->call('axisLabel', [4, 5, 'A-Z', 'reversed']));
    }

    /** startAt décale la séquence après application du sens — comme sequenceValue côté JS. */
    public function testAxisLabelStartAt(): void
    {
        $this->assertSame('F', $this->call('axisLabel', [0, 1, 'A-Z', 'normal', 5]));
        $this->assertSame('11', $this->call('axisLabel', [0, 3, '1-9', 'normal', 10]));
        $this->assertSame('12', $this->call('axisLabel', [1, 3, '1-9', 'normal', 10]));
        // sens inversé puis décalage
        $this->assertSame('C', $this->call('axisLabel', [0, 3, 'A-Z', 'reversed', 0]));
        $this->assertSame('E', $this->call('axisLabel', [0, 3, 'A-Z', 'reversed', 2]));
    }

    public function testAxisLabelWrapsPastZ(): void
    {
        $this->assertSame('Z',  $this->call('axisLabel', [25, 30, 'A-Z', 'normal']));
        $this->assertSame('AA', $this->call('axisLabel', [26, 30, 'A-Z', 'normal']));
    }

    public function testToArrayAcceptsBothShapes(): void
    {
        $this->assertSame(['cols' => 3], $this->call('toArray', [['cols' => 3]]));
        $this->assertSame(['cols' => 3], $this->call('toArray', [(object) ['cols' => 3]]));
        $this->assertSame([], $this->call('toArray', [null]));
        $this->assertSame([], $this->call('toArray', ['nope']));
    }
}
