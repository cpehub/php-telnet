<?php

namespace Cpehub\Telnet\Tests\Protocol;

use Cpehub\Telnet\Components\Command;
use Cpehub\Telnet\Components\Option;
use Cpehub\Telnet\Protocol\Event\CommandEvent;
use Cpehub\Telnet\Protocol\Event\DataEvent;
use Cpehub\Telnet\Protocol\Event\NegotiationEvent;
use Cpehub\Telnet\Protocol\Event\SubnegotiationEvent;
use Cpehub\Telnet\Protocol\TelnetParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TelnetParserTest extends TestCase
{
    #[Test]
    public function itEmitsPlainDataAsOneEvent(): void
    {
        $events = (new TelnetParser())->push('hello');

        $this->assertCount(1, $events);
        $this->assertInstanceOf(DataEvent::class, $events[0]);
        $this->assertSame('hello', $events[0]->data);
    }

    #[Test]
    public function itParsesANegotiationCommand(): void
    {
        $raw = chr(Command::IAC) . chr(Command::DO) . chr(Option::SUPPRESS_GO_AHEAD);

        $events = (new TelnetParser())->push($raw);

        $this->assertCount(1, $events);
        $this->assertInstanceOf(NegotiationEvent::class, $events[0]);
        $this->assertSame(Command::DO, $events[0]->command);
        $this->assertSame(Option::SUPPRESS_GO_AHEAD, $events[0]->option);
    }

    #[Test]
    public function itSplitsDataAroundACommand(): void
    {
        $raw = 'ab'
            . chr(Command::IAC) . chr(Command::WILL) . chr(Option::ECHO)
            . 'cd';

        $events = (new TelnetParser())->push($raw);

        $this->assertCount(3, $events);
        $this->assertInstanceOf(DataEvent::class, $events[0]);
        $this->assertSame('ab', $events[0]->data);
        $this->assertInstanceOf(NegotiationEvent::class, $events[1]);
        $this->assertInstanceOf(DataEvent::class, $events[2]);
        $this->assertSame('cd', $events[2]->data);
    }

    #[Test]
    public function itUnescapesDoubledIacInData(): void
    {
        $raw = 'a' . chr(Command::IAC) . chr(Command::IAC) . 'b';

        $events = (new TelnetParser())->push($raw);

        $this->assertCount(1, $events);
        $this->assertInstanceOf(DataEvent::class, $events[0]);
        $this->assertSame("a\xFFb", $events[0]->data);
    }

    #[Test]
    public function itParsesSubnegotiationAndUnescapesParams(): void
    {
        $raw = chr(Command::IAC) . chr(Command::SB) . chr(Option::TERMINAL_TYPE)
            . 'x' . chr(Command::IAC) . chr(Command::IAC) . 'y'
            . chr(Command::IAC) . chr(Command::SE);

        $events = (new TelnetParser())->push($raw);

        $this->assertCount(1, $events);
        $this->assertInstanceOf(SubnegotiationEvent::class, $events[0]);
        $this->assertSame(Option::TERMINAL_TYPE, $events[0]->option);
        $this->assertSame("x\xFFy", $events[0]->parameters);
    }

    #[Test]
    public function itEmitsStandaloneCommands(): void
    {
        $raw = chr(Command::IAC) . chr(Command::NOP);

        $events = (new TelnetParser())->push($raw);

        $this->assertCount(1, $events);
        $this->assertInstanceOf(CommandEvent::class, $events[0]);
        $this->assertSame(Command::NOP, $events[0]->command);
    }

    #[Test]
    public function itResumesAcrossChunkBoundaries(): void
    {
        $parser = new TelnetParser();

        // IAC split from its command; command split from its option.
        $first = $parser->push('hi' . chr(Command::IAC));
        $this->assertCount(1, $first);
        $this->assertInstanceOf(DataEvent::class, $first[0]);
        $this->assertSame('hi', $first[0]->data);
        $this->assertFalse($parser->isIdle());

        $second = $parser->push(chr(Command::DO));
        $this->assertCount(0, $second);

        $third = $parser->push(chr(Option::SUPPRESS_GO_AHEAD) . 'ok');
        $this->assertCount(2, $third);
        $this->assertInstanceOf(NegotiationEvent::class, $third[0]);
        $this->assertSame(Command::DO, $third[0]->command);
        $this->assertSame(Option::SUPPRESS_GO_AHEAD, $third[0]->option);
        $this->assertInstanceOf(DataEvent::class, $third[1]);
        $this->assertSame('ok', $third[1]->data);
        $this->assertTrue($parser->isIdle());
    }

    #[Test]
    public function itResumesASubnegotiationSplitByteByByte(): void
    {
        $parser = new TelnetParser();
        $raw = chr(Command::IAC) . chr(Command::SB) . chr(Option::WINDOW_SIZE)
            . "\x00\x50\x00\x18"
            . chr(Command::IAC) . chr(Command::SE);

        $events = [];
        foreach (str_split($raw) as $byte) {
            $events = array_merge($events, $parser->push($byte));
        }

        $this->assertCount(1, $events);
        $this->assertInstanceOf(SubnegotiationEvent::class, $events[0]);
        $this->assertSame("\x00\x50\x00\x18", $events[0]->parameters);
        $this->assertTrue($parser->isIdle());
    }
}
