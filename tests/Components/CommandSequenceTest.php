<?php
namespace Cpehub\Telnet\Tests\Components;

use Cpehub\Telnet\Components\Command;
use Cpehub\Telnet\Components\CommandSequence;
use Cpehub\Telnet\Components\Option;
use Cpehub\Telnet\Exceptions\ProtocolException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CommandSequenceTest extends TestCase
{
    #[Test]
    public function itParsesPlainText(): void
    {
        $sequence = new CommandSequence('hello');

        $this->assertSame('hello', $sequence->getText());
    }

    #[Test]
    public function itParsesANegotiationCommand(): void
    {
        $raw = chr(Command::IAC) . chr(Command::DO) . chr(Option::SUPPRESS_GO_AHEAD);

        $sequence = new CommandSequence($raw);

        $this->assertSame($raw, $sequence->compile());
    }

    #[Test]
    public function itParsesTextInterleavedWithCommands(): void
    {
        $raw = 'hi'
            . chr(Command::IAC) . chr(Command::WILL) . chr(Option::ECHO)
            . 'there';

        $sequence = new CommandSequence($raw);

        $this->assertSame('hithere', $sequence->getText());
        $this->assertSame($raw, $sequence->compile());
    }

    #[Test]
    public function itParsesSubnegotiation(): void
    {
        $raw = chr(Command::IAC) . chr(Command::SB)
            . chr(Option::TERMINAL_TYPE) . 'xterm'
            . chr(Command::IAC) . chr(Command::SE);

        $sequence = new CommandSequence($raw);

        $this->assertSame($raw, $sequence->compile());
    }

    #[Test]
    public function itThrowsOnDanglingIac(): void
    {
        $this->expectException(ProtocolException::class);

        new CommandSequence('data' . chr(Command::IAC));
    }

    #[Test]
    public function itThrowsOnUnterminatedSubnegotiation(): void
    {
        $this->expectException(ProtocolException::class);

        new CommandSequence(
            chr(Command::IAC) . chr(Command::SB) . chr(Option::TERMINAL_TYPE) . 'xterm'
        );
    }

    #[Test]
    public function itThrowsOnTruncatedNegotiationMissingOption(): void
    {
        $this->expectException(ProtocolException::class);

        new CommandSequence(chr(Command::IAC) . chr(Command::DO));
    }

    #[Test]
    public function itThrowsOnSubnegotiationMissingOptionByte(): void
    {
        $this->expectException(ProtocolException::class);

        new CommandSequence(chr(Command::IAC) . chr(Command::SB));
    }

    #[Test]
    public function builderCompilesNegotiationAndText(): void
    {
        $sequence = (new CommandSequence())
            ->addCommand(Command::DO, Option::SUPPRESS_GO_AHEAD)
            ->addText('ls', chr(Command::NOP));

        $expected = chr(Command::IAC) . chr(Command::DO) . chr(Option::SUPPRESS_GO_AHEAD)
            . 'ls' . chr(Command::NOP);

        $this->assertSame($expected, $sequence->compile());
    }
}
