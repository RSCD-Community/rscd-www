<?php

namespace RSCD\Util;

/**
 * Cross-platform shell process execution with optional timeout support.
 *
 * PHP's built-in shell_exec() and exec() do not support timeouts, and
 * proc_open() requires careful stream handling to avoid deadlocks. This class
 * wraps proc_open() with non-blocking I/O and a polling loop so that:
 *  - stdout and stderr are captured without deadlocking on large output.
 *  - An optional millisecond timeout can be specified; the process is killed
 *    with SIGKILL (signal 9) if it exceeds the limit.
 *  - Special exit codes 187 (timeout) and 911 (proc_open failure) are used
 *    internally and translated to `false` in the public API.
 *
 * All public methods are static so callers do not need to instantiate this class.
 */
class Process {

    /**
     * Execute a shell command and return its combined stdout output as a string.
     *
     * This is a drop-in replacement for PHP's shell_exec() that adds timeout
     * support and merges stderr into stdout (matching the common `2>&1` pattern
     * used by callers like PDF::impose()).
     *
     * Returns false if the command timed out or if proc_open() failed to start
     * the process.
     *
     * @param  string     $command  Shell command to execute.
     * @param  int|false  $timeout  Millisecond timeout, or false for no limit.
     * @return string|false         Stdout (+ stderr) output, or false on timeout/failure.
     */
    public static function shell_exec(string $command, mixed $timeout = false) {
        $stdout = '';
        // stderr is intentionally merged into stdout ($stdout passed for both).
        $result = static::raw_exec($command, null, $stdout, $stdout, $timeout);
        // 187 = timeout, 911 = proc_open failure.
        if($result === 187 || $result === 911) {
            return false;
        }
        return $stdout;
    }

    /**
     * Execute a shell command, returning the last output line and optionally
     * populating an output array (mirrors PHP's built-in exec() signature).
     *
     * Differences from PHP's exec():
     * - Supports a millisecond timeout (proc killed with SIGKILL on expiry).
     * - Returns false instead of an empty string on timeout/failure.
     * - stderr is merged into stdout (same channel).
     *
     * Trailing empty lines are stripped from the output array to match
     * PHP's exec() behavior.
     *
     * @param  string      $command      Shell command to execute.
     * @param  array|null  &$output      If non-null, populated with each line of output.
     * @param  int|null    &$result_code If non-null, populated with the process exit code.
     * @param  int|false   $timeout      Millisecond timeout, or false for no limit.
     * @return string|false              Last line of output, empty string if no output, or false on failure.
     */
    public static function exec(string $command, array &$output = null, int &$result_code = null, mixed $timeout = false) {
        $stdout = '';
        $result = static::raw_exec($command, null, $stdout, $stdout, $timeout);

        // Propagate exit code to caller if they passed a variable.
        if($result_code !== null) {
            $result_code = $result;
        }

        // Split on any line-ending style (CRLF, CR, LF).
        $stdarr = preg_split('/(\r\n|\r|\n)/', $stdout);
        $arrlen = count($stdarr);

        // Strip trailing blank line that preg_split produces from a trailing newline.
        if(empty($stdarr[$arrlen - 1])) {
            unset($stdarr[$arrlen - 1]);
            $arrlen--;
        }

        // Populate caller's output array if provided.
        if($output !== null) {
            $output = $stdarr;
        }

        // Return false for timeout/failure exit codes.
        if($result === 187 || $result === 911) {
            return false;
        }

        // Return the last line of output, matching PHP's exec() contract.
        return !empty($stdarr) ? $stdarr[$arrlen - 1] : '';
    }

    /**
     * Low-level process executor using proc_open() with non-blocking I/O.
     *
     * Opens the command in a child process with three pipes (stdin, stdout,
     * stderr). All pipes are set to non-blocking mode to prevent the polling
     * loop from hanging. The loop reads available output every 100 ms and
     * checks the process status; it exits when the process terminates.
     *
     * Special return values (not real OS exit codes):
     * - 187: the process was killed because it exceeded $timeout milliseconds.
     * - 911: proc_open() returned false (process could not be started).
     *
     * Note: stderr and stdout are collected separately — the caller can choose
     * to merge them by passing the same variable for both $stdout and $stderr.
     *
     * @param  string     $command  Shell command to execute.
     * @param  mixed      $stdin    Data to write to the process's stdin pipe (or null).
     * @param  string     &$stdout  Populated with the process's stdout output.
     * @param  string     &$stderr  Populated with the process's stderr output.
     * @param  int|false  $timeout  Millisecond timeout, or false for no limit.
     * @return int                  Process exit code, 187 on timeout, or 911 if proc_open failed.
     */
    public static function raw_exec(string $command, mixed $stdin, string &$stdout, string &$stderr, mixed $timeout = false) {
        $pipes = [];
        // Open the process with three pipes: 0=stdin, 1=stdout, 2=stderr.
        $process = proc_open($command, [
            ['pipe','r'],
            ['pipe','w'],
            ['pipe','w']
        ], $pipes);

        // Record start time in milliseconds for timeout tracking.
        $start = floor(microtime(true) * 1000);
        $stdout = '';
        $stderr = '';

        if(is_resource($process)) {
            // Non-blocking mode prevents reads from stalling the polling loop.
            stream_set_blocking($pipes[0], 0);
            stream_set_blocking($pipes[1], 0);
            stream_set_blocking($pipes[2], 0);
            // Write stdin data (if any) then close the pipe so the child sees EOF.
            if($stdin !== null) {
                fwrite($pipes[0], $stdin);
            }
            fclose($pipes[0]);
        }

        // Poll the process until it exits or the timeout fires.
        while(is_resource($process)) {
            // Drain available data from stdout and stderr.
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            // Check timeout before checking process status.
            if($timeout !== false && floor(microtime(true) * 1000) - $start >= $timeout) {
                // Kill the process with SIGKILL (9) — no chance to clean up.
                proc_terminate($process, 9);
                return 187; // Sentinel: timeout.
            }

            $status = proc_get_status($process);

            if(!$status['running']) {
                // Final drain — capture any data written after the last read.
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                // Process has exited — close pipes and reap the process.
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return $status['exitcode'];
            }

            // Wait 100 ms before polling again to avoid busy-waiting.
            usleep(100000);
        }

        // proc_open() failed to return a resource.
        return 911; // Sentinel: process could not be opened.
    }

}
