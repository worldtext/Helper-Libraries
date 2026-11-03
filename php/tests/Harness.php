<?php

declare(strict_types=1);

namespace WorldText\Tests;

/**
 * A deliberately tiny assertion harness: PHPUnit is not available and this
 * library takes no dependencies.
 *
 * Each test is a closure in a {@see Suite}; a failing assertion throws a
 * {@see TestFailure}, which the suite records and reports. The process exits
 * non-zero if anything failed or errored.
 */
final class TestFailure extends \RuntimeException
{
}

/**
 * The assertions available to tests. Static, so a test reads like a checklist.
 */
final class Assert
{
    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    public static function same(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected !== $actual) {
            throw new TestFailure(sprintf(
                '%s: expected %s, got %s',
                $what,
                self::render($expected),
                self::render($actual),
            ));
        }
    }

    public static function true(bool $condition, string $what): void
    {
        if (!$condition) {
            throw new TestFailure($what . ': expected true');
        }
    }

    public static function false(bool $condition, string $what): void
    {
        if ($condition) {
            throw new TestFailure($what . ': expected false');
        }
    }

    public static function notNull(mixed $value, string $what): void
    {
        if ($value === null) {
            throw new TestFailure($what . ': expected a value, got null');
        }
    }

    public static function notContains(string $needle, string $haystack, string $what): void
    {
        if (str_contains($haystack, $needle)) {
            throw new TestFailure(sprintf(
                '%s: %s unexpectedly contained %s',
                $what,
                self::render($haystack),
                self::render($needle),
            ));
        }
    }

    public static function contains(string $needle, string $haystack, string $what): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new TestFailure(sprintf(
                '%s: %s did not contain %s',
                $what,
                self::render($haystack),
                self::render($needle),
            ));
        }
    }

    /**
     * Assert that `$call` throws exactly `$class`, and return the exception.
     *
     * @template T of \Throwable
     *
     * @param class-string<T> $class
     * @param callable(): mixed $call
     *
     * @return T
     */
    public static function throws(string $class, callable $call, string $what): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $exc) {
            if (!$exc instanceof $class) {
                throw new TestFailure(sprintf(
                    '%s: expected %s, got %s (%s)',
                    $what,
                    $class,
                    $exc::class,
                    $exc->getMessage(),
                ));
            }

            return $exc;
        }

        throw new TestFailure(sprintf('%s: expected %s, nothing was thrown', $what, $class));
    }

    /**
     * A readable form of a value for a failure message.
     */
    private static function render(mixed $value): string
    {
        return match (true) {
            is_string($value) => "'" . $value . "'",
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => var_export($value, true),
        };
    }

    private function __construct()
    {
    }
}

/**
 * Runs test closures and reports a summary.
 */
final class Suite
{
    /** @var list<array{string, \Closure(): void}> */
    private array $tests = [];

    /** @var list<string> */
    private array $failures = [];

    private int $passed = 0;

    public function __construct(private readonly string $name)
    {
    }

    /**
     * Register a test. The closure throws on failure; returning quietly passes.
     */
    public function test(string $description, \Closure $test): void
    {
        $this->tests[] = [$description, $test];
    }

    /**
     * Run every test, printing one line each.
     *
     * @return bool True when all passed.
     */
    public function run(): bool
    {
        printf("\n%s (%d tests)\n", $this->name, count($this->tests));

        foreach ($this->tests as [$description, $test]) {
            try {
                $test();
                $this->passed++;
                printf("  ok    %s\n", $description);
            } catch (TestFailure $failure) {
                $this->failures[] = sprintf('%s :: %s', $description, $failure->getMessage());
                printf("  FAIL  %s\n        %s\n", $description, $failure->getMessage());
            } catch (\Throwable $error) {
                $this->failures[] = sprintf(
                    '%s :: unexpected %s: %s',
                    $description,
                    $error::class,
                    $error->getMessage(),
                );
                printf("  ERROR %s\n        %s: %s\n", $description, $error::class, $error->getMessage());
            }
        }

        return $this->failures === [];
    }

    /**
     * The number of tests that passed.
     */
    public function passedCount(): int
    {
        return $this->passed;
    }

    /**
     * @return list<string>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}