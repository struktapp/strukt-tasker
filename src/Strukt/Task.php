<?php
namespace Strukt;

use Closure;

/**
 * Describes one named task and invokes it with validated arguments.
 *
 * The callable is owned by the event package: a Task wraps a Strukt\Event,
 * which performs the closure conversion, retains the reflection metadata, and
 * applies the invocation arguments.
 */
final class Task
{
    /** Task name shown on the command line. */
    private string $name;

    /** Deferred task callback owned by the event package. */
    private Event $event;

    /** Optional explicit description, primarily used by built-in tasks. */
    private ?string $explicitDescription;

    /**
     * Creates a task description.
     *
     * @param string $name Command-line task name.
     * @param callable $callback Function to execute.
     * @param string|null $description Optional description override.
     * @throws \InvalidArgumentException When the task name is empty.
     */
    public function __construct(string $name, callable $callback, ?string $description = null)
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Task names must not be empty.');
        }

        $this->name                = $name;
        $this->event               = new Event($callback);
        $this->explicitDescription = $description === null ? null : trim($description);
    }

    /**
     * Returns the task name.
     *
     * @return string Task name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Returns the retained task callback.
     *
     * @return Closure Task callback.
     */
    public function callback(): Closure
    {
        return $this->event->ref()->getClosure();
    }

    /**
     * Returns the callback reflection object.
     *
     * @return \ReflectionFunction Task reflection.
     */
    public function reflection(): \ReflectionFunction
    {
        return $this->event->ref();
    }

    /**
     * Returns a concise description from an explicit value or PHPDoc.
     *
     * @return string Human-readable task description.
     */
    public function description(): string
    {
        if ($this->explicitDescription !== null) {
            return $this->explicitDescription;
        }

        $doc = $this->event->ref()->getDocComment();
        if ($doc === false) {
            return '';
        }

        $doc   = preg_replace('/^\/\*\*|\*\/$/', '', $doc) ?? $doc;
        $lines = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\* ?/', '', trim($line)));
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '@')) {
                break;
            }
            $lines[] = $line;
        }

        return trim(implode(' ', $lines));
    }

    /**
     * Returns reflected parameter metadata for help and validation.
     *
     * @return list<array{name: string, type: string, required: bool, variadic: bool}> Parameter metadata.
     */
    public function parameters(): array
    {
        $parameters = [];
        foreach ($this->event->ref()->getParameters() as $parameter) {
            $type         = $parameter->getType();
            $parameters[] = [
                'name'     => $parameter->getName(),
                'type'     => $type === null ? 'mixed' : (string) $type,
                'required' => ! $parameter->isOptional() && ! $parameter->isVariadic(),
                'variadic' => $parameter->isVariadic(),
            ];
        }

        return $parameters;
    }

    /**
     * Invokes the task with positional or named arguments.
     *
     * Positional argument counts are validated against the callback signature,
     * then the event package applies the arguments and executes the callback.
     *
     * @param array<int|string, mixed> $arguments Arguments to pass to the task.
     * @return mixed Task result.
     * @throws \InvalidArgumentException When positional argument count is invalid.
     */
    public function invoke(array $arguments = []): mixed
    {
        if (array_is_list($arguments)) {
            $reflection = $this->event->ref();
            $parameters = $reflection->getParameters();
            $required   = $reflection->getNumberOfRequiredParameters();
            $maximum    = $reflection->isVariadic()
                ? null
                : count($parameters);

            if (count($arguments) < $required) {
                throw new \InvalidArgumentException(sprintf(
                    'Task [%s] expects at least %d argument(s), %d given.',
                    $this->name,
                    $required,
                    count($arguments),
                ));
            }

            if ($maximum !== null && count($arguments) > $maximum) {
                throw new \InvalidArgumentException(sprintf(
                    'Task [%s] accepts at most %d argument(s), %d given.',
                    $this->name,
                    $maximum,
                    count($arguments),
                ));
            }
        }

        return $this->event->applyArgs($arguments)->exec();
    }

    /**
     * Invokes the task as a callable.
     *
     * @param mixed ...$arguments Task arguments.
     * @return mixed Task result.
     */
    public function __invoke(mixed ...$arguments): mixed
    {
        return $this->invoke($arguments);
    }
}
