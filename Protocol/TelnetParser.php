<?php
namespace Cpehub\Telnet\Protocol;

use Cpehub\Telnet\Components\Command;
use Cpehub\Telnet\Protocol\Event\CommandEvent;
use Cpehub\Telnet\Protocol\Event\DataEvent;
use Cpehub\Telnet\Protocol\Event\NegotiationEvent;
use Cpehub\Telnet\Protocol\Event\SubnegotiationEvent;
use Cpehub\Telnet\Protocol\Event\TelnetEvent;

/**
 * Resumable byte-stream parser for the telnet wire protocol.
 *
 * Bytes are fed in with {@see push()}, which returns the list of complete
 * {@see TelnetEvent}s recognised so far. A command, negotiation or
 * subnegotiation that is split across chunk boundaries is retained internally
 * and completed on the next {@see push()} — the parser never reads past the
 * bytes it has been given, so it is safe to drive from a chunked socket read.
 */
final class TelnetParser
{
    private const STATE_DATA   = 0;
    private const STATE_IAC    = 1;
    private const STATE_OPTION = 2; // after IAC WILL/WONT/DO/DONT, awaiting option byte
    private const STATE_SB_OPTION = 3; // after IAC SB, awaiting option byte
    private const STATE_SB_DATA   = 4; // collecting subnegotiation params
    private const STATE_SB_IAC    = 5; // saw IAC inside subnegotiation params

    private int $state = self::STATE_DATA;
    private string $data = '';
    private int $pendingCommand = 0;
    private int $sbOption = 0;
    private string $sbData = '';

    /**
     * Feed a chunk of bytes and return the events completed by it.
     *
     * @return list<TelnetEvent>
     */
    public function push(string $chunk): array
    {
        $events = [];
        $length = strlen($chunk);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($chunk[$i]);

            switch ($this->state) {
                case self::STATE_DATA:
                    if ($byte === Command::IAC) {
                        $this->state = self::STATE_IAC;
                    } else {
                        $this->data .= $chunk[$i];
                    }
                    break;

                case self::STATE_IAC:
                    if ($byte === Command::IAC) {
                        // Escaped literal 0xFF data byte.
                        $this->data .= chr(Command::IAC);
                        $this->state = self::STATE_DATA;
                    } elseif ($byte === Command::SB) {
                        $this->flushData($events);
                        $this->state = self::STATE_SB_OPTION;
                    } elseif ($this->isNegotiation($byte)) {
                        $this->flushData($events);
                        $this->pendingCommand = $byte;
                        $this->state = self::STATE_OPTION;
                    } else {
                        $this->flushData($events);
                        $events[] = new CommandEvent($byte);
                        $this->state = self::STATE_DATA;
                    }
                    break;

                case self::STATE_OPTION:
                    $events[] = new NegotiationEvent($this->pendingCommand, $byte);
                    $this->state = self::STATE_DATA;
                    break;

                case self::STATE_SB_OPTION:
                    $this->sbOption = $byte;
                    $this->sbData = '';
                    $this->state = self::STATE_SB_DATA;
                    break;

                case self::STATE_SB_DATA:
                    if ($byte === Command::IAC) {
                        $this->state = self::STATE_SB_IAC;
                    } else {
                        $this->sbData .= $chunk[$i];
                    }
                    break;

                case self::STATE_SB_IAC:
                    if ($byte === Command::IAC) {
                        // Escaped literal 0xFF inside the subnegotiation params.
                        $this->sbData .= chr(Command::IAC);
                        $this->state = self::STATE_SB_DATA;
                    } elseif ($byte === Command::SE) {
                        $events[] = new SubnegotiationEvent($this->sbOption, $this->sbData);
                        $this->state = self::STATE_DATA;
                    } else {
                        // Aborted subnegotiation (e.g. IAC IP): emit what we have,
                        // then re-process this byte as a fresh command.
                        $events[] = new SubnegotiationEvent($this->sbOption, $this->sbData);
                        if ($this->isNegotiation($byte)) {
                            $this->pendingCommand = $byte;
                            $this->state = self::STATE_OPTION;
                        } else {
                            $events[] = new CommandEvent($byte);
                            $this->state = self::STATE_DATA;
                        }
                    }
                    break;
            }
        }

        $this->flushData($events);

        return $events;
    }

    /**
     * True when the parser is mid-command (a partial sequence is buffered and
     * awaiting more bytes). Useful for asserting a clean boundary in tests.
     */
    public function isIdle(): bool
    {
        return $this->state === self::STATE_DATA && $this->data === '';
    }

    /**
     * @param list<TelnetEvent> $events
     */
    private function flushData(array &$events): void
    {
        if ($this->data !== '') {
            $events[] = new DataEvent($this->data);
            $this->data = '';
        }
    }

    private function isNegotiation(int $byte): bool
    {
        return $byte === Command::WILL
            || $byte === Command::WONT
            || $byte === Command::DO
            || $byte === Command::DONT;
    }
}
