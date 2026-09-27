<?php

use Strukt\CommandRunner;
use Strukt\TaskRegistry;
use Strukt\TaskWatcher;

if (function_exists('helper')) {
    helper('tasker');
}

if (! function_exists('task') && function_exists('helper_add') && helper_add('task')) {
    /**
     * Registers a task in the active Tasker application.
     *
     * @param string $name Command-line task name.
     * @param callable $function Task callback.
     * @return \Strukt\Task Registered task.
     * @throws \LogicException When no Tasker application is active.
     * @throws \InvalidArgumentException When the task name is invalid.
     * @throws \LogicException When the name is already registered.
     */
    function task(string $name, callable $function): \Strukt\Task
    {
        return TaskRegistry::current()->add($name, $function);
    }
}

if (! function_exists('go') && function_exists('helper_add') && helper_add('go')) {
    /**
     * Executes another registered task from the active task file.
     *
     * @param string $name Task name.
     * @param mixed ...$args Positional task arguments.
     * @return mixed Nested task result.
     * @throws \LogicException When no Tasker application is active.
     * @throws \InvalidArgumentException When the task is unknown or arguments are invalid.
     */
    function go(string $name, mixed ...$args): mixed
    {
        return TaskRegistry::current()->run($name, $args);
    }
}

if (! function_exists('writeln') && function_exists('helper_add') && helper_add('writeln')) {
    /**
     * Writes one value followed by the platform line ending.
     *
     * @param string|int|float|bool|null $line Value to write.
     * @return void
     */
    function writeln(string | int | float | bool | null $line): void
    {
        echo (string) $line . PHP_EOL;
    }
}

if (! function_exists('watch') && function_exists('helper_add') && helper_add('watch')) {
    /**
     * Watches a file or directory and calls back when content changes.
     *
     * @param string $path File or directory to watch.
     * @param callable $callback Receives changed path names.
     * @param int $wait Polling interval in seconds.
     * @return void
     * @throws \InvalidArgumentException When the path or interval is invalid.
     */
    function watch(string $path, callable $callback, int $wait = 1): void
    {
        (new TaskWatcher())->watch($path, $callback, $wait);
    }
}

if (! function_exists('run') && function_exists('helper_add') && helper_add('run')) {
    /**
     * Runs a shell command and returns its output and error streams.
     *
     * The tuple shape is retained for compatibility with Strukt 1 task
     * files. Use Strukt\CommandRunner directly when the exit status is also
     * required.
     *
     * @param string $command Shell command to execute.
     * @param callable|null $callback Receives complete standard-output lines.
     * @param string|null $input Optional standard-input bytes.
     * @return array{0: string, 1: string} Standard output and error.
     * @throws \InvalidArgumentException When the command is empty.
     * @throws \RuntimeException When the process cannot be started.
     */
    function run(string $command,  ? callable $callback = null, ?string $input = null) : array
    {
        return (new CommandRunner())->run($command, $callback, $input)->toArray();
    }
}
