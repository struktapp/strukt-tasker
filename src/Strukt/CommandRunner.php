<?php
namespace Strukt;

use Strukt\Process;

/** Runs task commands while capturing output without allowing pipe deadlocks. */
final class CommandRunner
{
    /**
     * Runs one shell command.
     *
     * Commands without input use Strukt Process, which drains standard output
     * and standard error concurrently. The input path uses the same three-pipe
     * protocol and additionally writes the supplied bytes before waiting.
     *
     * @param string $command Shell command to execute.
     * @param callable|null $callback Receives complete output lines.
     * @param string|null $input Optional bytes to send to standard input.
     * @return CommandResult Captured output, error, and exit status.
     * @throws \InvalidArgumentException When the command is empty.
     * @throws \RuntimeException When the process cannot be started or input cannot be written.
     */
    public function run(string $command,  ? callable $callback = null, ?string $input = null) : CommandResult
    {
        if (trim($command) === '') {
            throw new \InvalidArgumentException('A task command cannot be empty.');
        }

        if ($input === null) {
            $result  = Process::run([$command], $callback);
            $process = $result->resource();

            return new CommandResult(
                $result->outputs() ?? '',
                $result->errors() ?? '',
                $process->close(),
            );
        }

        return $this->runWithInput($command, $callback, $input);
    }

    /**
     * Runs a command while sending input through its standard-input pipe.
     *
     * @param string $command Shell command.
     * @param callable|null $callback Output-line callback.
     * @param string $input Input bytes.
     * @return CommandResult Captured command result.
     * @throws \RuntimeException When process setup, input, or stream handling fails.
     */
    private function runWithInput(string $command,  ? callable $callback, string $input) : CommandResult
    {
        $process = @proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process) || ! is_array($pipes) || count($pipes) < 3) {
            throw new \RuntimeException(sprintf('Unable to start task command: %s', $command));
        }

        $stdout     = $pipes[1];
        $stderr     = $pipes[2];
        $streams    = [$stdout, $stderr];
        $output     = '';
        $error      = '';
        $lineBuffer = '';

        try {
            $this->writeInput($pipes[0], $input);
            @fclose($pipes[0]);

            foreach ($streams as $stream) {
                stream_set_blocking($stream, false);
            }

            while ($streams !== []) {
                $read     = $streams;
                $write    = null;
                $except   = null;
                $selected = @stream_select($read, $write, $except, 0, 100000);

                if ($selected === false) {
                    $streams = $this->removeClosedStreams($streams);
                    if ($streams !== []) {
                        usleep(1000);
                    }
                    continue;
                }

                if ($selected === 0) {
                    $streams = $this->removeClosedStreams($streams);
                    continue;
                }

                foreach ($read as $stream) {
                    $chunk = @fread($stream, 8192);
                    if ($chunk === false) {
                        $streams = $this->removeStream($streams, $stream);
                        continue;
                    }

                    if ($chunk === '') {
                        if (feof($stream)) {
                            $streams = $this->removeStream($streams, $stream);
                        }
                        continue;
                    }

                    if ($stream === $stdout) {
                        $output     .= $chunk;
                        $lineBuffer .= $chunk;
                        $this->dispatchLines($lineBuffer, $callback);
                    } else {
                        $error .= $chunk;
                    }
                }
            }

            if ($lineBuffer !== '' && $callback !== null) {
                $callback($lineBuffer);
            }

            @fclose($stdout);
            @fclose($stderr);
            $exitCode = @proc_close($process);
            $process  = null;

            return new CommandResult($output, $error, is_int($exitCode) ? $exitCode : -1);
        } catch (\Throwable $exception) {
            @fclose($pipes[0]);
            @fclose($stdout);
            @fclose($stderr);
            @proc_terminate($process);
            @proc_close($process);

            throw $exception;
        }
    }

    /**
     * Writes all input bytes to a process pipe.
     *
     * @param mixed $pipe Standard-input pipe resource.
     * @param string $input Bytes to write.
     * @return void
     * @throws \RuntimeException When a write fails.
     */
    private function writeInput(mixed $pipe, string $input): void
    {
        if (! is_resource($pipe)) {
            throw new \RuntimeException('The task command input pipe is unavailable.');
        }

        $offset = 0;
        $length = strlen($input);
        while ($offset < $length) {
            $written = @fwrite($pipe, substr($input, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write task command input.');
            }
            $offset += $written;
        }
    }

    /**
     * Dispatches complete output lines and retains a partial final line.
     *
     * @param string $buffer Pending output buffer, updated by reference.
     * @param callable|null $callback Output callback.
     * @return void
     */
    private function dispatchLines(string &$buffer,  ? callable $callback) : void
    {
        while (($newline = strpos($buffer, "\n")) !== false) {
            $line   = substr($buffer, 0, $newline + 1);
            $buffer = substr($buffer, $newline + 1);
            if ($callback !== null) {
                $callback($line);
            }
        }
    }

    /**
     * Removes streams that reached EOF.
     *
     * @param list<resource> $streams Active streams.
     * @return list<resource> Streams still open.
     */
    private function removeClosedStreams(array $streams): array
    {
        foreach ($streams as $stream) {
            if (feof($stream)) {
                $streams = $this->removeStream($streams, $stream);
            }
        }

        return $streams;
    }

    /**
     * Removes one stream by resource identity.
     *
     * @param list<resource> $streams Active streams.
     * @param resource $target Stream to remove.
     * @return list<resource> Remaining streams.
     */
    private function removeStream(array $streams, mixed $target): array
    {
        foreach ($streams as $index => $stream) {
            if ($stream === $target) {
                unset($streams[$index]);
            }
        }

        return array_values($streams);
    }
}
