<?php

use Strukt\CommandRunner;
use Strukt\Tasker;
use Strukt\TaskRegistry;
use Strukt\TaskWatcher;

it('registers tasks once and executes validated arguments', function (): void {
    $registry = new TaskRegistry();
    $task     = $registry->add('greet', static fn(string $name): string => "Hello {$name}!", 'Say hello.');

    expect($task->name())->toBe('greet')
        ->and($task->description())->toBe('Say hello.')
        ->and($task->callback())->toBeInstanceOf(Closure::class)
        ->and($task->reflection())->toBeInstanceOf(ReflectionFunction::class)
        ->and($task->parameters()[0]['name'])->toBe('name')
        ->and($registry->run('greet', ['Ada']))->toBe('Hello Ada!');

    // expect(fn() => $registry->add('greet', static fn(): void => null))
    //     ->toThrow(LogicException::class);
    expect(fn() => $registry->add('greet', static function (): void {return;}))
        ->toThrow(LogicException::class);

    expect(fn() => $registry->run('greet'))->toThrow(InvalidArgumentException::class);
});

it('creates a default task file and lists built-in and user tasks', function (): void {
    $directory = $this->temporaryDirectory();
    $output    = [];
    $errors    = [];

    try {
        $application = new Tasker(
            $directory,
            outputWriter: static function (string $message) use (&$output): void {
                $output[] = $message;
            },
            errorWriter: static function (string $message) use (&$errors): void {
                $errors[] = $message;
            },
        );

        expect($application->run(['tasker', 'list']))->toBe(0)
            ->and(is_file($directory . DIRECTORY_SEPARATOR . 'tasker.php'))->toBeTrue()
            ->and(implode('', $output))->toContain('version')
            ->and(implode('', $output))->toContain('test')
            ->and($errors)->toBe([]);
    } finally {
        $this->removeDirectory($directory);
    }
});

it('loads a task file, passes CLI arguments, and supports go', function (): void {
    $directory = $this->temporaryDirectory();
    $taskFile  = <<<'PHP'
    <?php

    /** Say hello to one person. */
    task('hello', static function (string $name): void {
        writeln("Hello {$name}!");
    });

    /** Run hello through another task. */
    task('outer', static function (): void {
        go('hello', 'World');
    });
    PHP;
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'tasker.php', $taskFile);

    try {
        $application = new Tasker($directory);
        ob_start();
        $status      = $application->run(['tasker', 'hello', 'Ada']);
        $helloOutput = ob_get_clean();

        ob_start();
        $nestedStatus = $application->run(['tasker', 'outer']);
        $nestedOutput = ob_get_clean();

        expect($status)->toBe(0)
            ->and($helloOutput)->toBe("Hello Ada!" . PHP_EOL)
            ->and($nestedStatus)->toBe(0)
            ->and($nestedOutput)->toBe("Hello World!" . PHP_EOL)
            ->and($application->registry()->names())->toContain('hello', 'outer');
    } finally {
        $this->removeDirectory($directory);
    }
});

it('returns useful status codes for missing commands and bad task arguments', function (): void {
    $directory = $this->temporaryDirectory();
    $errors    = [];

    try {
        $application = new Tasker(
            $directory,
            errorWriter: static function (string $message) use (&$errors): void {
                $errors[] = $message;
            },
        );

        expect($application->run(['tasker', 'missing']))->toBe(127)
            ->and($errors[0])->toContain('missing');

        $errors = [];
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'tasker.php', <<<'PHP'
    <?php
    task('needs-name', static function (string $name): void {});
    PHP);

        expect($application->run(['tasker', 'needs-name']))->toBe(1)
            ->and($errors[0])->toContain('expects at least');
    } finally {
        $this->removeDirectory($directory);
    }
});

it('captures output, errors, exit status, and input', function (): void {
    $runner = new CommandRunner();
    $lines  = [];
    $result = $runner->run(
        'printf "first\\nsecond\\n"; printf "failure\\n" >&2; exit 7',
        static function (string $line) use (&$lines): void {
            $lines[] = trim($line);
        },
    );

    expect($result->output())->toBe("first\nsecond\n")
        ->and($result->error())->toBe("failure\n")
        ->and($result->exitCode())->toBe(7)
        ->and($result->successful())->toBeFalse()
        ->and($lines)->toBe(['first', 'second']);

    $php         = escapeshellarg(PHP_BINARY);
    $script      = escapeshellarg('echo stream_get_contents(STDIN);');
    $inputResult = $runner->run("{$php} -r {$script}", input: 'from stdin');

    expect($inputResult->output())->toBe('from stdin')
        ->and($inputResult->exitCode())->toBe(0);
});

it('reports changed files without hashing the same snapshot twice', function (): void {
    $directory = $this->temporaryDirectory();
    $file      = $directory . DIRECTORY_SEPARATOR . 'watched.txt';
    file_put_contents($file, 'before');
    $calls = [];
    $polls = 0;

    try {
        $watcher = new TaskWatcher(static function (int $seconds) use (&$polls, $file): void {
            ++$polls;
            file_put_contents($file, 'after');
        });

        $watcher->watch($directory, static function (array $paths) use (&$calls): void {
            $calls[] = $paths;
        }, 1, 1);

        expect($polls)->toBe(1)
            ->and($calls)->toHaveCount(1)
            ->and($calls[0])->toContain($file);
    } finally {
        $this->removeDirectory($directory);
    }
});
