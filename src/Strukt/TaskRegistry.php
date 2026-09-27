<?php
namespace Strukt;

/** Stores tasks in registration order and provides the active task context. */
final class TaskRegistry implements \Countable, \IteratorAggregate
{
    /** @var array<string, Task> Tasks keyed by their command-line names. */
    private array $tasks = [];

    /** Registry used by task-file helper functions during an application run. */
    private static ?self $active = null;

    /**
     * Registers a task once.
     *
     * @param string $name Task name.
     * @param callable $callback Task callback.
     * @param string|null $description Optional description override.
     * @return Task Registered task.
     * @throws \InvalidArgumentException When the name or callback is invalid.
     * @throws \LogicException When the name is already registered.
     */
    public function add(string $name, callable $callback, ?string $description = null): Task
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Task names must not be empty.');
        }

        if (isset($this->tasks[$name])) {
            throw new \LogicException(sprintf('Task [%s] is already registered.', $name));
        }

        return $this->tasks[$name] = new Task($name, $callback, $description);
    }

    /**
     * Reads a task by name.
     *
     * @param string $name Task name.
     * @return Task|null Task, or null when absent.
     */
    public function get(string $name): ?Task
    {
        return $this->tasks[$name] ?? null;
    }

    /**
     * Checks whether a task exists.
     *
     * @param string $name Task name.
     * @return bool True when registered.
     */
    public function has(string $name): bool
    {
        return isset($this->tasks[$name]);
    }

    /**
     * Returns task names in registration order.
     *
     * @return list<string> Registered names.
     */
    public function names(): array
    {
        return array_keys($this->tasks);
    }

    /**
     * Returns tasks in registration order.
     *
     * @return list<Task> Registered tasks.
     */
    public function all(): array
    {
        return array_values($this->tasks);
    }

    /**
     * Executes a named task.
     *
     * @param string $name Task name.
     * @param array<int|string, mixed> $arguments Task arguments.
     * @return mixed Task result.
     * @throws \InvalidArgumentException When the task is unknown.
     */
    public function run(string $name, array $arguments = []): mixed
    {
        $task = $this->get($name);
        if ($task === null) {
            throw new \InvalidArgumentException(sprintf('Task [%s] was not registered.', $name));
        }

        return $task->invoke($arguments);
    }

    /**
     * Removes every task from this registry.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->tasks = [];
    }

    /**
     * Makes a registry active for task-file helpers.
     *
     * @param self $registry Registry to activate.
     * @return self|null Previously active registry.
     */
    public static function activate(self $registry): ?self
    {
        $previous     = self::$active;
        self::$active = $registry;

        return $previous;
    }

    /**
     * Restores a previously active registry.
     *
     * @param self|null $registry Registry to restore, or null to clear it.
     * @return void
     */
    public static function restore(?self $registry): void
    {
        self::$active = $registry;
    }

    /**
     * Returns the active task registry.
     *
     * @return self Active registry.
     * @throws \LogicException When no tasker application is running.
     */
    public static function current(): self
    {
        if (self::$active === null) {
            throw new \LogicException('Task helpers can only be used while a Tasker application is active.');
        }

        return self::$active;
    }

    /**
     * Returns the active registry without throwing.
     *
     * @return self|null Active registry, or null when inactive.
     */
    public static function active(): ?self
    {
        return self::$active;
    }

    /**
     * Returns the number of registered tasks.
     *
     * @return int Task count.
     */
    public function count(): int
    {
        return count($this->tasks);
    }

    /**
     * Iterates over tasks in registration order.
     *
     * @return \Traversable<string, Task> Task iterator.
     */
    public function getIterator(): \Traversable
    {
        yield from $this->tasks;
    }
}
