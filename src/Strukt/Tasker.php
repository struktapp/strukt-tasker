<?php
namespace Strukt;

use Strukt\Fs\Fs;

/** Loads a task file and executes one task from a command-line argument list. */
final class Tasker
{
    /** Current rewrite version displayed by the built-in version task. */
    public const VERSION = '3.0.0';

    /** Project directory used to resolve the task file. */
    private string $directory;

    /** Absolute task-file path. */
    private string $taskFile;

    /** Version string displayed by the CLI. */
    private string $version;

    /** @var callable(string): void Standard-output writer. */
    private $outputWriter;

    /** @var callable(string): void Standard-error writer. */
    private $errorWriter;

    /** Registry populated for the current application invocation. */
    private TaskRegistry $registry;

    /**
     * Creates a tasker application.
     *
     * @param string|null $directory Project directory, defaulting to cwd.
     * @param string $taskFile Relative or absolute task-file path.
     * @param string|null $version Version label for the built-in task.
     * @param callable|null $outputWriter Writer for normal CLI output.
     * @param callable|null $errorWriter Writer for diagnostic output.
     */
    public function __construct(
        ?string $directory = null,
        string $taskFile = 'tasker.php',
        ?string $version = null,
        ? callable $outputWriter = null,
        ? callable $errorWriter = null,
    ) {
        $this->directory    = Fs::ds($directory ?? (getcwd() ?: '.'));
        $this->taskFile     = $this->resolveTaskFile($taskFile);
        $this->version      = $version ?? self::VERSION;
        $this->outputWriter = $outputWriter ?? static function (string $message): void {
            if (defined('STDOUT')) {
                fwrite(STDOUT, $message);
                return;
            }

            echo $message;
        };
        $this->errorWriter = $errorWriter ?? static function (string $message): void {
            if (defined('STDERR')) {
                fwrite(STDERR, $message);
                return;
            }

            echo $message;
        };
        $this->registry = new TaskRegistry();
    }

    /**
     * Runs the CLI argument list.
     *
     * The first argument is the executable name, the second is the task, and
     * the remaining values are passed positionally to that task. `--help`,
     * `-h`, `--version`, and `-V` are handled without a task-file command.
     *
     * @param array<int, string> $argv Process argument vector.
     * @return int Process exit code.
     */
    public function run(array $argv): int
    {
        $this->registry = new TaskRegistry();
        $this->registerBuiltIns();
        $previous = TaskRegistry::activate($this->registry);

        try {
            $this->ensureTaskFile();
            require $this->taskFile;

            $command   = $argv[1] ?? null;
            $arguments = array_slice($argv, 2);

            if ($command === null || $command === '') {
                $this->writeError("No task indicated. Run `tasker list` to see available tasks.\n");
                return 64;
            }

            if (in_array($command, ['--help', '-h'], true)) {
                $this->write($this->helpText());
                return 0;
            }

            if (in_array($command, ['--version', '-V'], true)) {
                $this->write(sprintf("Tasker v%s\n", $this->version));
                return 0;
            }

            if (! $this->registry->has($command)) {
                $this->writeError(sprintf("Tasker could not find task [%s].\n", $command));
                return 127;
            }

            $this->registry->run($command, $arguments);

            return 0;
        } catch (\Throwable $exception) {
            $this->writeError(sprintf("Tasker error: %s\n", $exception->getMessage()));

            return 1;
        } finally {
            TaskRegistry::restore($previous);
        }
    }

    /**
     * Returns the registry from the latest invocation.
     *
     * @return TaskRegistry Task registry.
     */
    public function registry(): TaskRegistry
    {
        return $this->registry;
    }

    /**
     * Returns the resolved task-file path.
     *
     * @return string Task-file path.
     */
    public function taskFile(): string
    {
        return $this->taskFile;
    }

    /**
     * Returns the formatted task list used by list and help.
     *
     * @return string Human-readable task list.
     */
    public function taskList(): string
    {
        $names = $this->registry->names();
        $width = 15;
        foreach ($names as $name) {
            $width = max($width, strlen($name));
        }

        $lines = [];
        foreach ($this->registry->all() as $task) {
            $description = $task->description();
            $lines[]     = sprintf(
                ' %s%s',
                str_pad($task->name(), $width + 1),
                $description,
            );
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Builds the default task-file contents.
     *
     * @return string PHP source for a newly initialized task file.
     */
    public static function defaultTaskFile(): string
    {
        return <<<'PHP'
    <?php

    /**
     * Sample task.
     */
    task('test', static function (): void {
        writeln('Hello world');
    });
    PHP;
    }

    /**
     * Registers built-in version, list, and help tasks.
     *
     * @return void
     */
    private function registerBuiltIns(): void
    {
        $this->registry->add('version', function (): void {
            $this->write(sprintf("Tasker v%s\n", $this->version));
        }, 'Show the Tasker version.');

        $this->registry->add('list', function (): void {
            $this->write($this->taskList() . PHP_EOL . PHP_EOL);
        }, 'List available tasks.');

        $this->registry->add('help', function (): void {
            $this->write($this->helpText());
        }, 'Show usage and available tasks.');
    }

    /**
     * Ensures that the configured task file exists.
     *
     * @return void
     * @throws \RuntimeException When the file cannot be created.
     */
    private function ensureTaskFile(): void
    {
        if (Fs::isFile($this->taskFile)) {
            return;
        }

        if (! Fs::touchWrite($this->taskFile, self::defaultTaskFile())) {
            throw new \RuntimeException(sprintf('Unable to create task file [%s].', $this->taskFile));
        }
    }

    /**
     * Resolves a task-file argument against the project directory.
     *
     * @param string $taskFile Relative or absolute path.
     * @return string Normalized absolute or project-relative path.
     */
    private function resolveTaskFile(string $taskFile): string
    {
        if ($taskFile === '') {
            throw new \InvalidArgumentException('The task file path cannot be empty.');
        }

        $isAbsolute = str_starts_with($taskFile, DIRECTORY_SEPARATOR)
        || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $taskFile);

        return Fs::ds($isAbsolute
                ? $taskFile
                : $this->directory . DIRECTORY_SEPARATOR . ltrim($taskFile, '/\\'));
    }

    /**
     * Builds the top-level help message.
     *
     * @return string Help text.
     */
    private function helpText(): string
    {
        return sprintf(
            "Tasker\n\nUsage: tasker <task> [arguments...]\n\n%s\n\n",
            $this->taskList(),
        );
    }

    /**
     * Writes normal output.
     *
     * @param string $message Output text.
     * @return void
     */
    private function write(string $message): void
    {
        ($this->outputWriter)($message);
    }

    /**
     * Writes diagnostic output.
     *
     * @param string $message Error text.
     * @return void
     */
    private function writeError(string $message): void
    {
        ($this->errorWriter)($message);
    }
}
