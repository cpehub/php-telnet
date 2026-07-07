<?php
namespace Cpehub\Telnet\Transport;

use Cpehub\Telnet\Exceptions\ConnectionException;

/**
 * Transport backed by PHP streams (stream_socket_client), which additionally
 * supports TLS ("telnets"). Use {@see StreamTransport::tls()} for an encrypted
 * connection, or the constructor directly for a plain stream connection.
 *
 * Readiness is detected with stream_select(); reads are non-blocking so the
 * client's timed read loop stays in control of the deadline.
 */
final class StreamTransport implements TransportInterface
{
    /** @var resource|null */
    private $stream = null;

    /**
     * @param string $host Target host.
     * @param int $port Target port.
     * @param bool $tls Whether to enable TLS after connecting.
     * @param float $connectTimeout Connection timeout in seconds.
     * @param array<string, mixed> $sslOptions SSL context options (see PHP "ssl" context).
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 23,
        private readonly bool $tls = false,
        private readonly float $connectTimeout = 10.0,
        private readonly array $sslOptions = []
    ) {
        if ($this->host === '') {
            throw new ConnectionException('Telnet host must not be empty.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new ConnectionException(sprintf('Invalid telnet port: %d.', $this->port));
        }
    }

    /**
     * Named constructor for an encrypted ("telnets") connection.
     *
     * @param array<string, mixed> $sslOptions
     */
    public static function tls(
        string $host,
        int $port = 992,
        float $connectTimeout = 10.0,
        array $sslOptions = []
    ): self {
        return new self($host, $port, true, $connectTimeout, $sslOptions);
    }

    public function connect(): void
    {
        if (is_resource($this->stream)) {
            return;
        }

        $context = stream_context_create($this->sslOptions === [] ? [] : ['ssl' => $this->sslOptions]);
        $errno = 0;
        $errstr = '';

        $stream = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($stream === false) {
            throw new ConnectionException(
                sprintf('Unable to connect to %s:%d: %s (%d)', $this->host, $this->port, $errstr, $errno)
            );
        }

        if ($this->tls) {
            $enabled = @stream_socket_enable_crypto(
                $stream,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            if ($enabled !== true) {
                fclose($stream);
                throw new ConnectionException(
                    sprintf('Unable to establish TLS with %s:%d.', $this->host, $this->port)
                );
            }
        }

        stream_set_blocking($stream, false);
        $this->stream = $stream;
    }

    public function waitReadable(int $timeoutMs): bool
    {
        $stream = $this->requireStream();

        $read = [$stream];
        $write = null;
        $except = null;

        $seconds = intdiv($timeoutMs, 1000);
        $microseconds = ($timeoutMs % 1000) * 1000;

        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
        if ($ready === false) {
            throw new ConnectionException('stream_select failed.');
        }

        return $ready > 0;
    }

    public function read(int $length): string
    {
        $stream = $this->requireStream();

        $data = @fread($stream, $length);
        if ($data === false) {
            if (feof($stream)) {
                throw new ConnectionException('Connection closed by peer.');
            }
            return '';
        }

        return $data;
    }

    public function write(string $data): void
    {
        $stream = $this->requireStream();

        $remaining = strlen($data);
        $offset = 0;
        while ($remaining > 0) {
            $written = @fwrite($stream, substr($data, $offset), $remaining);
            if ($written === false || $written === 0) {
                throw new ConnectionException('fwrite failed.');
            }
            $offset += $written;
            $remaining -= $written;
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    public function isConnected(): bool
    {
        return is_resource($this->stream);
    }

    /**
     * @return resource
     */
    private function requireStream()
    {
        if (!is_resource($this->stream)) {
            throw new ConnectionException('Transport is not connected.');
        }

        return $this->stream;
    }
}
