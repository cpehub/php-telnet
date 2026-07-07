<?php

namespace Cpehub\Telnet\Tests\Components;

use Cpehub\Telnet\Components\Command;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CommandTest extends TestCase
{
    #[Test]
    public function abortOutputHasTheCorrectByteValue(): void
    {
        $this->assertSame(0xF5, Command::ABORT_OUTPUT);
    }

    #[Test]
    public function deprecatedAliasStillPointsToAbortOutput(): void
    {
        $this->assertSame(Command::ABORT_OUTPUT, Command::ABOUT_OUTPUT);
    }

    #[Test]
    public function iacIsFinalByte(): void
    {
        $this->assertSame(0xFF, Command::IAC);
    }
}
