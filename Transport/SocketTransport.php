<?php

namespace Cpehub\Telnet\Transport;

use Cpehub\Telnet\Exceptions\ConnectionException;
use Socket;

/**
 * Default transport backed by the ext-sockets extension.
 *
 * Uses socket_select() to wait for readability instead of busy-polling, and
 * loops over partial writes so a short socket_write does not silently drop data.
 */
final class SocketTransport implements TransportInterface
{
    private ?Socket $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 23
    ) {
        if ($this->host === '') {
            throw new ConnectionException('Telnet host must not be empty.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new ConnectionException(sprintf('Invalid telnet port: %d.', $this->port));
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

        socket_set_block($socket);
        if (@socket_connect($socket, $this->host, $this->port) === false) {
            $error = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new ConnectionException(
                sprintf('Unable to connect to %s:%d: %s', $this->host, $this->port, $error)
            );
        }

        $this->socket = $socket;
    }

    public function waitReadable(int $timeoutMs): bool
    {
        $socket = $this->requireSocket();

        $read = [$socket];
        $write = null;
        $except = null;

        $seconds = intdiv($timeoutMs, 1000);
        $microseconds = ($timeoutMs % 1000) * 1000;

        $ready = @socket_select($read, $write, $except, $seconds, $microseconds);
        if ($ready === false) {
            throw new ConnectionException(
                'socket_select failed: ' . socket_strerror(socket_last_error($socket))
            );
        }

        return $ready > 0;
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            return '';
        }

        $socket = $this->requireSocket();

        $data = @socket_read($socket, $length, PHP_BINARY_READ);
        if ($data === false) {
            $code = socket_last_error($socket);
            // EAGAIN/EWOULDBLOCK: nothing to read right now, not a hard error.
            // (On Linux both constants are 11; on some platforms they differ.)
            $wouldBlock = array_unique([SOCKET_EAGAIN, SOCKET_EWOULDBLOCK]);
            if (in_array($code, $wouldBlock, true)) {
                socket_clear_error($socket);
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
            $written = @socket_write($socket, substr($data, $offset), $remaining);
            if ($written === false) {
                throw new ConnectionException(
                    'socket_write failed: ' . socket_strerror(socket_last_error($socket))
                );
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

    private function requireSocket(): Socket
    {
        if (!$this->socket instanceof Socket) {
            throw new ConnectionException('Transport is not connected.');
        }

        return $this->socket;
    }
}
