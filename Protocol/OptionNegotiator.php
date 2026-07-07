<?php
namespace Cpehub\Telnet\Protocol;

use Cpehub\Telnet\Components\Command;
use Psr\Log\LoggerInterface;

/**
 * Loop-free telnet option negotiation using the RFC 1143 "Q Method".
 *
 * This class is pure: it holds the per-option state machine and, given an
 * inbound WILL/WONT/DO/DONT (or a local decision to enable/disable an option),
 * returns the command byte that should be written back — or null when nothing
 * must be sent. It performs no I/O, which makes the whole negotiation logic
 * unit-testable in isolation.
 *
 * "him"/"himq" track the option on the server's side (driven by WILL/WONT and
 * answered with DO/DONT); "us"/"usq" track our side (driven by DO/DONT and
 * answered with WILL/WONT). The two are handled by the same procedure with the
 * roles swapped, per RFC 1143 §7.
 *
 * @phpstan-type OptionRecord array{
 *     us: OptionState, usq: QueueBit, him: OptionState, himq: QueueBit
 * }
 */
final class OptionNegotiator
{
    /** @var array<int, array{us: OptionState, usq: QueueBit, him: OptionState, himq: QueueBit}> */
    private array $options = [];

    public function __construct(
        private readonly NegotiationPolicy $policy = new NegotiationPolicy(),
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Handle an inbound IAC WILL <option>.
     *
     * @return int|null Command::DO or Command::DONT to send, or null.
     */
    public function receiveWill(int $option): ?int
    {
        $o = $this->record($option);

        switch ($o['him']) {
            case OptionState::NO:
                if ($this->policy->shouldEnableHim($option)) {
                    $o['him'] = OptionState::YES;
                    $this->store($option, $o);
                    return Command::DO;
                }
                $this->store($option, $o);
                return Command::DONT;

            case OptionState::YES:
                return null; // ignore

            case OptionState::WANTNO:
                $this->error('DONT answered by WILL', $option);
                if ($o['himq'] === QueueBit::EMPTY) {
                    $o['him'] = OptionState::NO;
                } else {
                    $o['him'] = OptionState::YES;
                    $o['himq'] = QueueBit::EMPTY;
                }
                $this->store($option, $o);
                return null;

            case OptionState::WANTYES:
                if ($o['himq'] === QueueBit::EMPTY) {
                    $o['him'] = OptionState::YES;
                    $this->store($option, $o);
                    return null;
                }
                $o['him'] = OptionState::WANTNO;
                $o['himq'] = QueueBit::EMPTY;
                $this->store($option, $o);
                return Command::DONT;
        }

        return null;
    }

    /**
     * Handle an inbound IAC WONT <option>.
     *
     * @return int|null Command::DONT or Command::DO to send, or null.
     */
    public function receiveWont(int $option): ?int
    {
        $o = $this->record($option);

        switch ($o['him']) {
            case OptionState::NO:
                return null; // ignore

            case OptionState::YES:
                $o['him'] = OptionState::NO;
                $this->store($option, $o);
                return Command::DONT;

            case OptionState::WANTNO:
                if ($o['himq'] === QueueBit::EMPTY) {
                    $o['him'] = OptionState::NO;
                    $this->store($option, $o);
                    return null;
                }
                $o['him'] = OptionState::WANTYES;
                $o['himq'] = QueueBit::EMPTY;
                $this->store($option, $o);
                return Command::DO;

            case OptionState::WANTYES:
                $o['him'] = OptionState::NO;
                $o['himq'] = QueueBit::EMPTY;
                $this->store($option, $o);
                return null;
        }

        return null;
    }

    /**
     * Handle an inbound IAC DO <option>.
     *
     * @return int|null Command::WILL or Command::WONT to send, or null.
     */
    public function receiveDo(int $option): ?int
    {
        $o = $this->record($option);

        switch ($o['us']) {
            case OptionState::NO:
                if ($this->policy->shouldEnableUs($option)) {
                    $o['us'] = OptionState::YES;
                    $this->store($option, $o);
                    return Command::WILL;
                }
                $this->store($option, $o);
                return Command::WONT;

            case OptionState::YES:
                return null;

            case OptionState::WANTNO:
                $this->error('WONT answered by DO', $option);
                if ($o['usq'] === QueueBit::EMPTY) {
                    $o['us'] = OptionState::NO;
                } else {
                    $o['us'] = OptionState::YES;
                    $o['usq'] = QueueBit::EMPTY;
                }
                $this->store($option, $o);
                return null;

            case OptionState::WANTYES:
                if ($o['usq'] === QueueBit::EMPTY) {
                    $o['us'] = OptionState::YES;
                    $this->store($option, $o);
                    return null;
                }
                $o['us'] = OptionState::WANTNO;
                $o['usq'] = QueueBit::EMPTY;
                $this->store($option, $o);
                return Command::WONT;
        }

        return null;
    }

    /**
     * Handle an inbound IAC DONT <option>.
     *
     * @return int|null Command::WONT or Command::WILL to send, or null.
     */
    public function receiveDont(int $option): ?int
    {
        $o = $this->record($option);

        switch ($o['us']) {
            case OptionState::NO:
                return null;

            case OptionState::YES:
                $o['us'] = OptionState::NO;
                $this->store($option, $o);
                return Command::WONT;

            case OptionState::WANTNO:
                if ($o['usq'] === QueueBit::EMPTY) {
                    $o['us'] = OptionState::NO;
                    $this->store($option, $o);
                    return null;
                }
                $o['us'] = OptionState::WANTYES;
                $o['usq'] = QueueBit::EMPTY;
                $this->store($option, $o);
                return Command::WILL;

            case OptionState::WANTYES:
                $o['us'] = OptionState::NO;
                $o['usq'] = QueueBit::EMPTY;
                $this->store($option, $o);
                return null;
        }

        return null;
    }

    /**
     * Locally decide to ask the server to enable an option.
     *
     * @return int|null Command::DO to send, or null when already enabled/negotiating.
     */
    public function askEnableHim(int $option): ?int
    {
        $o = $this->record($option);

        switch ($o['him']) {
            case OptionState::NO:
                $o['him'] = OptionState::WANTYES;
                $this->store($option, $o);
                return Command::DO;

            case OptionState::YES:
                $this->error('Already enabled (him)', $option);
                return null;

            case OptionState::WANTNO:
                if ($o['himq'] === QueueBit::EMPTY) {
                    $o['himq'] = QueueBit::OPPOSITE;
                    $this->store($option, $o);
                } else {
                    $this->error('Already queued an enable request (him)', $option);
                }
                return null;

            case OptionState::WANTYES:
                $this->error('Already negotiating for enable (him)', $option);
                return null;
        }

        return null;
    }

    /**
     * Locally decide to ask the server to disable an option.
     *
     * @return int|null Command::DONT to send, or null.
     */
    public function askDisableHim(int $option): ?int
    {
        $o = $this->record($option);

        switch ($o['him']) {
            case OptionState::NO:
                $this->error('Already disabled (him)', $option);
                return null;

            case OptionState::YES:
                $o['him'] = OptionState::WANTNO;
                $this->store($option, $o);
                return Command::DONT;

            case OptionState::WANTNO:
                $this->error('Already negotiating for disable (him)', $option);
                return null;

            case OptionState::WANTYES:
                if ($o['himq'] === QueueBit::EMPTY) {
                    $o['himq'] = QueueBit::OPPOSITE;
                    $this->store($option, $o);
                } else {
                    $this->error('Already queued a disable request (him)', $option);
                }
                return null;
        }

        return null;
    }

    /**
     * Locally decide to offer to perform an option ourselves.
     *
     * @return int|null Command::WILL to send, or null.
     */
    public function askEnableUs(int $option): ?int
    {
        $o = $this->record($option);

        switch ($o['us']) {
            case OptionState::NO:
                $o['us'] = OptionState::WANTYES;
                $this->store($option, $o);
                return Command::WILL;

            case OptionState::YES:
                $this->error('Already enabled (us)', $option);
                return null;

            case OptionState::WANTNO:
                if ($o['usq'] === QueueBit::EMPTY) {
                    $o['usq'] = QueueBit::OPPOSITE;
                    $this->store($option, $o);
                } else {
                    $this->error('Already queued an enable request (us)', $option);
                }
                return null;

            case OptionState::WANTYES:
                $this->error('Already negotiating for enable (us)', $option);
                return null;
        }

        return null;
    }

    /**
     * Locally decide to stop performing an option ourselves.
     *
     * @return int|null Command::WONT to send, or null.
     */
    public function askDisableUs(int $option): ?int
    {
        $o = $this->record($option);

        switch ($o['us']) {
            case OptionState::NO:
                $this->error('Already disabled (us)', $option);
                return null;

            case OptionState::YES:
                $o['us'] = OptionState::WANTNO;
                $this->store($option, $o);
                return Command::WONT;

            case OptionState::WANTNO:
                $this->error('Already negotiating for disable (us)', $option);
                return null;

            case OptionState::WANTYES:
                if ($o['usq'] === QueueBit::EMPTY) {
                    $o['usq'] = QueueBit::OPPOSITE;
                    $this->store($option, $o);
                } else {
                    $this->error('Already queued a disable request (us)', $option);
                }
                return null;
        }

        return null;
    }

    /** Whether the server-side of an option is currently enabled. */
    public function isEnabledHim(int $option): bool
    {
        return $this->record($option)['him'] === OptionState::YES;
    }

    /** Whether our side of an option is currently enabled. */
    public function isEnabledUs(int $option): bool
    {
        return $this->record($option)['us'] === OptionState::YES;
    }

    /**
     * @return array{us: OptionState, usq: QueueBit, him: OptionState, himq: QueueBit}
     */
    private function record(int $option): array
    {
        return $this->options[$option] ?? [
            'us'   => OptionState::NO,
            'usq'  => QueueBit::EMPTY,
            'him'  => OptionState::NO,
            'himq' => QueueBit::EMPTY,
        ];
    }

    /**
     * @param array{us: OptionState, usq: QueueBit, him: OptionState, himq: QueueBit} $record
     */
    private function store(int $option, array $record): void
    {
        $this->options[$option] = $record;
    }

    private function error(string $message, int $option): void
    {
        $this->logger?->warning(
            sprintf('telnet negotiation: %s (option 0x%02X)', $message, $option)
        );
    }
}
