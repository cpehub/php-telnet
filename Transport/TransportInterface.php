<?php

namespace Cpehub\Telnet\Transport;

use Cpehub\Telnet\Exceptions\ConnectionException;

/**
 * Byte-level transport used by the telnet client. Abstracting the socket lets
 * the negotiation/parsing logic be driven over a real socket, a TLS stream, or
 * an in-memory pipe (tests) through the same contract.
 *
 * All timeouts are expressed in integer milliseconds.
 */
interface TransportInterface
{
    /**
     * Open the connection.
     *
     * @throws ConnectionException on failure to create/connect the socket.
     */
    public function connect(): void;

    /**
     * Block until the transport is readable or the timeout elapses.
     *
     * @param int $timeoutMs Milliseconds to wait; 0 polls without blocking.
     * @return bool True if data is available to read, false on timeout.
     */
    public function waitReadable(int $timeoutMs): bool;

    /**
     * Read up to $length bytes. Returns '' when no data is currently available.
     *
     * @throws ConnectionException on a hard read error.
     */
    public function read(int $length): string;

    /**
     * Write all of $data, looping over partial writes.
     *
     * @throws ConnectionException on a hard write error.
     */
    public function write(string $data): void;

    /**
     * Close the connection. Idempotent.
     */
    public function close(): void;

    /**
     * Whether the transport is currently open.
     */
    public function isConnected(): bool;
}
