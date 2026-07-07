<?php

namespace Cpehub\Telnet\Protocol\Event;

/**
 * A completed subnegotiation block: IAC SB <option> <params> IAC SE.
 * The parameters are already IAC-unescaped.
 */
final class SubnegotiationEvent implements TelnetEvent
{
    public function __construct(
        public readonly int $option,
        public readonly string $parameters
    ) {
    }
}
