<?php
namespace Cpehub\Telnet\Tests\Support;

use Cpehub\Telnet\Transport\TransportInterface;

/**
 * A test transport that models a request/response server: it reveals the next
 * scripted response only after the client has written the expected input, which
 * is how a real telnet prompt/response exchange behaves.
 */
final class ScriptedTransport implements TransportInterface
{
    private bool $connected = false;
    private string $inbound = '';
    private string $written = '';
    private string $pendingWrite = '';

    /**
     * @param string $greeting Bytes available immediately after connect.
     * @param list<array{expect: string, send: string}> $steps
     *        Each step waits until $expect has been written, then appends $send to the readable stream.
     */
    public function __construct(
        string $greeting = '',
        private array $steps = []
    ) {
        $this->inbound = $greeting;
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
        $this->written .= $data;
        $this->pendingWrite .= $data;
        $this->advance();
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function allWritten(): string
    {
        return $this->written;
    }

    /**
     * Fire any scripted steps whose expected input has now been written.
     */
    private function advance(): void
    {
        $progressed = true;
        while ($progressed && $this->steps !== []) {
            $progressed = false;
            $step = $this->steps[0];
            $position = strpos($this->pendingWrite, $step['expect']);
            if ($position !== false) {
                $this->pendingWrite = substr($this->pendingWrite, $position + strlen($step['expect']));
                $this->inbound .= $step['send'];
                array_shift($this->steps);
                $progressed = true;
            }
        }
    }
}
