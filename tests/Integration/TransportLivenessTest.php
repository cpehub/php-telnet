<?php

namespace Cpehub\Telnet\Tests\Integration;

use Cpehub\Telnet\Transport\SocketTransport;
use Cpehub\Telnet\Transport\StreamTransport;
use Cpehub\Telnet\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the isAlive() liveness probe against a real loopback TCP server, for
 * both concrete transports, proving it detects an actual peer close/reset (not
 * merely the local handle state) and never consumes pending bytes.
 */
class TransportLivenessTest extends TestCase
{
    /** @var resource */
    private $server;
    private int $port;

    protected function setUp(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "server failed: $errstr ($errno)");
        $this->server = $server;
        $address = stream_socket_get_name($server, false);
        $this->port = (int) parse_url("tcp://$address", PHP_URL_PORT);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }
    }

    #[Test]
    public function socketTransportDetectsPeerClose(): void
    {
        $this->assertLivenessLifecycle(new SocketTransport('127.0.0.1', $this->port));
    }

    #[Test]
    public function streamTransportDetectsPeerClose(): void
    {
        $this->assertLivenessLifecycle(new StreamTransport('127.0.0.1', $this->port));
    }

    #[Test]
    public function socketTransportProbeDoesNotConsumePendingBytes(): void
    {
        $this->assertProbeLeavesBytesIntact(new SocketTransport('127.0.0.1', $this->port));
    }

    #[Test]
    public function streamTransportProbeDoesNotConsumePendingBytes(): void
    {
        $this->assertProbeLeavesBytesIntact(new StreamTransport('127.0.0.1', $this->port));
    }

    private function assertLivenessLifecycle(TransportInterface $transport): void
    {
        $transport->connect();
        $conn = stream_socket_accept($this->server, 2);
        $this->assertIsResource($conn);

        $this->assertTrue($transport->isAlive(), 'alive while idle and connected');

        // Peer closes: the probe must flip to dead.
        fclose($conn);
        $this->assertFalse(
            $this->probeUntil($transport, false),
            'must detect the peer close'
        );

        $transport->close();
        $this->assertFalse($transport->isAlive(), 'dead after local close');
    }

    private function assertProbeLeavesBytesIntact(TransportInterface $transport): void
    {
        $transport->connect();
        $conn = stream_socket_accept($this->server, 2);
        $this->assertIsResource($conn);

        fwrite($conn, 'HELLO');

        // Wait until the byte is actually pending on our side, then probe.
        $this->assertTrue($transport->waitReadable(1000));
        $this->assertTrue($transport->isAlive(), 'alive with pending data');

        // The peeked byte must not have been consumed: a real read still sees it.
        $this->assertTrue($transport->waitReadable(1000));
        $this->assertSame('HELLO', $transport->read(64));

        $transport->close();
        fclose($conn);
    }

    /**
     * Poll isAlive() briefly until it reaches $expected (peer-close visibility can
     * lag by a scheduler tick), then return the final observed value.
     */
    private function probeUntil(TransportInterface $transport, bool $expected): bool
    {
        $alive = true;
        for ($i = 0; $i < 50; $i++) {
            $alive = $transport->isAlive();
            if ($alive === $expected) {
                return $alive;
            }
            usleep(10_000);
        }

        return $alive;
    }
}
