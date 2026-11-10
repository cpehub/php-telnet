<?php

namespace Cpehub\Telnet\Tests\Integration;

use Cpehub\Telnet\Exceptions\ConnectionException;
use Cpehub\Telnet\Transport\SocketTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SocketTransport::connect() used to be a plain blocking socket_connect(): against
 * a host that drops packets it hung until the kernel gave up (about two minutes).
 * A queue worker checking an unreachable OLT was held until its own job timeout
 * killed it. These tests pin the bounded, non-blocking connect.
 */
class SocketTransportConnectTimeoutTest extends TestCase
{
    #[Test]
    public function connectsToALoopbackServerAndStaysUsable(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "server failed: $errstr ($errno)");
        $port = (int) parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT);

        $transport = new SocketTransport('127.0.0.1', $port, 2000);
        $transport->connect();
        $this->assertTrue($transport->isConnected());

        $peer = stream_socket_accept($server, 2);
        $this->assertIsResource($peer);

        // Both directions work after the non-blocking handshake.
        $transport->write('ping');
        $this->assertSame('ping', fread($peer, 4));
        fwrite($peer, 'pong');
        $this->assertTrue($transport->waitReadable(2000));
        $this->assertSame('pong', $transport->read(4));

        $transport->close();
        fclose($peer);
        fclose($server);
    }

    #[Test]
    public function givesUpAtTheConnectTimeoutWhenNobodyAnswers(): void
    {
        // A listener whose accept queue is full stops answering SYNs: the connect
        // stays pending without any network dependency. backlog=0 still lets a
        // couple of handshakes through, so the queue is filled first.
        $server = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create(['socket' => ['backlog' => 0]])
        );
        $this->assertIsResource($server, "server failed: $errstr ($errno)");
        $port = (int) parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT);

        $fillers = [];
        $timedOut = null;
        $elapsedMs = 0.0;
        for ($i = 0; $i < 16; $i++) {
            $transport = new SocketTransport('127.0.0.1', $port, 300);
            $startedAt = hrtime(true);
            try {
                $transport->connect();
                $fillers[] = $transport;
            } catch (ConnectionException $e) {
                $timedOut = $e;
                $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
                break;
            }
        }

        foreach ($fillers as $filler) {
            $filler->close();
        }
        fclose($server);

        if ($timedOut === null) {
            $this->markTestSkipped('This kernel kept accepting connections past the backlog.');
        }

        $this->assertStringContainsString("Unable to connect to 127.0.0.1:$port", $timedOut->getMessage());
        $this->assertStringContainsString('connection timed out after 0.3 s', $timedOut->getMessage());
        $this->assertGreaterThanOrEqual(250.0, $elapsedMs);
        $this->assertLessThan(2000.0, $elapsedMs);
    }

    #[Test]
    public function aClosedPortStillFailsImmediately(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "server failed: $errstr ($errno)");
        $port = (int) parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT);
        fclose($server);

        $transport = new SocketTransport('127.0.0.1', $port, 5000);
        $startedAt = hrtime(true);

        try {
            $transport->connect();
            $this->fail('Connecting to a closed port must throw.');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString("Unable to connect to 127.0.0.1:$port", $e->getMessage());
            $this->assertStringNotContainsString('timed out', $e->getMessage());
        }

        $this->assertLessThan(1000.0, (hrtime(true) - $startedAt) / 1_000_000);
        $this->assertFalse($transport->isConnected());
    }

    #[Test]
    public function rejectsANonPositiveConnectTimeout(): void
    {
        $this->expectException(ConnectionException::class);

        new SocketTransport('127.0.0.1', 23, 0);
    }
}
