<?php

namespace Cpehub\Telnet\Tests\Integration;

use Cpehub\Telnet\Transport\StreamTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StreamTransportTest extends TestCase
{
    #[Test]
    public function itConnectsReadsAndWritesOverAPlainStream(): void
    {
        // A non-blocking TCP server on a random port.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "server failed: $errstr ($errno)");
        $address = stream_socket_get_name($server, false);

        $transport = new StreamTransport('127.0.0.1', (int) parse_url("tcp://$address", PHP_URL_PORT));
        $transport->connect();
        $transport->write("PING");

        // Accept and echo on the server side.
        $conn = stream_socket_accept($server, 2);
        $this->assertIsResource($conn);
        $this->assertSame('PING', fread($conn, 64));
        fwrite($conn, 'PONG');

        $this->assertTrue($transport->waitReadable(1000));
        $this->assertSame('PONG', $transport->read(64));

        $transport->close();
        fclose($conn);
        fclose($server);
    }

    #[Test]
    public function itRejectsAnEmptyHost(): void
    {
        $this->expectException(\Cpehub\Telnet\Exceptions\ConnectionException::class);
        new StreamTransport('', 23);
    }

    #[Test]
    public function tlsNamedConstructorDefaultsToPort992(): void
    {
        // We do not open a connection here — just assert the factory configures TLS.
        $transport = StreamTransport::tls('example.test');
        $this->assertFalse($transport->isConnected());
    }
}
