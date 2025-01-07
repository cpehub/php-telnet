<?php
namespace Cpehub\Telnet\Components;

class Option
{
    /** Ожидает передачи последовательности символов rfc857 */
    const ECHO                = 0x01;
    /** Начало передачи последовательности символов rfc858 */
    const SUPPRESS_GO_AHEAD   = 0x03;
    /** Статус rfc859 */
    const STATUS              = 0x05;
    /** Тип терминала. rfc1091 */
    const TERMINAL_TYPE       = 0x18;
    /** Размер окна. rfc1073 */
    const WINDOW_SIZE         = 0x1f;
    /** Скорость терминала. rfc1079 */
    const TERMINAL_SPEED      = 0x20;
    /** Удаленное уравление потоком. rfc1372 */
    const REMOTE_FLOW_CONTROL = 0x21;
    /** Скорость терминала. rfc1184 */
    const TERMINAL_LINEMODE   = 0x22;
    /** Вариант ресположения дисплэя. rfc1096 */
    const X_DISPLAY_LOCATION  = 0x23;
    /** Окружение. rfc1572 */
    const ENVIRONMENT         = 0x27;
}
