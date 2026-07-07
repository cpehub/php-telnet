<?php

namespace Cpehub\Telnet\Components;

class Option
{
    /** Binary Transmission (RFC 856). */
    public const TRANSMIT_BINARY     = 0x00;
    /** Echo (RFC 857). */
    public const ECHO                = 0x01;
    /** Suppress Go Ahead (RFC 858). */
    public const SUPPRESS_GO_AHEAD   = 0x03;
    /** Status (RFC 859). */
    public const STATUS              = 0x05;
    /** End of Record (RFC 885). */
    public const END_OF_RECORD       = 0x19;
    /** Terminal Type (RFC 1091). */
    public const TERMINAL_TYPE       = 0x18;
    /** Negotiate About Window Size / NAWS (RFC 1073). */
    public const WINDOW_SIZE         = 0x1f;
    /** Terminal Speed (RFC 1079). */
    public const TERMINAL_SPEED      = 0x20;
    /** Remote Flow Control (RFC 1372). */
    public const REMOTE_FLOW_CONTROL = 0x21;
    /** Linemode (RFC 1184). */
    public const TERMINAL_LINEMODE   = 0x22;
    /** X Display Location (RFC 1096). */
    public const X_DISPLAY_LOCATION  = 0x23;
    /** Environment / NEW-ENVIRON (RFC 1572). */
    public const ENVIRONMENT         = 0x27;
}
