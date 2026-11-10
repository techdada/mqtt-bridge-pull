<?php
// systemd consumes these syslog priorities with SyslogLevelPrefix=yes.
// Logging never opens a file; timestamps and retention belong to journald.
class BridgeLog {
    public static $debug = false;
    private static function write($priority, $message) {
        fwrite(STDERR, '<'.$priority.'>'.str_replace(["\r", "\n"], ' ', $message).PHP_EOL);
    }
    public static function info($message) { self::write(6, $message); }
    public static function warning($message) { self::write(4, $message); }
    public static function error($message) { self::write(3, $message); }
    public static function debug($message) { if (self::$debug) self::write(7, $message); }
}
