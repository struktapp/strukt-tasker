<?php
namespace Strukt;

/** Contains the captured streams and exit status of one shell command. */
final class CommandResult
{
    /**
     * Creates a command result.
     *
     * @param string $output Captured standard output.
     * @param string $error Captured standard error.
     * @param int $exitCode Process exit code.
     */
    public function __construct(
        private string $output,
        private string $error,
        private int $exitCode,
    ) {
    }

    /**
     * Returns captured standard output.
     *
     * @return string Standard output.
     */
    public function output(): string
    {
        return $this->output;
    }

    /**
     * Returns captured standard error.
     *
     * @return string Standard error.
     */
    public function error(): string
    {
        return $this->error;
    }

    /**
     * Returns the process exit code.
     *
     * @return int Exit code.
     */
    public function exitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * Checks whether the command completed successfully.
     *
     * @return bool True for exit code zero.
     */
    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Converts the result to the legacy two-element tuple shape.
     *
     * @return array{0: string, 1: string} Output and error strings.
     */
    public function toArray(): array
    {
        return [$this->output, $this->error];
    }
}
