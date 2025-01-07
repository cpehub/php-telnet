<?php

namespace Cpehub\Telnet;

use Psr\Log\LoggerInterface;
use Cpehub\Telnet\Components\CommandSequence;
use Cpehub\Telnet\Components\Command;
use Cpehub\Telnet\Components\Option;
use Cpehub\Telnet\Components\Printer;
use Cpehub\Telnet\Exceptions\TelnetException;

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
    /** @var string $promptPattern */
    private $promptPattern;

    public function __construct(
        string $ip,
        int $port = 23,
        int $timelimit = 1, // 1 Second
        LoggerInterface $logger = null
    ) {
        $this->logger = $logger;
        $this->timelimit = $timelimit * 1000; //TODO: Use milliseconds for more precision.

        $this->socket = fsockopen($ip, $port);
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_block($this->socket);
        socket_connect($this->socket, $ip, $port);
    }

    public function __destruct()
    {
        $sequence = new CommandSequence();
        $sequence->addText('exit');
        $this->sendSequence($sequence);
        socket_close($this->socket);
    }

    public function setPromptPattern(string $promptPattern) : self
    {
        $this->promptPattern = $promptPattern;
        return $this;
    }

    public function setLogger(LoggerInterface $logger) : self
    {
        $this->setLogger($logger);
        return $this;
    }

    public function login(string $login, string $password, string $promptPattern = null) : CommandSequence
    {
        //set telnet connection options
        $sequence = new CommandSequence();
        $sequence
            ->addCommand(Command::DO, Option::SUPPRESS_GO_AHEAD)
            ->addCommand(Command::WILL, Option::TERMINAL_TYPE)
            ->addCommand(Command::WILL, Option::WINDOW_SIZE)
            ->addCommand(Command::WILL, Option::TERMINAL_SPEED)
            ->addCommand(Command::WILL, Option::REMOTE_FLOW_CONTROL)
            ->addCommand(Command::WILL, Option::TERMINAL_LINEMODE)
            ->addCommand(Command::WILL, Option::ENVIRONMENT)
            ->addCommand(Command::DO, Option::STATUS)
            ->addCommand(Command::WILL, Option::X_DISPLAY_LOCATION);
        $this->sendSequence($sequence);
        
        //get telnet response
        $sequence = new CommandSequence();
        $sequence->addCommand(Command::DONT, Option::X_DISPLAY_LOCATION);
        $this->awaitSequence($sequence);

        //enable authorization
        $sequence = new CommandSequence();
        $sequence
            ->addCommand(Command::DO, Option::SUPPRESS_GO_AHEAD)
            ->addCommand(Command::WILL, Option::ECHO);
        $this->sendSequence($sequence);

        //set login
        $sequence = new CommandSequence();
        $sequence->addText($login, Printer::CR);
        $this->sendSequence($sequence);

        $sequence = new CommandSequence();
        $sequence->addText('Password:');
        $this->awaitSequence($sequence);

        //set password
        $sequence = new CommandSequence();
        $sequence->addText($password, Printer::CR);
        $this->sendSequence($sequence);
        return $this->awaitPrompt($promptPattern ?? $this->promptPattern);
    }

    public function sendMessage(string $message, string $promptPattern = null, int $timelimit = null) : string
    {
        if (empty($timelimit)) {
            $timelimit = $this->timelimit;
        } else {
            $timelimit *= 1000;
        }
        if (empty($promptPattern)) {
            $promptPattern = $this->promptPattern;
        }
        $sequence = new CommandSequence();
        $sequence->addText($message, Printer::CR);
        $this->sendSequence($sequence);
        $result = $this->awaitPrompt($promptPattern, $timelimit);
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
                    . 'received: ' . $this->buffer
                );
            }
            throw new TelnetException('Telnet sequence await timeout exceeded. response: ' . $this->buffer);
        }
        $ep += strlen($needle);
        $raw = substr($this->buffer, 0, $ep);
        $this->buffer = substr($this->buffer, $ep);
        $sequence = new CommandSequence($raw);
        if (!empty($this->logger)) {
            $this->logger->info('received: ' . $sequence->dump() . ' time: ' . $counter * self::READ_TIMEOUT / 1000 . 'ms');
        }
        return $sequence;
    }

    public function awaitPrompt(string $promptPattern = null, int $timelimit = null) : CommandSequence
    {
        if (empty($promptPattern)) {
            $promptPattern = $this->promptPattern;
        }
        if (empty($timelimit)) {
            $timelimit = $this->timelimit;
        } else {
            $timelimit *= 1000;
        }
        $counter = 0;
        $match = [];
        socket_set_nonblock($this->socket);
        while (!preg_match($promptPattern, $this->buffer, $match, PREG_OFFSET_CAPTURE)
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
                    'expected: ' . $promptPattern
                    . 'received: ' . $this->buffer
                );
            }
            throw new TelnetException('Telnet prompt waiting time exceeded, response: ' . $this->buffer);
        }
        $ep = $match[0][1];
        $ep += strlen($match[0][0]);
        $raw = substr($this->buffer, 0, $ep);
        $this->buffer = substr($this->buffer, $ep);
        $sequence = new CommandSequence($raw);
        if (!empty($this->logger)) {
            $this->logger->info('received: ' . $sequence->dump() . ' time: ' . $counter * self::READ_TIMEOUT / 1000 . 'ms');
        }
        return $sequence;
    }
}
