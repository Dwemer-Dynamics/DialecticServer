<?php

class Logger {
    private const DEFAULT_LOG = __DIR__ . '/../log/dialectic.log';
    private static $CUSTOM_LOG;
    private static $REQUEST_ID = "";
    private static $PHASE_STARTS = [];
    private static $MAX_LOG_SIZE = 10485760;
    private static $MAX_ROTATED_LOGS = 3;
    private static $ROTATED_THIS_REQUEST = [];
    private static $FAILED_LOG_TARGETS = [];
    private static $HANDLING_PHP_ERROR = false;
    private static $KNOWN_LOGS = [
        'dialectic.log',
        'debugStream.log',
        'debugStreamParsed.log',
        'output_from_llm.log',
        'output_from_llm_fast.log',
        'output_from_llm_fast_step_2.log',
        'output_to_plugin.log',
        'context_sent_to_llm.log',
        'context_sent_to_llm_fast.log',
        'bug_func.txt',
        'manager.log',
        'monitor.log',
    ];
    private const LOG_LEVELS = [
        'trace' => 1,
        'debug' => 2,
        'info' => 3,
        'warn' => 4,
        'error' => 5,
    ];

    // minimum log level to write to the log (default = write everything)
    private static $_minLogLevel = 'trace';

    // timestamp format (default = ISO 8601)
    private static $_timestampFormat = 'Y-m-d\TH:i:sP';

    // minimum log level to backtrace (default = disabled)
    private static $_backtraceLevel = 9;

    // include arguments in backtrace (default no)
    private static $_backtraceArgs = false;

    // Set custom log file path
    public static function setCustomLog($logFile) {
        self::$CUSTOM_LOG = $logFile;
    }

    // Unset custom log file path
    public static function unsetCustomLog() {
        self::$CUSTOM_LOG = null;
    }

    public static function setRequestId($requestId): void {
        $requestId = trim((string)$requestId);
        if ($requestId === "") {
            return;
        }
        $requestId = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $requestId);
        self::$REQUEST_ID = substr($requestId, 0, 96);
        $GLOBALS["DIALECTIC_REQUEST_ID"] = self::$REQUEST_ID;
    }

    public static function getRequestId(): string {
        if (self::$REQUEST_ID !== "") {
            return self::$REQUEST_ID;
        }
        if (!empty($GLOBALS["DIALECTIC_REQUEST_ID"])) {
            self::setRequestId($GLOBALS["DIALECTIC_REQUEST_ID"]);
            return self::$REQUEST_ID;
        }
        return "";
    }

    public static function ensureRequestId(string $prefix = "req"): string {
        $existing = self::getRequestId();
        if ($existing !== "") {
            return $existing;
        }
        $seed = $prefix . "_" . str_replace(".", "", uniqid("", true));
        self::setRequestId($seed);
        return self::$REQUEST_ID;
    }

    public static function bootstrapRequestId(string $prefix = "req"): string {
        $candidate = $_SERVER["HTTP_X_DIALECTIC_REQUEST_ID"] ?? $_SERVER["HTTP_X_REQUEST_ID"] ?? "";
        if ($candidate !== "") {
            self::setRequestId($candidate);
        }
        return self::ensureRequestId($prefix);
    }

    private static function resolveLogFile($logFile) {
        if ($logFile == self::DEFAULT_LOG && isset(self::$CUSTOM_LOG) && !empty(self::$CUSTOM_LOG)) {
            $logFile = self::$CUSTOM_LOG;
        } elseif ($logFile == self::DEFAULT_LOG) {
            $logFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . "log" . DIRECTORY_SEPARATOR . "dialectic.log";
        }

        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        self::rotateLogIfTooLarge($logFile);
        return $logFile;
    }

    // Ex: Logger::setLevel("warn") to suppress trace, debug, and info messages
    public static function setLevel($level) {
        if (isset(self::LOG_LEVELS[$level])) {
            self::$_minLogLevel = $level;
        } else {
            error_log("[error] Invalid log level specified: {$level}");
        }
    }

    // Ex: Logger::setBacktrace("disabled") to suppress backtrace, Logger::setBacktrace("warn") to show stack and  for warnings and errors
    public static function setBacktrace($level, $exposeArgs=false) {
        if (isset(self::LOG_LEVELS[$level])) {
            self::$_backtraceLevel = self::LOG_LEVELS[$level];
            self::$_backtraceArgs = $exposeArgs;
        } else {
            self::$_backtraceLevel = 9;
            self::$_backtraceArgs = false;
        }
    }

    // Can call with no parameter or an empty string to omit the timestamp from logs
    public static function setTimestampFormat($format = "") {
        self::$_timestampFormat = $format;
    }

    private static function shouldLog($level) {
        return self::LOG_LEVELS[$level] >= self::LOG_LEVELS[self::$_minLogLevel];
    }

    private static function shouldBacktrace($level) {
        $b_res = false;
        
        if (isset(self::LOG_LEVELS[$level])) {
            $b_res = (self::LOG_LEVELS[$level] >= self::$_backtraceLevel);
        }
        
        return $b_res; 
    }

    private static function log($level, $message, $logFile) {
        if (!self::shouldLog($level)) {
            return;
        }

        $timestamp = self::$_timestampFormat ? "[".date(self::$_timestampFormat)."] " : "";

        if (self::shouldBacktrace($level)) {
            ob_start();
            if (self::$_backtraceArgs)
                debug_print_backtrace(0, 7);
            else
                debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 7);
            $s_trace = ob_get_contents();
            ob_end_clean();
            if (str_contains($s_trace, '#1')) {
                $s_trace = strstr($s_trace, '#1');
            }
        } else $s_trace = "";
        
        $requestId = self::getRequestId();
        $requestPrefix = $requestId !== "" ? "[rid={$requestId}] " : "";
        $logEntry = "{$timestamp}[{$level}] {$requestPrefix}{$message}\n{$s_trace}";
        

        $logFile = self::resolveLogFile($logFile);

        self::appendToLog($logFile, $logEntry);

        // also write to apache error log
        if (in_array(strtolower($level), ["warn", "error"])) {
            $logEntry = "[{$level}] {$requestPrefix}{$message}";
            error_log($logEntry);
        }
    }

    private static function appendToLog(string $logFile, string $logEntry): bool
    {
        if (isset(self::$FAILED_LOG_TARGETS[$logFile])) {
            return false;
        }

        $written = @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        if ($written !== false) {
            return true;
        }

        self::$FAILED_LOG_TARGETS[$logFile] = true;
        error_log("[WARN] Dialectic logger cannot write to {$logFile}; further writes to this target are suppressed for this request");
        return false;
    }

    public static function phaseStart(string $phase, array $context = []): void {
        $phase = trim($phase);
        if ($phase === "") {
            return;
        }
        self::$PHASE_STARTS[$phase] = microtime(true);
        self::debug("[phase:start] {$phase}" . self::formatContext($context));
    }

    public static function phaseEnd(string $phase, array $context = [], string $level = "debug"): void {
        $phase = trim($phase);
        if ($phase === "") {
            return;
        }
        $elapsedMs = null;
        if (isset(self::$PHASE_STARTS[$phase])) {
            $elapsedMs = round((microtime(true) - self::$PHASE_STARTS[$phase]) * 1000, 2);
            unset(self::$PHASE_STARTS[$phase]);
        }
        if ($elapsedMs !== null) {
            $context = array_merge(["elapsed_ms" => $elapsedMs], $context);
        }
        if (!isset(self::LOG_LEVELS[$level])) {
            $level = "debug";
        }
        self::log($level, "[phase:end] {$phase}" . self::formatContext($context), self::DEFAULT_LOG);
    }

    public static function summarizePayload($value, int $maxTextLength = 180) {
        if (is_array($value)) {
            $summary = [];
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $summary[$key] = [
                        "type" => self::isList($item) ? "list" : "object",
                        "count" => count($item),
                    ];
                } elseif (is_string($item)) {
                    $summary[$key] = self::truncateText($item, $maxTextLength);
                } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                    $summary[$key] = $item;
                } else {
                    $summary[$key] = gettype($item);
                }
            }
            return $summary;
        }

        if (is_string($value)) {
            return self::truncateText($value, $maxTextLength);
        }

        return $value;
    }

    public static function formatContext(array $context): string {
        if (empty($context)) {
            return "";
        }
        $parts = [];
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode(self::summarizePayload($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $value = $value ? "true" : "false";
            } elseif ($value === null) {
                $value = "null";
            } else {
                $value = (string)$value;
            }
            $parts[] = "{$key}=" . self::truncateText($value, 220);
        }
        return " " . implode(" ", $parts);
    }

    public static function rotateKnownLogs(?string $root = null): void {
        $root = $root ?: dirname(__DIR__);
        $logDir = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "log";
        foreach (self::$KNOWN_LOGS as $name) {
            self::rotateLogIfTooLarge($logDir . DIRECTORY_SEPARATOR . $name);
        }
    }

    public static function trace($message, $logFile = self::DEFAULT_LOG) {
        self::log("trace", $message, $logFile);
    }

    public static function debug($message, $logFile = self::DEFAULT_LOG) {
        self::log("debug", $message, $logFile);
    }

    public static function info($message, $logFile = self::DEFAULT_LOG) {
        self::log("info", $message, $logFile);
    }

    public static function warn($message, $logFile = self::DEFAULT_LOG) {
        self::log("warn", $message, $logFile);
    }

    public static function error($message, $logFile = self::DEFAULT_LOG) {
        self::log("error", $message, $logFile);
    }

    // write uncaught errors to the DIALECTIC log in addition to the apache log
    public static function errorHandler(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if (self::$HANDLING_PHP_ERROR) {
            return false;
        }

        if (error_reporting() === 0) {// when error reporting is suppressed
            return false;
        }

        self::$HANDLING_PHP_ERROR = true;

        switch ($errno) {
            case E_USER_ERROR:
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
                $level = 'error';
                break;
            case E_WARNING:
            case E_USER_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
                $level = 'warn';
                break;
            case E_NOTICE:
            case E_USER_NOTICE:
                $level = 'info';
                break;
            default:
                $level = 'debug'; // For other less critical errors
                break;
        }

        // obey the minimum log level for the DIALECTIC log (but still write uncaught errors to the apache log)
        if (self::shouldLog($level)) {
            $timestamp = self::$_timestampFormat ? "[".date(self::$_timestampFormat)."] " : "";
            $logEntry = "{$timestamp}[{$level}] %s in %s on line %d\n";
            $formattedMessage = sprintf($logEntry, $errstr, $errfile, $errline);

            // Logger failures are suppressed and remembered so this error handler cannot recurse.
            self::appendToLog(self::resolveLogFile(self::DEFAULT_LOG), $formattedMessage);
        }

        self::$HANDLING_PHP_ERROR = false;

        // return false to allow PHP's default error handler to run as well
        return false;
    }

    // Delete log file if its size is greater than 25Mb
    public static function deleteLogIfTooLarge($logFile = null, $maxSize = 26214400) {
        if ($logFile === null) {
            $logFile = isset(self::$CUSTOM_LOG) && !empty(self::$CUSTOM_LOG) ? self::$CUSTOM_LOG : self::DEFAULT_LOG;
        }
        $logFile = self::resolveLogFile($logFile);
        if (file_exists($logFile) && filesize($logFile) > $maxSize) {
            self::rotateLogIfTooLarge($logFile, $maxSize);
        }
    }

    public static function rotateLogIfTooLarge(string $logFile, ?int $maxSize = null): void {
        $maxSize = $maxSize ?? self::$MAX_LOG_SIZE;
        if (isset(self::$ROTATED_THIS_REQUEST[$logFile])) {
            return;
        }
        self::$ROTATED_THIS_REQUEST[$logFile] = true;

        if (!is_file($logFile)) {
            return;
        }

        clearstatcache(true, $logFile);
        $size = @filesize($logFile);
        if ($size === false || $size <= $maxSize) {
            return;
        }

        for ($i = self::$MAX_ROTATED_LOGS; $i >= 1; $i--) {
            $from = $i === 1 ? $logFile : "{$logFile}." . ($i - 1);
            $to = "{$logFile}.{$i}";
            if (is_file($to)) {
                @unlink($to);
            }
            if (is_file($from)) {
                @rename($from, $to);
            }
        }
        @touch($logFile);
    }

    private static function truncateText(string $text, int $maxLength): string {
        $text = str_replace(["\r", "\n", "\t"], " ", $text);
        $text = preg_replace('/\s+/', ' ', $text);
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, max(0, $maxLength - 3)) . "...";
    }

    private static function isList(array $array): bool {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }
        return array_keys($array) === range(0, count($array) - 1);
    }
}

// use the errorHandler provided here to write errors to the DIALECTIC log in addition to the apache log
set_error_handler(["Logger", "errorHandler"]);

?>
