Strukt Tasker
=============

[![License](https://img.shields.io/packagist/l/strukt/tasker.svg)](https://packagist.org/packages/strukt/tasker)

Strukt Tasker is a small PHP task runner for project-local commands. It loads
`tasker.php`, registers named PHP callables, executes one task from the shell,
and provides helpers for composing tasks, watching files, and running external
commands.

<center><img src="tasker.jpg" width="200" height="200"></center>

This is the Strukt 3 rewrite of `strukt1/strukt-tasker`. It keeps the simple
task-file workflow while adding PHP 8.2 types, deterministic registration,
testable application and watcher classes, useful exit codes, complete stream
capture, and safe process cleanup.

Requirements
------------

- PHP 8.2 or newer
- `proc_open()` for the `run()` helper and command execution
- `ext-zip`, inherited from the Strukt Fs dependency
- `strukt/event`, `strukt/fs`, and `strukt/process`

Installation
------------

```sh
composer require strukt/tasker
```

Composer installs the `tasker` executable in `vendor/bin`:

```sh
vendor/bin/tasker list
```

When the command is run in a project without a `tasker.php`, Tasker creates a
starter file containing a `test` task.

Quick start
-----------

Create a `tasker.php` file:

```php
<?php

/**
 * Say hello to someone.
 */
task('hello', static function (string $name): void {
    writeln("Hello {$name}!");
});

/**
 * Run a shell command.
 */
task('php-version', static function (): void {
    [$output, $error] = run(PHP_BINARY . ' --version');
    echo $error === '' ? $output : $error;
});
```

Run the tasks with positional arguments:

```sh
vendor/bin/tasker list
vendor/bin/tasker hello Ada
vendor/bin/tasker php-version
```

Tasker passes command-line values to the task callback as strings. Required and
maximum positional argument counts are checked before invocation.

Built-in commands
-----------------

The application registers these tasks before loading the project task file:

| Command | Description |
| --- | --- |
| `list` | Lists built-in and project tasks in registration order. |
| `help` | Shows usage and the task list. |
| `version` | Prints the Tasker version. |

The equivalent switches are `--help`/`-h` and `--version`/`-V`.

Exit statuses are predictable:

| Status | Meaning |
| ---: | --- |
| `0` | The task completed successfully. |
| `1` | The task file or task raised an exception. |
| `64` | No task was supplied. |
| `127` | The requested task was not registered. |

Task-file helpers
-----------------

Composer loads `src/helpers.php` automatically. The helpers are registered
conditionally so a host application can provide a function with the same name.

### `task()`

```php
task(string $name, callable $function): Strukt\Task
```

Registers a task in the active Tasker application. The callback's PHPDoc first
description paragraph is shown by `list`.

```php
/** Remove generated files. */
task('clean', static function (): void {
    // cleanup
});
```

Task names are unique. Registering a duplicate throws `LogicException` instead
of silently replacing an earlier task.

### `go()`

```php
go(string $name, mixed ...$args): mixed
```

Executes another registered task in the active application and returns its
result. It is useful for composing larger tasks:

```php
task('all', static function (): void {
    go('clean');
    go('build');
});
```

### `writeln()`

```php
writeln(string|int|float|bool|null $line): void
```

Writes one scalar value followed by `PHP_EOL`.

### `watch()`

```php
watch(string $path, callable $callback, int $wait = 1): void
```

Polls a file or directory until the process is stopped. The callback receives
an array of added, removed, or content-changed paths. The first scan establishes
a baseline and does not call the callback.

```php
task('watch:js', static function (): void {
    watch('app/js', static function (array $files): void {
        foreach ($files as $file) {
            writeln("Changed: {$file}");
        }
    });
});
```

`watch()` throws `InvalidArgumentException` for an unknown path or negative
interval. `TaskWatcher` provides an iteration limit and injected sleeper for
tests and long-running application integrations.

### `run()`

```php
run(
    string $command,
    ?callable $callback = null,
    ?string $input = null,
): array{0: string, 1: string}
```

Runs a shell command and returns `[output, error]`. The optional callback
receives complete standard-output lines, including their line endings. The
optional input is written exactly to standard input and the input pipe is then
closed.

```php
[$output, $error] = run('printf "ready\\n"');

[$output, $error] = run(
    PHP_BINARY . ' -r ' . escapeshellarg('echo stream_get_contents(STDIN);'),
    input: "from task\n",
);
```

The tuple preserves the Strukt 1 task-file API. Use `CommandRunner` directly
when the process exit code is important.

The `strukt/event` dependency is load-bearing: every `Strukt\Task` wraps a
`Strukt\Event` (see the Task API below). Its `event()` and `cmd()` helpers are
also available in the same task file after Composer loads the package.

Task API
--------

### `Strukt\Task`

`Task` composes `Strukt\Event`. It owns the task name, description, and
parameter metadata, while the wrapped event retains the callable as a `Closure`,
exposes its `ReflectionFunction`, and applies arguments during execution. This
keeps Task a thin descriptor over the event package instead of reimplementing
callable handling.

```php
new Task(string $name, callable $callback, ?string $description = null)
$task->name(): string
$task->callback(): Closure
$task->reflection(): ReflectionFunction
$task->description(): string
$task->parameters(): list<array>
$task->invoke(array $arguments = []): mixed
$task->invoke(mixed ...$arguments): mixed // through __invoke()
```

`parameters()` returns records with `name`, `type`, `required`, and `variadic`
keys. `invoke()` supports positional integer-keyed arrays and PHP named
argument arrays. Invalid positional counts throw `InvalidArgumentException`.

### `Strukt\TaskRegistry`

`TaskRegistry` is an ordered, isolated collection of tasks:

```php
$registry = new TaskRegistry();
$registry->add(string $name, callable $callback, ?string $description = null): Task
$registry->get(string $name): ?Task
$registry->has(string $name): bool
$registry->names(): list<string>
$registry->all(): list<Task>
$registry->run(string $name, array $arguments = []): mixed
$registry->reset(): void
$registry->count(): int
```

The registry also implements `IteratorAggregate` and can be iterated as
`Task` values keyed by task name. `add()` rejects duplicate names.

Task-file helpers use the active registry during `Tasker::run()`:

```php
$previous = TaskRegistry::activate($registry);
TaskRegistry::current();       // $registry
TaskRegistry::active();         // $registry or null
TaskRegistry::restore($previous);
```

Calling `task()` or `go()` outside an active application throws
`LogicException`, which prevents accidental writes to a hidden global queue.

### `Strukt\Tasker`

`Tasker` is the command-line application object:

```php
new Tasker(
    ?string $directory = null,
    string $taskFile = 'tasker.php',
    ?string $version = null,
    ?callable $outputWriter = null,
    ?callable $errorWriter = null,
)
$tasker->run(array $argv): int
$tasker->registry(): TaskRegistry
$tasker->taskFile(): string
$tasker->taskList(): string
Tasker::defaultTaskFile(): string
```

`directory` defaults to the current working directory. A relative task-file
name is resolved below that directory. Output and error writers receive one
string at a time, making the CLI easy to test or embed.

The `bin/tasker` executable constructs this class with the process argument
vector and exits with the value returned by `run()`.

### `Strukt\TaskWatcher`

`TaskWatcher` makes polling testable by accepting injectable time and hashing
functions:

```php
new TaskWatcher(
    ?callable $sleeper = null,
    ?callable $fingerprinter = null,
)
$watcher->watch(
    string $path,
    callable $callback,
    int $interval = 1,
    ?int $iterations = null,
): void
$watcher->snapshot(string $path): array<string, string>
```

The default fingerprinter uses SHA-256. Snapshots are path-sorted and compare
added, removed, and changed files. A null iteration count means the watcher
runs until the process is interrupted.

Command API
-----------

### `Strukt\CommandRunner`

```php
$runner = new CommandRunner();
$result = $runner->run(
    string $command,
    ?callable $callback = null,
    ?string $input = null,
): CommandResult
```

The no-input path delegates to `strukt/process`, which drains standard output
and standard error concurrently. The input path uses the same three-pipe
protocol and captures both streams before reaping the process. An empty command
throws `InvalidArgumentException`; a failed process setup throws
`RuntimeException`.

### `Strukt\CommandResult`

```php
$result->output(): string
$result->error(): string
$result->exitCode(): int
$result->successful(): bool
$result->toArray(): array{0: string, 1: string}
```

`successful()` is true only for exit code zero. `toArray()` provides the output
and error tuple used by the global `run()` helper.

Performance and safety
----------------------

- Task callbacks are converted to closures and reflected once by `Strukt\Event`
  at registration.
- Task lookup uses an ordered associative map, so execution does not scan the
  complete task list.
- External command output and error streams are drained concurrently through
  `strukt/process` to avoid stderr back-pressure.
- Watch snapshots are sorted and compared by path, avoiding order-dependent
  false positives.
- Process pipes are closed and children are reaped on successful and failed
  command paths.
- Task-file loading is isolated behind `Tasker`, so tests can use temporary
  project directories and injected writers without changing the process cwd.

Development
-----------

Install dependencies and run the Pest suite:

```sh
composer update
composer test
composer validate --no-check-publish
```

The package tests cover task registration and argument validation, default-file
creation, nested tasks, CLI status codes, command output/error/input handling,
and deterministic file watching.

Changelog
---------

Every change to this package is recorded in
[`docs/changelog/`](docs/changelog/README.md), newest first.

License
-------

Strukt Tasker is released under the MIT license.