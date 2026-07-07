<?php

namespace Cpehub\Telnet\Tests\Integration;

use Cpehub\Telnet\Client;
use Cpehub\Telnet\Tests\Support\ScriptedTransport;
use Cpehub\Telnet\Transport\InMemoryTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ClientIsConnectedTest extends TestCase
{
    #[Test]
    public function itReportsConnectedOnAFreshClient(): void
    {
        $client = new Client('memory', 23, 500, transport: new ScriptedTransport('Router#'));

        $this->assertTrue($client->isConnected());
    }

    #[Test]
    public function itReportsDisconnectedAfterTheTransportCloses(): void
    {
        $transport = new ScriptedTransport('Router#');
        $client = new Client('memory', 23, 500, transport: $transport);

        $this->assertTrue($client->isConnected());

        $transport->close();

        $this->assertFalse($client->isConnected());
    }

    #[Test]
    public function inMemoryTransportIsAliveWhileConnected(): void
    {
        $transport = new InMemoryTransport('greeting');
        $this->assertFalse($transport->isAlive(), 'not alive before connect');

        $transport->connect();
        $this->assertTrue($transport->isAlive(), 'alive with pending inbound');

        $transport->read(64);
        $this->assertTrue($transport->isAlive(), 'still alive when idle');

        $transport->close();
        $this->assertFalse($transport->isAlive(), 'dead after close');
    }
}
