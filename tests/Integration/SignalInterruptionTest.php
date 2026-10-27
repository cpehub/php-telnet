<?php

namespace Cpehub\Telnet\Tests\Integration;

use Cpehub\Telnet\Client;
use Cpehub\Telnet\Exceptions\ConnectionException;
use Cpehub\Telnet\Transport\SocketTransport;
use Cpehub\Telnet\Transport\StreamTransport;
use Cpehub\Telnet\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A signal delivered while the transport waits for data must not end the session.
 *
 * Queue workers (Laravel/Horizon) enable pcntl_async_signals(); a SIGTERM sent when
 * the supervisor scales down, or a SIGALRM, interrupts the blocking select() with
 * EINTR. That used to surface as "socket_select failed: No error information" and
 * a dropped session in the middle of a long OLT read.
 *
 * A forked child plays the disturbance: it signals the test process and, when the
 * test needs it, writes to the server side of the connection afterwards.
 */
class SignalInterruptionTest extends TestCase
{
    /** @var resource */
    private $server;
    private int $port;
    private int $signals = 0;
    private bool $previousAsyncSignals = false;

    protected function setUp(): void
    {
        $required = ['pcntl_fork', 'pcntl_async_signals', 'posix_kill'];
        if (array_filter($required, fn (string $function): bool => !function_exists($function)) !== []) {
            $this->markTestSkipped('Requires ext-pcntl and ext-posix.');
        }

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "server failed: $errstr ($errno)");
        $this->server = $server;
        $address = stream_socket_get_name($server, false);
        $this->port = (int) parse_url("tcp://$address", PHP_URL_PORT);

        $this->signals = 0;
        $this->previousAsyncSignals = pcntl_async_signals(true);
        pcntl_signal(SIGUSR1, function (): void {
            $this->signals++;
        });
    }

    protected function tearDown(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGUSR1, SIG_DFL);
            pcntl_async_signals($this->previousAsyncSignals);
        }

        if (is_resource($this->server)) {
            fclose($this->server);
        }
    }

    /**
     * @return array<string, array{0: callable(int): TransportInterface}>
     */
    public static function transports(): array
    {
        return [
            'socket' => [fn (int $port): TransportInterface => new SocketTransport('127.0.0.1', $port)],
            'stream' => [fn (int $port): TransportInterface => new StreamTransport('127.0.0.1', $port)],
        ];
    }

    #[Test]
    #[DataProvider('transports')]
    public function waitReadableSurvivesASignalAndSeesTheDataThatFollows(callable $factory): void
    {
        $transport = $factory($this->port);
        $transport->connect();
        $conn = stream_socket_accept($this->server, 2);
        $this->assertIsResource($conn);

        $child = $this->disturb(signalAfterMs: 200, conn: $conn, writeAfterMs: 300, payload: 'OLT#');

        $readable = $transport->waitReadable(3000);
        $this->reap($child);

        $this->assertSame(1, $this->signals, 'the wait must actually have been interrupted');
        $this->assertTrue($readable, 'data written after the signal must be seen');
        $this->assertSame('OLT#', $transport->read(64));

        $transport->close();
    }

    #[Test]
    #[DataProvider('transports')]
    public function aSignalDoesNotCutTheTimeoutShort(callable $factory): void
    {
        $transport = $factory($this->port);
        $transport->connect();
        $conn = stream_socket_accept($this->server, 2);
        $this->assertIsResource($conn);

        $child = $this->disturb(signalAfterMs: 150);

        $start = hrtime(true);
        $readable = $transport->waitReadable(800);
        $elapsedMs = intdiv(hrtime(true) - $start, 1_000_000);
        $this->reap($child);

        $this->assertSame(1, $this->signals);
        $this->assertFalse($readable, 'no data: still a plain timeout');
        $this->assertGreaterThanOrEqual(750, $elapsedMs, 'the remaining time must still be waited');
        $this->assertLessThan(2000, $elapsedMs, 'the deadline must still be honored');

        $transport->close();
    }

    #[Test]
    public function aClientReadSurvivesASignalMidCommand(): void
    {
        $transport = new SocketTransport('127.0.0.1', $this->port);
        $client = new Client('127.0.0.1', $this->port, 3000, transport: $transport);
        $conn = stream_socket_accept($this->server, 2);
        $this->assertIsResource($conn);

        $reply = "display ont info summary\r\n  1590 ONTs\r\nOLT#";
        $child = $this->disturb(signalAfterMs: 200, conn: $conn, writeAfterMs: 300, payload: $reply);

        $output = $client->sendMessage('display ont info summary', '/OLT#/', 3000);
        $this->reap($child);

        $this->assertSame(1, $this->signals);
        $this->assertStringContainsString('1590 ONTs', $output);
    }

    #[Test]
    public function aRealFailureStillReportsTheActualError(): void
    {
        $transport = new SocketTransport('127.0.0.1', $this->port);
        $transport->connect();
        $conn = stream_socket_accept($this->server, 2);
        $this->assertIsResource($conn);

        // Peer resets the connection; writing until the kernel reports it must
        // raise with a real reason, never the old "No error information".
        stream_socket_shutdown($conn, STREAM_SHUT_RDWR);
        fclose($conn);

        try {
            for ($i = 0; $i < 50; $i++) {
                $transport->write(str_repeat('x', 4096));
                usleep(10_000);
            }
            $this->fail('writing to a reset peer must fail');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('socket_write failed: ', $e->getMessage());
            $this->assertStringNotContainsString('No error information', $e->getMessage());
            $this->assertStringNotContainsString('Success', $e->getMessage());
        } finally {
            $transport->close();
        }
    }

    /**
     * Fork a child that signals this process after $signalAfterMs and, optionally,
     * writes $payload to $conn $writeAfterMs later. The child never returns: it
     * kills itself so PHPUnit's shutdown handlers do not run twice.
     *
     * @param resource|null $conn
     */
    private function disturb(int $signalAfterMs, $conn = null, int $writeAfterMs = 0, string $payload = ''): int
    {
        $parent = posix_getpid();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            usleep($signalAfterMs * 1000);
            posix_kill($parent, SIGUSR1);

            if (is_resource($conn) && $payload !== '') {
                usleep($writeAfterMs * 1000);
                fwrite($conn, $payload);
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        return $pid;
    }

    private function reap(int $pid): void
    {
        pcntl_waitpid($pid, $status);
    }
}
