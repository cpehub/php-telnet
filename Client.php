<?php

namespace Skrip42\Telnet;

use Psr\Log\LoggerInterface;
use Skrip42\Telnet\Components\CommandSequence;
use Skrip42\Telnet\Components\Command;
use Skrip42\Telnet\Components\Option;
use Skrip42\Telnet\Components\Printer;
use Skrip42\Telnet\Exceptions\TelnetException;

class Client
{
    const BYTE_READ    = 4096;  //4kb
    const READ_TIMEOUT = 10000; //10ms

    private $socket;
    private $buffer = '';

    /** @var int $timelimit */
    private $timelimit;
    /** @var LoggerInterface $logger */
    private $logger;
    /** @var string $promtPattern */
    private $promtPattern;

    public function __construct(
        string $ip,
        int $port = 23,
        int $timelimit = 100, //100ms
        LoggerInterface $logger = null
    ) {
        $this->logger = $logger;
        $this->timelimit = $timelimit * 1000;

        $this->socket = fsockopen($ip, $port);
        // stream_set_timeout($this->socket, self::READ_TIMEOUT);
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_block($this->socket);
        socket_connect($this->socket, $ip, $port);
    }

    public function __desctruct()
    {
        $sequense = new CommandSequence();
        $sequense->addText('exit');
        $this->sendSequence($sequense);
        // fclose($this->socket);
        socket_close($this->socket);
    }

    public function setPromtPattern(string $promtPattern) : self
    {
        $this->promtPattern = $promtPattern;
        return $this;
    }

    public function setLogger(LoggerInterface $logger) : self
    {
        $this->setLogger($logger);
        return $this;
    }

    public function login($login, $password, $promtPattern = null)// : CommandSequence
    {
        //set telnet connetion options
        $sequense = new CommandSequence();
        $sequense
            ->addCommand(Command::DO, Option::SUPPRESS_GO_AHEAD)
            ->addCommand(Command::WILL, Option::TERMINAL_TYPE)
            ->addCommand(Command::WILL, Option::WINDOW_SIZE)
            ->addCommand(Command::WILL, Option::TERMINAL_SPEED)
            ->addCommand(Command::WILL, Option::REMOTE_FLOW_CONTROL)
            ->addCommand(Command::WILL, Option::TERMINAL_LINEMODE)
            ->addCommand(Command::WILL, Option::ENVIRONMENT)
            ->addCommand(Command::DO, Option::STATUS)
            ->addCommand(Command::WILL, Option::X_DISPLAY_LOCATION);
        $this->sendSequence($sequense);
        //get telnet response
        $sequense = new CommandSequence();
        $sequense->addCommand(Command::DONT, Option::X_DISPLAY_LOCATION);
        $this->awaitSequence($sequense);

        //enable authorization
        $sequense = new CommandSequence();
        $sequense
            ->addCommand(Command::DO, Option::SUPPRESS_GO_AHEAD)
            ->addCommand(Command::WILL, Option::ECHO);
        $this->sendSequence($sequense);
        //set login
        $sequense = new CommandSequence();
        $sequense->addText($login, Printer::CR);
        $this->sendSequence($sequense);

        $sequense = new CommandSequence();
        $sequense->addText('Password:');
        $this->awaitSequence($sequense);

        //set password
        $sequense = new CommandSequence();
        $sequense->addText($password, Printer::CR);
        $this->sendSequence($sequense);
        $this->awaitPrompt($promtPattern ?? $this->promtPattern);
    }

    public function sendMessage(string $message, string $promtPattern = null, int $timelimit = null) : string
    {
        if (empty($timelimit)) {
            $timelimit = $this->timelimit;
        } else {
            $timelimit *= 1000;
        }
        if (empty($promtPattern)) {
            $promtPattern = $this->promtPattern;
        }
        $sequense = new CommandSequence();
        $sequense->addText($message, Printer::CR);
        $this->sendSequence($sequense);
        $result = $this->awaitPrompt($promtPattern, $timelimit);
        return $result->getText();
    }

    public function sendSequence(CommandSequence $sequence)
    {
        if (!empty($this->logger)) {
            $this->logger->info('send: ' . $sequence->dump());
        }
        socket_write($this->socket, $sequence->compile());
    }

    public function awaitSequence(CommandSequence $sequence, int $timelimit = null) : CommandSequence
    {
        if (empty($timelimit)) {
            $timelimit = $this->timelimit;
        } else {
            $timelimit *= 1000;
        }
        $needle = $sequence->compile();
        $ep = 0;
        $counter = 0;
        socket_set_nonblock($this->socket);
        while (($ep = strpos($this->buffer, $needle, 0)) === false
            && $counter < ($timelimit / self::READ_TIMEOUT)
        ) {
            usleep(self::READ_TIMEOUT);
            $res = socket_read($this->socket, self::BYTE_READ);
            if ($res !== false) {
                $this->buffer .= $res;
            }
            $counter++;
        }
        socket_set_block($this->socket);
        if ($counter >= $timelimit / self::READ_TIMEOUT) {
            if (!empty($this->logger)) {
                $this->logger->error(
                    'expected: ' . $sequence->dump()
                    . 'receive: ' . $this->buffer
                );
            }
            throw new TelnetException('telnet swquence await timeout exceeded, response: ' . $this->buffer);
        }
        $ep += strlen($needle);
        $raw = substr($this->buffer, 0, $ep);
        $this->buffer = substr($this->buffer, $ep);
        $sequence = new CommandSequence($raw);
        if (!empty($this->logger)) {
            $this->logger->info('receive: ' . $sequence->dump() . ' time: ' . $counter * self::READ_TIMEOUT / 1000 . 'ms');
        }
        return $sequence;
    }

    public function awaitPrompt(string $promtPattern = null, int $timelimit = null) : CommandSequence
    {
        if (empty($promtPattern)) {
            $promtPattern = $this->promtPattern;
        }
        if (empty($timelimit)) {
            $timelimit = $this->timelimit;
        } else {
            $timelimit *= 1000;
        }
        $counter = 0;
        $match = [];
        socket_set_nonblock($this->socket);
        while (!preg_match($promtPattern, $this->buffer, $match, PREG_OFFSET_CAPTURE)
            && $counter < ($timelimit / self::READ_TIMEOUT)
        ) {
            usleep(self::READ_TIMEOUT);
            $res = socket_read($this->socket, self::BYTE_READ);
            if (!empty($res !== false)) {
                $this->buffer .= $res;
            }
            $counter++;
        }
        socket_set_block($this->socket);
        if ($counter >= $timelimit / self::READ_TIMEOUT) {
            if (!empty($this->logger)) {
                $this->logger->error(
                    'expected: ' . $promtPattern
                    . 'receive: ' . $this->buffer
                );
            }
            throw new TelnetException('telnet promt await timeout exceeded, response: ' . $this->buffer);
        }
        $ep = $match[0][1];
        $ep += strlen($match[0][0]);
        $raw = substr($this->buffer, 0, $ep);
        $this->buffer = substr($this->buffer, $ep);
        $sequence = new CommandSequence($raw);
        if (!empty($this->logger)) {
            $this->logger->info('receive: ' . $sequence->dump() . ' time: ' . $counter * self::READ_TIMEOUT / 1000 . 'ms');
        }
        return $sequence;
    }
}
