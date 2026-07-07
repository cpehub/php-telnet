# php-telnet
A standards-compliant Telnet client for PHP.

[![phpcs](https://github.com/cpehub/php-telnet/actions/workflows/phpcs.yml/badge.svg)](https://github.com/cpehub/php-telnet/actions/workflows/phpcs.yml) [![phpstan](https://github.com/cpehub/php-telnet/actions/workflows/phpstan.yml/badge.svg)](https://github.com/cpehub/php-telnet/actions/workflows/phpstan.yml) [![phpunit](https://github.com/cpehub/php-telnet/actions/workflows/phpunit.yml/badge.svg)](https://github.com/cpehub/php-telnet/actions/workflows/phpunit.yml)

php-telnet speaks the Telnet protocol correctly: it escapes `IAC` per RFC 854/855,
negotiates options with the RFC 1143 "Q Method" state machine, and answers the
server's negotiation automatically while you wait for a prompt. It ships with a
pluggable transport layer (raw sockets by default, or streams with optional TLS).

> **Upgrading from v1?** The behaviour changed in a few important ways (most
> notably `timelimit` is now in **milliseconds**). See
> [Upgrading from v1 to v2](#upgrading-from-v1-to-v2).

## Requirements

- PHP **8.2+**
- `ext-sockets` (used by the default transport)
- `psr/log` (pulled in automatically by Composer)

## Installation

```
composer require cpehub/php-telnet
```

## Basic usage

```php
use Cpehub\Telnet\Client;

// timelimit is in MILLISECONDS (5000 ms = 5 s).
$client = new Client($ip, 23, 5000);

$client->setPromptPattern('~\w+(>|#)$~'); // default await pattern
$client->login($login, $password);        // run the login procedure

$data = $client->sendMessage('show version'); // send a command, get the response
```

`sendMessage()` waits for the configured prompt pattern and returns the clean
response text (Telnet protocol bytes are stripped for you).

## Advanced usage

Build and exchange raw command sequences with `CommandSequence`:

```php
use Cpehub\Telnet\Client;
use Cpehub\Telnet\Components\Command;
use Cpehub\Telnet\Components\CommandSequence;
use Cpehub\Telnet\Components\Option;

$client = new Client($ip, 23, 5000);
$client->setPromptPattern('~\w+(>|#)$~');

// Send a low-level command sequence.
$sequence = new CommandSequence();
$sequence
    ->addCommand(Command::DO, Option::SUPPRESS_GO_AHEAD)
    ->addCommand(Command::WILL, Option::ECHO)
    ->addOption(Option::TERMINAL_TYPE, 'xterm');
$client->sendSequence($sequence);

// Wait for a specific command sequence from the server.
$await = new CommandSequence();
$await->addCommand(Command::DONT, Option::X_DISPLAY_LOCATION);
$client->awaitSequence($await);

// Send text and wait for a prompt.
$command = new CommandSequence();
$command->addText('enable');
$client->sendSequence($command);

$result = $client->awaitPrompt('~Password:~'); // wait for 'Password:' and return the response
```

> Note: `CommandSequence::compile()` doubles `IAC` (`0xFF`) bytes in text and
> subnegotiation parameters as required by RFC 854/855 — pass raw bytes and let
> the library escape them; do not double `0xFF` yourself.

## TLS ("telnets")

The default transport uses raw sockets. For an encrypted connection, inject a
`StreamTransport` created via its `tls()` named constructor (port 992 by default):

```php
use Cpehub\Telnet\Client;
use Cpehub\Telnet\Transport\StreamTransport;

$transport = StreamTransport::tls($ip, 992);

// The 5th constructor argument is the transport; pass null for logger to keep the default.
$client = new Client($ip, 992, 5000, null, $transport);
$client->setPromptPattern('~\w+(>|#)$~');
$client->login($login, $password);
```

## Logging

Pass any PSR-3 `LoggerInterface` to the constructor, or set it later:

```php
use Cpehub\Telnet\Client;

// Constructor: (ip, port, timelimit_ms, logger, ...)
$client = new Client($ip, 23, 5000, $logger);

// Or at any time:
$client->setLogger($logger);
```

Credentials are never logged: `login()` sends the password through a redacted
path, so the log shows `send: <redacted>` instead of the raw bytes.

## Exceptions

All exceptions extend `Cpehub\Telnet\Exceptions\TelnetException`:

- `Cpehub\Telnet\Exceptions\ConnectionException` — connection/transport failures
  (invalid host/port, connect error, peer closed the connection).
- `Cpehub\Telnet\Exceptions\ProtocolException` — malformed Telnet stream (e.g. a
  dangling `IAC`, a truncated negotiation or an unterminated subnegotiation).

Catching `TelnetException` still catches everything. Exception messages no longer
embed buffer contents (a security hardening change); use the value returned by the
`send*`/`await*` methods to read the response.

## Class synopsis

### `Cpehub\Telnet\Client`

```php
use Psr\Log\LoggerInterface;
use Cpehub\Telnet\Components\CommandSequence;
use Cpehub\Telnet\Protocol\NegotiationPolicy;
use Cpehub\Telnet\Transport\TransportInterface;

class Client
{
    public const BYTE_READ = 4096;
    public const DEFAULT_MAX_BUFFER = 16 * 1024 * 1024; // 16 MiB

    /**
     * @param string $ip        telnet host
     * @param int    $port      telnet port
     * @param int    $timelimit max awaiting time, in MILLISECONDS (default 1000 = 1 s)
     * @param LoggerInterface|null    $logger    PSR-3 logger
     * @param TransportInterface|null $transport transport (default: SocketTransport)
     * @param NegotiationPolicy|null  $policy    option-negotiation policy
     * @param int    $maxBuffer max accumulated data buffer, in bytes
     */
    public function __construct(
        string $ip,
        int $port = 23,
        int $timelimit = 1000,
        ?LoggerInterface $logger = null,
        ?TransportInterface $transport = null,
        ?NegotiationPolicy $policy = null,
        int $maxBuffer = self::DEFAULT_MAX_BUFFER
    );

    /** Set the default await pattern used by all requests. */
    public function setPromptPattern(string $promptPattern): self;

    /** Set the PSR-3 logger. */
    public function setLogger(LoggerInterface $logger): self;

    /** Run the login procedure. $promptPattern defaults to the configured pattern. */
    public function login(string $login, string $password, ?string $promptPattern = null): CommandSequence;

    /** Send a command and return the clean response text. */
    public function sendMessage(string $message, ?string $promptPattern = null, ?int $timelimit = null): string;

    /** Send a command without waiting for a response. */
    public function sendLastMessage(string $message): void;

    /** Send a command sequence. Pass $sensitive = true to redact it from logs. */
    public function sendSequence(CommandSequence $sequence, bool $sensitive = false): void;

    /** Wait for a specific command sequence and return the response sequence. */
    public function awaitSequence(CommandSequence $sequence, ?int $timelimit = null): CommandSequence;

    /**
     * Wait for a prompt and return the response sequence.
     *
     * Throws TelnetException if no prompt pattern is configured/passed, or if the
     * pattern is not a valid regex.
     */
    public function awaitPrompt(?string $promptPattern = null, ?int $timelimit = null): CommandSequence;
}
```

### `Cpehub\Telnet\Components\CommandSequence`

```php
class CommandSequence
{
    /** @param string|null $input optional raw byte string to parse into a sequence */
    public function __construct(?string $input = null);

    /** Add a command (optionally with an option byte). */
    public function addCommand(int $command, ?int $option = null): self;

    /** Add text/data. Accepts strings and int byte values (e.g. Printer::CR). */
    public function addText(int|string ...$parts): self;

    /** Add a subnegotiation option with its data. */
    public function addOption(int $option, string $data): self;

    /** Get all text parts from the sequence. */
    public function getText(): string;

    /** Human-readable dump of the sequence. */
    public function dump(): string;

    /** Compile the sequence to a byte string (doubles IAC in data and SB params). */
    public function compile(): string;
}
```

### `Cpehub\Telnet\Components\Command` constants

```php
Command {
    public const SE                = 0xF0;
    public const NOP               = 0xF1;
    public const DATA_MARK         = 0xF2;
    public const BREAK             = 0xF3;
    public const INTERRUPT_PROCESS = 0xF4;
    public const ABORT_OUTPUT      = 0xF5; // was ABOUT_OUTPUT in v1 (still available as a deprecated alias)
    public const ARE_YOU_THERE     = 0xF6;
    public const ERASE_CHARACTER   = 0xF7;
    public const ERASE_LINE        = 0xF8;
    public const GO_AHEAD          = 0xF9;
    public const SB                = 0xFA;
    public const WILL              = 0xFB;
    public const WONT              = 0xFC;
    public const DO                = 0xFD;
    public const DONT              = 0xFE;
    public const IAC               = 0xFF;
}
```

See https://www.rfc-editor.org/rfc/rfc854 for details.

### `Cpehub\Telnet\Components\Option` constants

```php
Option {
    public const TRANSMIT_BINARY     = 0x00; // https://www.rfc-editor.org/rfc/rfc856
    public const ECHO                = 0x01; // https://www.rfc-editor.org/rfc/rfc857
    public const SUPPRESS_GO_AHEAD   = 0x03; // https://www.rfc-editor.org/rfc/rfc858
    public const STATUS              = 0x05; // https://www.rfc-editor.org/rfc/rfc859
    public const END_OF_RECORD       = 0x19; // https://www.rfc-editor.org/rfc/rfc885
    public const TERMINAL_TYPE       = 0x18; // https://www.rfc-editor.org/rfc/rfc1091
    public const WINDOW_SIZE         = 0x1f; // https://www.rfc-editor.org/rfc/rfc1073
    public const TERMINAL_SPEED      = 0x20; // https://www.rfc-editor.org/rfc/rfc1079
    public const REMOTE_FLOW_CONTROL = 0x21; // https://www.rfc-editor.org/rfc/rfc1372
    public const TERMINAL_LINEMODE   = 0x22; // https://www.rfc-editor.org/rfc/rfc1184
    public const X_DISPLAY_LOCATION  = 0x23; // https://www.rfc-editor.org/rfc/rfc1096
    public const ENVIRONMENT         = 0x27; // https://www.rfc-editor.org/rfc/rfc1572
}
```

### `Cpehub\Telnet\Components\Printer` constants

```php
Printer {
    public const NL   = 0x00; // \0
    public const LF   = 0x0A; // \n
    public const CR   = 0x0D; // \r
    public const BELL = 0x07;
    public const BS   = 0x08;
    public const HT   = 0x09;
    public const VT   = 0x0B;
    public const FF   = 0x0C;
}
```

## Upgrading from v1 to v2

v2.0.0 is the first substantial release since v1.0.2. **Public method signatures
are preserved**, but three behavioural contracts changed. Read this before you
`composer update`.

| # | Change | v1.0.2 | v2.0.0 | What to do |
|---|--------|--------|--------|------------|
| A | **`timelimit` unit** | seconds | **milliseconds** | Multiply your old second values by 1000. `new Client($ip, 23, 5)` meant 5 s in v1; in v2 it means 5 ms — use `5000`. The default is unchanged in effect (`1` s → `1000` ms). Applies to the constructor and the per-call `$timelimit` in `sendMessage()`/`awaitSequence()`/`awaitPrompt()`. |
| B | **`CommandSequence::compile()` escapes IAC** | raw `0xFF` passed through | `0xFF` doubled in data and SB params (RFC 854/855) | Stop doubling `0xFF` yourself; pass raw bytes and let the library escape them. |
| C | **`login()` rewritten** | fixed WILL/DO burst + canned reply; waited for the literal string `'Password:'` | negotiation handled automatically by the read loop; password prompt matched with `~password[: ]*$~i` (case-insensitive, anchored) | Usually nothing. If you relied on the old negotiation choreography, review it. Make sure a prompt pattern is configured (see D). |
| D | **`awaitPrompt()` validates the pattern** | passed empty/`null` to `preg_match` and looped | throws `TelnetException` when no pattern is configured/passed, or when the regex is invalid | Call `setPromptPattern('...')` or pass a pattern explicitly. |
| E | **New Composer requirements** | only `php: ^8.2` | adds `ext-sockets` and `psr/log` | Ensure `ext-sockets` is enabled. `psr/log` is pulled in automatically (in v1 it was missing and the autoload could fatal). |
| F | **`setLogger()` fixed** | infinite recursion (stack overflow on first call) | assigns the logger | Nothing — it just works now. |
| G | **Constant renamed** | `Command::ABOUT_OUTPUT` | `Command::ABORT_OUTPUT` (old name kept as a deprecated alias, same value `0xF5`) | Migrate to `Command::ABORT_OUTPUT`. |
| H | **No buffer in errors/logs** | exception messages/logs embedded the raw buffer | messages omit buffer contents (security hardening) | If you parsed exception text to recover the response body, use the value returned by the `send*`/`await*` methods instead. |

Additive (non-breaking) improvements you can adopt:

- `sendSequence(CommandSequence $sequence, bool $sensitive = false)` — pass `true`
  to redact a sequence from logs.
- New options `Option::TRANSMIT_BINARY` (`0x00`) and `Option::END_OF_RECORD` (`0x19`).
- Typed exceptions `ConnectionException` and `ProtocolException` (both extend
  `TelnetException`).
- Optional TLS via `StreamTransport::tls()` (see [TLS](#tls-telnets)).
- New optional constructor arguments: `$transport`, `$policy`, `$maxBuffer`.

### Before / after

```php
// v1.0.2 — timelimit in SECONDS, misspelled method
$client = new Client($ip, 23, 5);
$client->setPromtPattern('~\w+(>|#)$~');
$client->login($login, $password);
$data = $client->sendMessage('show version');

// v2.0.0 — timelimit in MILLISECONDS, corrected method name
$client = new Client($ip, 23, 5000);
$client->setPromptPattern('~\w+(>|#)$~');
$client->login($login, $password);
$data = $client->sendMessage('show version');
```
