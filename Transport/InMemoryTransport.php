<?php

namespace Cpehub\Telnet\Transport;

use Cpehub\Telnet\Exceptions\ConnectionException;

/**
 * In-memory transport for tests. The "server" side feeds bytes with
 * {@see feed()} (data the client will read) and inspects what the client wrote
 * with {@see takeWritten()}. No real socket is involved.
 */
final class InMemoryTransport implements TransportInterface
{
    private bool $connected = false;
    private string $inbound = '';
    private string $written = '';

    /**
     * @param string $inbound Initial bytes available for the client to read.
     */
    public function __construct(string $inbound = '')
    {
        $this->inbound = $inbound;
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function waitReadable(int $timeoutMs): bool
    {
        return $this->inbound !== '';
    }

    public function read(int $length): string
    {
        $chunk = substr($this->inbound, 0, $length);
        $this->inbound = substr($this->inbound, strlen($chunk));

        return $chunk;
    }

    public function write(string $data): void
    {
        if (!$this->connected) {
            throw new ConnectionException('Transport is not connected.');
        }
        $this->written .= $data;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Server-side helper: queue more bytes for the client to read.
     */
    public function feed(string $data): void
    {
        $this->inbound .= $data;
    }

    /**
     * Server-side helper: consume and return everything the client has written so far.
     */
    public function takeWritten(): string
    {
        $data = $this->written;
        $this->written = '';

        return $data;
    }

    /**
     * Server-side helper: peek at everything the client has written without consuming it.
     */
    public function written(): string
    {
        return $this->written;
    }
}
