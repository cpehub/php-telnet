<?php
namespace Cpehub\Telnet\Components;

class Command
{
    /** End of subnegotiation parameters (terminates a sequence started by SB). */
    const SE                = 0xF0;
    /** No operation. */
    const NOP               = 0xF1;
    /**
     * Data Mark: the synch marker in the data stream.
     * This command is always accompanied by a TCP Urgent notification.
     */
    const DATA_MARK         = 0xF2;
    /** The "Break" or "Attention" key was pressed. */
    const BREAK             = 0xF3;
    /** Suspend, interrupt, abort or terminate the running process. */
    const INTERRUPT_PROCESS = 0xF4;
    /**
     * Abort Output: suppress the output of the current process.
     * Also sends a Synch signal to the user.
     */
    const ABORT_OUTPUT      = 0xF5;
    /**
     * Abort Output.
     *
     * @deprecated Misspelled historical alias. Use {@see Command::ABORT_OUTPUT} instead.
     */
    const ABOUT_OUTPUT      = 0xF5;
    /** Are You There: request a visible response from the terminal. */
    const ARE_YOU_THERE     = 0xF6;
    /** Erase Character: the receiver should delete the previous character if possible. */
    const ERASE_CHARACTER   = 0xF7;
    /**
     * Erase Line: delete the last entered line, i.e. all data
     * received since the last new line.
     */
    const ERASE_LINE        = 0xF8;
    /** Go Ahead: the other end may now transmit. */
    const GO_AHEAD          = 0xF9;
    /** Begin subnegotiation of an option that requires parameters. */
    const SB                = 0xFA;
    /**
     * Indicates the desire to begin performing, or confirms that it is now
     * performing, the indicated option.
     */
    const WILL              = 0xFB;
    /** Indicates the refusal to begin or to continue performing the indicated option. */
    const WONT              = 0xFC;
    /**
     * Requests that the other party begin performing, or confirms that it is
     * expected to perform, the indicated option.
     */
    const DO                = 0xFD;
    /**
     * Requests that the other party stop performing, or confirms that it is
     * no longer performing, the indicated option.
     */
    const DONT              = 0xFE;
    /** Interpret As Command. */
    const IAC               = 0xFF;
}
