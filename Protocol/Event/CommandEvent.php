<?php

namespace Cpehub\Telnet\Protocol\Event;

/**
 * A standalone telnet command with no option operand
 * (NOP, DM, BRK, IP, AO, AYT, EC, EL, GA, ...).
 */
final class CommandEvent implements TelnetEvent
{
    public function __construct(public readonly int $command)
    {
    }
}
