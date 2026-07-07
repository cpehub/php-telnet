<?php

namespace Cpehub\Telnet\Components;

use Cpehub\Telnet\Components\Command;
use Cpehub\Telnet\Exceptions\ProtocolException;
use Cpehub\Telnet\Protocol\IacCodec;

class CommandSequence
{
    /**
     * The sequence as a list of rows. Each row is a list whose members are
     * either int command/option bytes or string data fragments.
     *
     * @var list<list<int|string>>
     */
    private array $sequence = [];

    public function __construct(?string $input = null)
    {
        if (is_null($input)) {
            return;
        }
        $length = strlen($input);
        $text = '';
        for ($i = 0; $i < $length; $i++) {
            //if command
            if ($input[$i] == chr(Command::IAC)) {
                if ($i + 1 >= $length) {
                    throw new ProtocolException('Truncated telnet command: dangling IAC at end of input.');
                }
                // IAC IAC is a literal 0xFF data byte, not the start of a command.
                if ($input[$i + 1] === chr(Command::IAC)) {
                    $text .= chr(Command::IAC);
                    $i++;
                    continue;
                }
                if ($text !== '') {
                    $this->addText($text); //flush current text
                    $text = '';
                }
                $i++;

                if ($input[$i] === chr(Command::SB)) { //if option
                    if ($i + 1 >= $length) {
                        throw new ProtocolException('Truncated subnegotiation: missing option byte after IAC SB.');
                    }
                    $option = $input[++$i];
                    $data = '';
                    while (true) {
                        if ($i + 1 >= $length) {
                            throw new ProtocolException('Unterminated subnegotiation: missing IAC SE.');
                        }
                        if ($input[++$i] === chr(Command::IAC)) {
                            if ($i + 1 >= $length) {
                                throw new ProtocolException('Unterminated subnegotiation: dangling IAC.');
                            }
                            // IAC IAC inside SB params is a literal 0xFF data byte.
                            if ($input[$i + 1] === chr(Command::IAC)) {
                                $data .= chr(Command::IAC);
                                $i++;
                                continue;
                            }
                            // IAC SE terminates the subnegotiation.
                            break;
                        }
                        $data .= $input[$i];
                    }
                    $this->addOption(ord($option), $data);
                    $i++; //consume the SE following IAC
                } elseif (
                    in_array( //if WILL,WONT,DO,DONT command
                        $input[$i],
                        [
                        chr(Command::WILL),
                        chr(Command::WONT),
                        chr(Command::DO),
                        chr(Command::DONT)
                        ],
                        true
                    )
                ) {
                    if ($i + 1 >= $length) {
                        throw new ProtocolException('Truncated negotiation command: missing option byte.');
                    }
                    $this->addCommand(ord($input[$i]), ord($input[++$i]));
                } else { //other command
                    $this->addCommand(ord($input[$i]));
                }
            } else {
                $text .= $input[$i]; //if text
            }
        }
        if ($text !== '') {
            $this->addText($text); //flush text
        }
    }


    public function addCommand(int $command, ?int $option = null): self
    {
        if (!is_null($option)) {
            $this->sequence[] = [Command::IAC, $command, $option];
        } else {
            $this->sequence[] = [Command::IAC, $command];
        }
        return $this;
    }

    /**
     * Append a text row. Parts may be strings or int byte values (e.g. Printer::CR).
     *
     * @param int|string ...$parts
     */
    public function addText(int|string ...$parts): self
    {
        $this->sequence[] = array_values($parts);
        return $this;
    }

    public function addOption(int $option, string $data): self
    {
        $this->sequence[] = [
            Command::IAC,
            Command::SB,
            $option,
            $data,
            Command::IAC,
            Command::SE
        ];
        return $this;
    }

    /**
     * @return list<list<int|string>>
     */
    public function getSequence(): array
    {
        return $this->sequence;
    }

    public function getText(): string
    {
        $result = '';
        foreach ($this->sequence as $row) {
            if (is_int($row[0])) {
                continue; // command row
            }
            foreach ($row as $member) {
                $result .= is_int($member) ? self::byte($member) : $member;
            }
        }
        return $result;
    }

    public function dump(): string
    {
        $resultStrings = [];
        foreach ($this->sequence as $row) {
            if (!is_int($row[0])) {
                $resultStrings[] = implode('', array_map('strval', $row));
                continue;
            }
            $temp = '';
            foreach ($row as $member) {
                $temp .= is_int($member) ? dechex($member) : $member;
            }
            $resultStrings[] = $temp;
        }
        return implode(' ', $resultStrings);
    }

    public function compile(): string
    {
        $compiledSequence = '';
        foreach ($this->sequence as $row) {
            $isSubnegotiation = $this->isSubnegotiationRow($row);
            foreach ($row as $member) {
                if (is_int($member)) {
                    $compiledSequence .= self::byte($member);
                    continue;
                }
                // String members carry application data and must have their IAC
                // bytes doubled (RFC 854 for text, RFC 855 for subnegotiation params).
                $compiledSequence .= $isSubnegotiation
                    ? IacCodec::escapeSubParams($member)
                    : IacCodec::escape($member);
            }
        }
        return $compiledSequence;
    }

    /**
     * A subnegotiation row is the six-element structure produced by {@see addOption()}:
     * [IAC, SB, option, data, IAC, SE].
     *
     * @param list<int|string> $row
     */
    private function isSubnegotiationRow(array $row): bool
    {
        return count($row) === 6
            && $row[0] === Command::IAC
            && $row[1] === Command::SB
            && $row[4] === Command::IAC
            && $row[5] === Command::SE;
    }

    /**
     * Render an int command/option value as a single wire byte. Values are byte
     * codes by contract; the mask makes the byte domain explicit (and matches
     * chr()'s own modulo-256 behaviour) for any out-of-range input.
     */
    private static function byte(int $value): string
    {
        return chr($value & 0xFF);
    }
}
