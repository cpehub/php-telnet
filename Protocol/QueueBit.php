<?php

namespace Cpehub\Telnet\Protocol;

/**
 * The queue bit attached to a WANTNO/WANTYES state in the RFC 1143 "Q Method".
 * OPPOSITE records that the opposite request was queued while a negotiation was
 * already in flight, so it can be issued once the current one resolves.
 */
enum QueueBit
{
    case EMPTY;
    case OPPOSITE;
}
