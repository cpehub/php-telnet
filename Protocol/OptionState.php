<?php

namespace Cpehub\Telnet\Protocol;

/**
 * Per-side option state from the RFC 1143 "Q Method".
 * An option is enabled if and only if its state is {@see OptionState::YES}.
 */
enum OptionState
{
    case NO;
    case WANTNO;
    case WANTYES;
    case YES;
}
