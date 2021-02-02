<?php
namespace Skrip42\Telnet\Components;

use Skrip42\Telnet\Components\Command;

class CommandSequence
{
    private $sequense = [];

    public function __construct(string $input = null)
    {
        if (is_null($input)) {
            return;
        }
        $text = '';
        // $option = [];
        for ($i = 0; $i < strlen($input); $i++) {
            //if command
            if ($input[$i] == chr(Command::IAC)) {
                if (!empty($text)) {
                    $this->addText($text); //flush current text
                    $text = '';
                }
                $i++;

                if ($input[$i] === chr(Command::SB)) { //if option
                    $option = $input[++$i];
                    $data = '';
                    while ($input[++$i] != chr(Command::IAC)) {
                        $data .= $input[$i];
                    }
                    $this->addOption(ord($option), $data);
                    $i++;
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
                    $this->addCommand(ord($input[$i]), ord($input[++$i]));
                } else { //other command
                    $this->addCommand(ord($input[$i]));
                }
            } else {
                $text .= $input[$i]; //if text
            }
        }
        if (!empty($text)) {
            $this->addText($text); //flush text
        }
    }


    public function addCommand(int $command, int $option = null) : self
    {
        if (!is_null($option)) {
            $this->sequense[] = [Command::IAC, $command, $option];
        } else {
            $this->sequense[] = [Command::IAC, $command];
        }
        return $this;
    }

    public function addText(...$parts) : self
    {
        $this->sequense[] = $parts;
        return $this;
    }

    public function addOption(int $option, string $data) : self
    {
        $this->sequense[] = [
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
        return $this->sequense;
    }

    public function getText() : string
    {
        $result = '';
        foreach ($this->sequense as $sequence) {
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
        foreach ($this->sequense as $sequence) {
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
        foreach ($this->sequense as $sequence) {
            foreach ($sequence as $command) {
                if (is_numeric($command)) {
                    $compiledSequence .= chr($command);
                } else {
                    $compiledSequence .= $command;
                }
            }
        }
        return $compiledSequence;
    }
}
