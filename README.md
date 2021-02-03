# php-telnet
telnet client for PHP

## install:
- run composer require skrip42/php-telnet

## base usage:
```php
.....
use Skrip42\Telnet\Client;
.....
$client = new Clent($ip); //create client
$client->setPromtPattern('~\w+(>|#)$~'); //add await pattern
$client->login($login, $password); //call login procedure

$data = $client->sendMessage($telnetCommand); //set command and get response

```

## advanced usage:
### low level command example:
```php
$sequense = new CommandSequence(); //create command sequence
$sequense
    ->addCommand(Command::DO, Option::SUPPRESS_GO_AHEAD) //add command
    ->addCommand(Command::WILL, Option::ECHO); //add command
    ->addOption(Option::TERMINAL_TYPE, 'x-term') //add option
$this->sendSequence($sequense); //send command sequence

$sequense = new CommandSequence(); //create awaiting sequence
$sequense->addCommand(Command::DONT, Option::X_DISPLAY_LOCATION); //add command
$this->awaitSequence($sequense); //await specific sequence

$sequense = new CommandSequence(); //create command
$sequence->addText('enable'); //add text command
$this->sendSequence($sequense); //send command sequence

$result = $this->awaitPrompt('~User>~'); //awaint 'User>' string and get result
```

### logging
```php
//you can set Psr\Log\LoggerInterface to client constructor;
$client = new Clent($ip, $port, $timelimit, $logger);
//or set it at any time
$client->setLogger($logger);
```

## Class synopsis
### Skrip42\Telnet\Client class synopsis:
```php
Client {
    /**
     * @param string $ip telnet host ip
     * @param int $port telnet host port
     * @param int $timelimit max awaiting time (ms)
     * @param LoggerInterface $logger client logger instance
     */
    public function __construct(string $ip, int $port = 23, int $timelimit = 100, Psr\Log\LoggerInterface $logger);

    /**
     * set default await pattern for all requests
     *
     * @param string $promtPattern set/change default await pattern
     */
    public function setPromtPattern(string $promtPattern) : self;

    /**
     * set psr logger for client
     *
     * @param LoggerInterface $logger set/change default logger instance
     */
    public function setLogger(Psr\Log\LoggerInterface $logger) : self;

    /**
     * execute login procedure
     *
     * @param string $login user login
     * @param string $password user password
     * @param string $promtPattern  await pattern (optional)
     *
     * @return CommandSequence (see Skrip42\Telet\Components\CommandSequence class synopsis)
     */
    public function login(string $login, string $password, string $promtPattern = null) : Skrip42\Telnet\Components\CommandSequence;


    /**
     * hight level method for send telnet command
     *
     * @param string $message telnet command string
     * @param string $promtPattern  await pattern (optional)
     * @param int $timelimit max awaiting time (optional)
     *
     * @return string
     */
    public function sendMessage(string $message, string $promtPattern = null, int $timelimit = null) : string;

    /**
     * send commands
     *
     * @param CommandSequence $swquence (see Skrip42\Telet\Components\CommandSequence class synopsis)
     */
    public function sendSequence(Skrip42\Telnet\Components\CommandSequence $sequence);

    /**
     * await specific command sequence from response and return response command sequence
     *
     * @param CommandSequence $sequence (see Skrip42\Telet\Components\CommandSequence class synopsis)
     * @param int $timelimit max awaiting time (optional)
     *
     * @return CommandSequence (see Skrip42\Telnet\Components\CommandSequence class synopsis)
     */
    public function awaitSequence(Skrip42\Telnet\Components\CommandSequence $sequence, int $timelimit = null) : Skrip42\Telnet\Components\CommandSequence;

    /**
     * await specific promt text from response and return response command sequence
     *
     * @param string $promtPattern  await pattern (optional)
     * @param int $timelimit max awaiting time (optional)
     *
     * @return CommandSequence (see Skrip42\Telnet\Components\CommandSequence class synopsis)
     */
    public function awaitPrompt(string $promtPattern = null, int $timeLimit = null) : Skrip42\Telnet\Components\CommandSequence;
}
```

### Skrip42\Telnet\Components\CommandSequence class synopsis:
```php
CommandSequence {

    /**
     * @param string $rawByteString if define prepare raw string to command sequence (optional)
     */
    public function __construct(string $rawByteString = null);

    /**
     * add command to sequence
     *
     * @param int $command telnet command (see Skrip42\Telnet\Components\Command constants)
     * @param int $options telnet options (see Skrip42\Telnet\Components\Command constants)
     */
    public function addCommand(int $command, int $option = null) : self;

    /**
     * add raw byte string to sequence
     *
     * @param ...$parts one or more raw byte strings
     */
    public function addText(...$parts) : self;

    /**
     * add option to sequence
     *
     * @param int $options telnet options (see Skrip42\Telnet\Components\Command constants)
     * @param string $data option data
     */
    public function addOption(int $option, string $data) : self;
    /**
     * get all text parts from sequence
     */
    public function getText() : string;
    /**
     * dump sequence
     */
    public function dump() : string;
    /**
     * compile sequence to one byte string
     */
    public function compile() : string;
}
```
### Skrip42\Telnet\Components\Command constants
```php
Command
{
    const SE                = 0xF0;
    const NOP               = 0xF1;
    const DATA_MARK         = 0xF2;
    const BREAK             = 0xF3;
    const INTERRUPT_PROCESS = 0xF4;
    const ABOUT_OUTPUT      = 0xF5;
    const ARE_TYOU_THERE    = 0xF6;
    const ERASE_CHARACTER   = 0xF7;
    const ERASE_LINE        = 0xF8;
    const GO_AHEAD          = 0xF9;
    const SB                = 0xFA;
    const WILL              = 0xFB;
    const WONT              = 0xFC;
    const DO                = 0xFD;
    const DONT              = 0xFE;
    const IAC               = 0xFF;
}
```
see https://tools.ietf.org/html/rfc854 to detail

### Skrip42\Telnet\Components\Option  constants
```php
Option
{
    /** https://tools.ietf.org/html/rfc857 */
    const ECHO                = 0x01;
    /** https://tools.ietf.org/html/rfc858 */
    const SUPPRESS_GO_AHEAD   = 0x03;
    /** https://tools.ietf.org/html/rfc859 */
    const STATUS              = 0x05;
    /** https://tools.ietf.org/html/rfc1091 */
    const TERMINAL_TYPE       = 0x18;
    /** https://tools.ietf.org/html/rfc1073 */
    const WINDOW_SIZE         = 0x1f;
    /** https://tools.ietf.org/html/rfc1079 */
    const TERMINAL_SPEED      = 0x20;
    /** https://tools.ietf.org/html/rfc1372 */
    const REMOTE_FLOW_CONTROL = 0x21;
    /** https://tools.ietf.org/html/rfc1184 */
    const TERMINAL_LINEMODE   = 0x22;
    /** https://tools.ietf.org/html/rfc1096 */
    const X_DISPLAY_LOCATION  = 0x23;
    /** https://tools.ietf.org/html/rfc1572 */
    const ENVIRONMENT         = 0x27;
}
```
### Skrip42\Telnet\Components\Printer constants
```php
Printer
{
    const NL   = 0x00; // \0
    const LF   = 0x0A; // \n
    const CR   = 0x0D; // \r
    const BELL = 0x07;
    const BS   = 0x08;
    const HT   = 0x09;
    const VT   = 0x0B;
    const FF   = 0x0C;
}
```
see https://tools.ietf.org/html/rfc854 to detail
