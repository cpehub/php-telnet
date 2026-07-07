<?php

namespace Cpehub\Telnet;

use Psr\Log\LoggerInterface;
use Cpehub\Telnet\Components\CommandSequence;
use Cpehub\Telnet\Components\Command;
use Cpehub\Telnet\Components\Option;
use Cpehub\Telnet\Components\Printer;
use Cpehub\Telnet\Exceptions\TelnetException;
use Cpehub\Telnet\Protocol\OptionNegotiator;
use Cpehub\Telnet\Protocol\NegotiationPolicy;
use Cpehub\Telnet\Protocol\TelnetParser;
use Cpehub\Telnet\Protocol\Event\CommandEvent;
use Cpehub\Telnet\Protocol\Event\DataEvent;
use Cpehub\Telnet\Protocol\Event\NegotiationEvent;
use Cpehub\Telnet\Protocol\Event\SubnegotiationEvent;
use Cpehub\Telnet\Transport\TransportInterface;
use Cpehub\Telnet\Transport\SocketTransport;

/**
 * Telnet client.
 *
 * The client owns a {@see TransportInterface} for byte I/O, a {@see TelnetParser}
 * that demultiplexes the incoming stream into data and protocol events, and an
 * {@see OptionNegotiator} that answers the server's option negotiation while the
 * caller waits for output. Application data is accumulated in a clean buffer that
 * prompt/sequence matching runs against, so telnet control bytes never leak into
 * matched text.
 *
 * NOTE (behaviour change vs 1.0.x): $timelimit is now expressed in **milliseconds**
 * everywhere (constructor and per-call overrides). The previous code mixed
 * seconds/milliseconds/microseconds inconsistently. The constructor default of
 * 1000 ms preserves the old "1 second" behaviour.
 */
class Client
{
    const BYTE_READ = 4096; // 4kb read chunk

    /** Default cap on the clean data buffer (bytes) to bound memory against a chatty/hostile peer. */
    const DEFAULT_MAX_BUFFER = 16 * 1024 * 1024; // 16 MiB

    private TransportInterface $transport;
    private TelnetParser $parser;
    private OptionNegotiator $negotiator;

    /** Clean application data accumulated from the stream (telnet commands stripped). */
    private string $buffer = '';

    /** @var int Default await time limit, in milliseconds. */
    private int $timelimit;
    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger;
    /** @var string|null */
    private ?string $promptPattern = null;
    /** @var int Maximum size of the clean data buffer, in bytes. */
    private int $maxBuffer;

    /**
     * @param string $ip Telnet host (ip or hostname), or a preconfigured transport is passed separately.
     * @param int $port Telnet host port.
     * @param int $timelimit Default await time limit, in milliseconds.
     * @param LoggerInterface|null $logger Optional PSR-3 logger.
     * @param TransportInterface|null $transport Override the transport (e.g. TLS); defaults to a plain socket.
     * @param NegotiationPolicy|null $policy Override which options the client agrees to negotiate.
     * @param int $maxBuffer Cap on accumulated unmatched data, in bytes (guards against a hostile peer).
     */
    public function __construct(
        string $ip,
        int $port = 23,
        int $timelimit = 1000, // milliseconds
        ?LoggerInterface $logger = null,
        ?TransportInterface $transport = null,
        ?NegotiationPolicy $policy = null,
        int $maxBuffer = self::DEFAULT_MAX_BUFFER
    ) {
        $this->logger = $logger;
        $this->timelimit = $timelimit;
        $this->maxBuffer = $maxBuffer;
        $this->transport = $transport ?? new SocketTransport($ip, $port);
        $this->parser = new TelnetParser();
        $this->negotiator = new OptionNegotiator($policy ?? new NegotiationPolicy(), $logger);

        $this->transport->connect();
    }

    public function __destruct()
    {
        try {
            $this->transport->close();
        } catch (\Throwable $e) {
            $this->logger?->warning('Transport was already closed before destruction: ' . $e->getMessage());
        }
    }

    public function setPromptPattern(string $promptPattern): self
    {
        $this->promptPattern = $promptPattern;
        return $this;
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Perform the login procedure.
     *
     * Unlike 1.0.x this no longer scripts a fixed burst of WILL/DO and does not
     * wait for a hard-coded reply; option negotiation is handled automatically by
     * the read loop. We proactively offer the RFC 1123 §3 baseline (SUPPRESS-GO-AHEAD),
     * then drive the login/password prompts.
     */
    public function login(string $login, string $password, ?string $promptPattern = null): CommandSequence
    {
        // Offer/request the mandatory baseline; the negotiator suppresses duplicates
        // and the read loop answers whatever the server negotiates in return.
        $sequence = new CommandSequence();
        foreach ([
            $this->negotiator->askEnableUs(Option::SUPPRESS_GO_AHEAD),
            $this->negotiator->askEnableHim(Option::SUPPRESS_GO_AHEAD),
        ] as $reply) {
            if ($reply !== null) {
                $sequence->addCommand($reply, Option::SUPPRESS_GO_AHEAD);
            }
        }
        if ($sequence->getSequence() !== []) {
            $this->sendSequence($sequence);
        }

        // Wait for the login prompt, then send the username. Match the common
        // "login:" / "Username:" variants at the end of the received data.
        $this->awaitPrompt('~(?:login|user(?:name)?)[: ]*$~i', $this->timelimit);
        $this->sendSequence((new CommandSequence())->addText($login, chr(Printer::CR)));

        // Wait for the password prompt, then send the password (never logged).
        $this->awaitPrompt('~password[: ]*$~i', $this->timelimit);
        $this->sendSequence(
            (new CommandSequence())->addText($password, chr(Printer::CR)),
            sensitive: true
        );

        return $this->awaitPrompt($promptPattern ?? $this->promptPattern);
    }

    /**
     * Send a command line and return the text received up to the prompt.
     */
    public function sendMessage(string $message, ?string $promptPattern = null, ?int $timelimit = null): string
    {
        $timelimit ??= $this->timelimit;
        $promptPattern ??= $this->promptPattern;

        $this->sendSequence((new CommandSequence())->addText($message, chr(Printer::CR)));
        $result = $this->awaitPrompt($promptPattern, $timelimit);

        return $result->getText();
    }

    /**
     * Send a command line without awaiting any response.
     */
    public function sendLastMessage(string $message): void
    {
        $this->sendSequence((new CommandSequence())->addText($message, chr(Printer::CR)));
    }

    /**
     * Write a command sequence to the transport.
     *
     * @param bool $sensitive When true, the payload is redacted in logs (used for credentials).
     */
    public function sendSequence(CommandSequence $sequence, bool $sensitive = false): void
    {
        $this->logger?->info('send: ' . ($sensitive ? '<redacted>' : $sequence->dump()));
        $this->transport->write($sequence->compile());
    }

    /**
     * Wait until the given raw sequence appears in the incoming data, then return
     * a CommandSequence built from everything up to and including it.
     *
     * @param int|null $timelimit Time limit in milliseconds.
     */
    public function awaitSequence(CommandSequence $sequence, ?int $timelimit = null): CommandSequence
    {
        $timelimit ??= $this->timelimit;
        $needle = $sequence->getText();

        $position = $this->readUntil(
            fn (string $buffer): int|false => $needle === '' ? 0 : strpos($buffer, $needle),
            $timelimit
        );

        if ($position === null) {
            $this->logger?->error('expected sequence: ' . $sequence->dump());
            throw new TelnetException('Telnet sequence await timeout exceeded.');
        }

        return $this->consume($position + strlen($needle));
    }

    /**
     * Wait until the given prompt pattern matches the incoming data, then return
     * a CommandSequence built from everything up to and including the match.
     *
     * @param int|null $timelimit Time limit in milliseconds.
     */
    public function awaitPrompt(?string $promptPattern = null, ?int $timelimit = null): CommandSequence
    {
        $promptPattern ??= $this->promptPattern;
        $timelimit ??= $this->timelimit;

        if ($promptPattern === null) {
            throw new TelnetException('No prompt pattern configured; set one via setPromptPattern() or pass it explicitly.');
        }
        if (@preg_match($promptPattern, '') === false) {
            throw new TelnetException('Invalid prompt pattern: ' . $promptPattern);
        }

        $end = $this->readUntil(
            function (string $buffer) use ($promptPattern): int|false {
                if (preg_match($promptPattern, $buffer, $match, PREG_OFFSET_CAPTURE)) {
                    return $match[0][1] + strlen($match[0][0]);
                }
                return false;
            },
            $timelimit
        );

        if ($end === null) {
            $this->logger?->error('expected prompt: ' . $promptPattern);
            throw new TelnetException('Telnet prompt waiting time exceeded.');
        }

        return $this->consume($end);
    }

    /**
     * Drive the read loop until $matcher returns a non-false offset into the clean
     * data buffer or the deadline passes. Incoming negotiation is answered inline.
     *
     * @param callable(string): (int|false) $matcher Returns the end offset of the match, or false.
     * @return int|null The end offset, or null on timeout.
     */
    private function readUntil(callable $matcher, int $timelimitMs): ?int
    {
        // Check anything already buffered before waiting on the socket.
        $offset = $matcher($this->buffer);
        if ($offset !== false) {
            return $offset;
        }

        $deadline = $this->now() + $timelimitMs;
        do {
            $remaining = $deadline - $this->now();
            if ($remaining <= 0) {
                break;
            }

            if (!$this->transport->waitReadable($remaining)) {
                continue;
            }

            $chunk = $this->transport->read(self::BYTE_READ);
            if ($chunk === '') {
                continue;
            }

            $this->ingest($chunk);

            $offset = $matcher($this->buffer);
            if ($offset !== false) {
                return $offset;
            }
        } while ($this->now() < $deadline);

        return null;
    }

    /**
     * Feed raw bytes through the parser: append data to the clean buffer, answer
     * negotiation, and drop pure protocol events.
     */
    private function ingest(string $chunk): void
    {
        foreach ($this->parser->push($chunk) as $event) {
            if ($event instanceof DataEvent) {
                $this->buffer .= $event->data;
                if (strlen($this->buffer) > $this->maxBuffer) {
                    throw new TelnetException(sprintf(
                        'Telnet response exceeded the maximum buffer size of %d bytes without matching.',
                        $this->maxBuffer
                    ));
                }
            } elseif ($event instanceof NegotiationEvent) {
                $this->handleNegotiation($event);
            } elseif ($event instanceof SubnegotiationEvent) {
                $this->logger?->debug(sprintf('subnegotiation option 0x%02X ignored', $event->option));
            } elseif ($event instanceof CommandEvent) {
                $this->logger?->debug(sprintf('telnet command 0x%02X received', $event->command));
            }
        }
    }

    private function handleNegotiation(NegotiationEvent $event): void
    {
        $reply = match ($event->command) {
            Command::WILL => $this->negotiator->receiveWill($event->option),
            Command::WONT => $this->negotiator->receiveWont($event->option),
            Command::DO   => $this->negotiator->receiveDo($event->option),
            Command::DONT => $this->negotiator->receiveDont($event->option),
            default       => null,
        };

        if ($reply !== null) {
            $this->sendSequence((new CommandSequence())->addCommand($reply, $event->option));
        }
    }

    /**
     * Slice the first $length bytes off the clean buffer and return them as a
     * CommandSequence (plain text — protocol bytes were already stripped).
     */
    private function consume(int $length): CommandSequence
    {
        $raw = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        $sequence = new CommandSequence();
        if ($raw !== '') {
            $sequence->addText($raw);
        }
        $this->logger?->info('received: ' . strlen($raw) . ' bytes');

        return $sequence;
    }

    /**
     * Current monotonic time in milliseconds.
     */
    private function now(): int
    {
        return (int) (hrtime(true) / 1_000_000);
    }
}
