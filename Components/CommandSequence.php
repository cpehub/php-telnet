<?php
namespace Cpehub\Telnet\Components;

use Cpehub\Telnet\Components\Command;
use Cpehub\Telnet\Exceptions\ProtocolException;
use Cpehub\Telnet\Protocol\IacCodec;

class CommandSequence
{
    private $sequence = [];

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
                } elseif (in_array( //if WILL,WONT,DO,DONT command
                    $input[$i],
                    [
                        chr(Command::WILL),
                        chr(Command::WONT),
                        chr(Command::DO),
                        chr(Command::DONT)
                    ],
                    true
                )) {
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


    public function addCommand(int $command, ?int $option = null) : self
    {
        if (!is_null($option)) {
            $this->sequence[] = [Command::IAC, $command, $option];
        } else {
            $this->sequence[] = [Command::IAC, $command];
        }
        return $this;
    }

    public function addText(...$parts) : self
    {
        $this->sequence[] = $parts;
        return $this;
    }

    public function addOption(int $option, string $data) : self
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

    public function getSequence()
    {
        return $this->sequence;
    }

    public function getText() : string
    {
        $result = '';
        foreach ($this->sequence as $sequence) {
            if (is_numeric($sequence[0])) {
                continue;
            }
            // $result .= implode('', $sequence);
            foreach ($sequence as $command) {
                if (is_numeric($command)) {
                    $result .= chr($command);
                } else {
                    $result .= $command;
                }
            }
        }
        return $result;
    }

    public function dump() : string
    {
        $resultStrings = [];
        foreach ($this->sequence as $sequence) {
            if (!is_numeric($sequence[0])) {
                $resultStrings[] = implode('', $sequence);
                continue;
            }
            $temp = '';
            foreach ($sequence as $command) {
                if (is_numeric($command)) {
                    $temp .= dechex($command);
                } else {
                    $temp .= $command;
                }
            }
            $resultStrings[] = $temp;
        }
        return implode(' ', $resultStrings);
    }

    public function compile() : string
    {
        $compiledSequence = '';
        foreach ($this->sequence as $sequence) {
            $isSubnegotiation = $this->isSubnegotiationRow($sequence);
            foreach ($sequence as $index => $command) {
                if (is_numeric($command)) {
                    $compiledSequence .= chr($command);
                    continue;
                }
                // String members carry application data and must have their IAC
                // bytes doubled (RFC 854 for text, RFC 855 for subnegotiation params).
                if ($isSubnegotiation) {
                    $compiledSequence .= IacCodec::escapeSubParams($command);
                } else {
                    $compiledSequence .= IacCodec::escape($command);
                }
            }
        }
        return $compiledSequence;
    }

    /**
     * A subnegotiation row is the six-element structure produced by {@see addOption()}:
     * [IAC, SB, option, data, IAC, SE].
     */
    private function isSubnegotiationRow(array $row) : bool
    {
        return count($row) === 6
            && ($row[0] ?? null) === Command::IAC
            && ($row[1] ?? null) === Command::SB
            && ($row[4] ?? null) === Command::IAC
            && ($row[5] ?? null) === Command::SE;
    }
}
