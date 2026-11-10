<?php

namespace Cpehub\Telnet\Transport;

use Cpehub\Telnet\Exceptions\ConnectionException;
use Socket;

/**
 * Default transport backed by the ext-sockets extension.
 *
 * Uses socket_select() to wait for readability instead of busy-polling, and
 * loops over partial writes so a short socket_write does not silently drop data.
 *
 * A system call interrupted by a signal (EINTR) is retried instead of failing the
 * session. Queue workers enable async signals (pcntl_async_signals), so a SIGTERM
 * sent by a supervisor scaling down, or a SIGALRM, lands in the middle of
 * socket_select() and makes it return false although the connection is fine.
 */
final class SocketTransport implements TransportInterface
{
    /**
     * Default time allowed for the TCP handshake, in milliseconds.
     *
     * A blocking socket_connect() has no timeout of its own: against a host that
     * silently drops packets it waits for the kernel to give up (about two
     * minutes on Linux), which is longer than most callers' own deadline.
     */
    public const DEFAULT_CONNECT_TIMEOUT_MS = 10_000;

    private ?Socket $socket = null;

    /**
     * @param int $connectTimeoutMs Time allowed for the TCP handshake, in milliseconds.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 23,
        private readonly int $connectTimeoutMs = self::DEFAULT_CONNECT_TIMEOUT_MS
    ) {
        if ($this->host === '') {
            throw new ConnectionException('Telnet host must not be empty.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new ConnectionException(sprintf('Invalid telnet port: %d.', $this->port));
        }
        if ($this->connectTimeoutMs < 1) {
            throw new ConnectionException(
                sprintf('Invalid connect timeout: %d ms.', $this->connectTimeoutMs)
            );
        }
    }

    public function connect(): void
    {
        if ($this->socket instanceof Socket) {
            return;
        }

        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new ConnectionException(
                'Unable to create socket: ' . socket_strerror(socket_last_error())
            );
        }

        // Non-blocking connect bounded by socket_select(): a blocking connect to a
        // host that drops packets would hang until the kernel times out.
        socket_set_nonblock($socket);
        socket_clear_error();
        if (@socket_connect($socket, $this->host, $this->port) === false) {
            $code = socket_last_error($socket);
            $pending = array_unique([SOCKET_EINPROGRESS, SOCKET_EALREADY, SOCKET_EINTR]);
            if (!in_array($code, $pending, true)) {
                $this->failConnect($socket, socket_strerror($code));
            }
            $this->clearErrors($socket);
            $this->awaitConnected($socket);
        }
        socket_set_block($socket);

        $this->socket = $socket;
    }

    /**
     * Wait for a pending non-blocking connect to finish, within the connect timeout.
     */
    private function awaitConnected(Socket $socket): void
    {
        $deadline = $this->nowMs() + $this->connectTimeoutMs;
        $remainingMs = $this->connectTimeoutMs;

        while (true) {
            $read = null;
            $write = [$socket];
            $except = null;

            socket_clear_error();
            $ready = @socket_select($read, $write, $except, intdiv($remainingMs, 1000), ($remainingMs % 1000) * 1000);
            if ($ready === false) {
                $code = $this->lastErrorCode($socket);
                if ($code !== SOCKET_EINTR) {
                    $this->failConnect($socket, 'socket_select failed: ' . socket_strerror($code));
                }
                $this->clearErrors($socket);
            } elseif ($ready > 0) {
                // Writable means the handshake ended; SO_ERROR says how.
                $error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
                if ($error !== 0) {
                    $this->failConnect($socket, socket_strerror(is_int($error) ? $error : SOCKET_ECONNREFUSED));
                }

                return;
            }

            $remainingMs = max(0, $deadline - $this->nowMs());
            if ($remainingMs === 0) {
                $seconds = rtrim(rtrim(number_format($this->connectTimeoutMs / 1000, 1, '.', ''), '0'), '.');
                $this->failConnect($socket, sprintf('connection timed out after %s s', $seconds));
            }
        }
    }

    private function failConnect(Socket $socket, string $reason): never
    {
        socket_close($socket);

        throw new ConnectionException(
            sprintf('Unable to connect to %s:%d: %s', $this->host, $this->port, $reason)
        );
    }

    public function waitReadable(int $timeoutMs): bool
    {
        $socket = $this->requireSocket();
        $deadline = $this->nowMs() + max(0, $timeoutMs);
        $remainingMs = max(0, $timeoutMs);

        while (true) {
            $read = [$socket];
            $write = null;
            $except = null;

            socket_clear_error();
            $ready = @socket_select($read, $write, $except, intdiv($remainingMs, 1000), ($remainingMs % 1000) * 1000);
            if ($ready !== false) {
                return $ready > 0;
            }

            $code = $this->lastErrorCode($socket);
            if ($code !== SOCKET_EINTR) {
                throw new ConnectionException('socket_select failed: ' . socket_strerror($code));
            }

            $this->clearErrors($socket);
            $remainingMs = max(0, $deadline - $this->nowMs());
            if ($remainingMs === 0) {
                return false;
            }
        }
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            return '';
        }

        $socket = $this->requireSocket();

        socket_clear_error();
        $data = @socket_read($socket, $length, PHP_BINARY_READ);
        if ($data === false) {
            $code = $this->lastErrorCode($socket);
            // EAGAIN/EWOULDBLOCK: nothing to read right now; EINTR: a signal
            // interrupted the call. Neither is a hard error — the caller's read
            // loop simply tries again. (On Linux EAGAIN and EWOULDBLOCK are both
            // 11; on some platforms they differ.)
            $retryable = array_unique([SOCKET_EAGAIN, SOCKET_EWOULDBLOCK, SOCKET_EINTR]);
            if (in_array($code, $retryable, true)) {
                $this->clearErrors($socket);
                return '';
            }
            throw new ConnectionException('socket_read failed: ' . socket_strerror($code));
        }

        return $data;
    }

    public function write(string $data): void
    {
        $socket = $this->requireSocket();

        $remaining = strlen($data);
        $offset = 0;
        while ($remaining > 0) {
            socket_clear_error();
            $written = @socket_write($socket, substr($data, $offset), $remaining);
            if ($written === false) {
                $code = $this->lastErrorCode($socket);
                if ($code === SOCKET_EINTR) {
                    $this->clearErrors($socket);
                    continue;
                }
                throw new ConnectionException('socket_write failed: ' . socket_strerror($code));
            }
            $offset += $written;
            $remaining -= $written;
        }
    }

    public function close(): void
    {
        if ($this->socket instanceof Socket) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    public function isConnected(): bool
    {
        return $this->socket instanceof Socket;
    }

    public function isAlive(): bool
    {
        if (!$this->socket instanceof Socket) {
            return false;
        }

        // Not readable within a zero-timeout select => open but idle.
        if (!$this->waitReadable(0)) {
            return true;
        }

        // Readable: peek a byte without consuming it. A readable socket that
        // yields 0 bytes has been closed/reset by the peer.
        $buffer = '';
        $received = @socket_recv($this->socket, $buffer, 1, MSG_PEEK | MSG_DONTWAIT);
        if ($received === false) {
            $code = socket_last_error($this->socket);
            socket_clear_error($this->socket);
            // EAGAIN/EWOULDBLOCK: nothing pending after all, still alive.
            $wouldBlock = array_unique([SOCKET_EAGAIN, SOCKET_EWOULDBLOCK]);

            return in_array($code, $wouldBlock, true);
        }

        return $received > 0;
    }

    /**
     * Error code of the last failed call on this socket.
     *
     * socket_select() reports its failure only through the global error (it
     * operates on a set of sockets), so the per-socket code reads 0 — which musl
     * renders as "No error information", hiding the real cause. The global code
     * is checked first and the per-socket one is the fallback.
     */
    private function lastErrorCode(Socket $socket): int
    {
        $global = socket_last_error();

        return $global !== 0 ? $global : socket_last_error($socket);
    }

    private function clearErrors(Socket $socket): void
    {
        socket_clear_error();
        socket_clear_error($socket);
    }

    /**
     * Monotonic clock in milliseconds (not affected by wall-clock changes).
     */
    private function nowMs(): int
    {
        [$seconds, $nanoseconds] = hrtime();

        return $seconds * 1000 + intdiv($nanoseconds, 1_000_000);
    }

    private function requireSocket(): Socket
    {
        if (!$this->socket instanceof Socket) {
            throw new ConnectionException('Transport is not connected.');
        }

        return $this->socket;
    }
}
