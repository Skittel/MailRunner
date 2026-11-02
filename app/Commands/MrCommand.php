<?php

namespace App\Commands;

use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;
use Throwable;

/**
 * Base for MailRunner CLI commands: argument helpers, error output, JSON mode.
 *
 * Syntax: values always as --option=value, flags as --flag, everything else is a
 * positional argument (CodeIgniter's own parser would swallow the argument after a flag).
 *
 * Exit codes: 0 = success, 1 = configuration error (with code), 2 = unexpected error.
 */
abstract class MrCommand extends BaseCommand
{
    protected $group = 'MailRunner';

    /** @var list<string> */
    private array $parsedPositional = [];

    /** @var array<string, string|true> */
    private array $parsedOptions = [];

    public function run(array $params)
    {
        $this->parseArguments($params);

        try {
            return $this->execute() ?? EXIT_SUCCESS;
        } catch (ConfigException $e) {
            $this->error($e->errorCode, $e->getMessage());

            return 1;
        } catch (InvalidArgumentException $e) {
            $this->error('INVALID_ARGUMENT', $e->getMessage());

            return 1;
        } catch (Throwable $e) {
            $this->error('INTERNAL_ERROR', $e->getMessage());

            return 2;
        }
    }

    abstract protected function execute(): ?int;

    /**
     * Positional argument by index (0-based) or null.
     */
    protected function arg(int $index): ?string
    {
        $positional = $this->positional();

        return isset($positional[$index]) ? (string) $positional[$index] : null;
    }

    protected function requireArg(int $index, string $name): string
    {
        return $this->arg($index) ?? throw new InvalidArgumentException('Missing argument <' . $name . '>. Usage: ' . $this->usage);
    }

    /**
     * @return list<string>
     */
    protected function positional(): array
    {
        return $this->parsedPositional;
    }

    protected function option(string $name): string|true|null
    {
        return $this->parsedOptions[$name] ?? null;
    }

    /**
     * Parses the raw argv after the command name. Falls back to CodeIgniter's
     * $params when the command is invoked programmatically.
     *
     * @param array<int|string, mixed> $params
     */
    private function parseArguments(array $params): void
    {
        $argv  = $_SERVER['argv'] ?? [];
        $start = array_search($this->name, $argv, true);
        if ($start === false) {
            foreach ($params as $key => $value) {
                if (is_int($key)) {
                    $this->parsedPositional[] = (string) $value;
                } elseif (str_contains($key, '=')) { // command('x --opt=value') passes "opt=value" as the key
                    [$name, $v]                 = explode('=', $key, 2); // --opt="a b" arrives as "opt=" + value
                    $this->parsedOptions[$name] = $v !== '' ? trim($v, '"\'') : (string) ($value ?? '');
                } else {
                    $this->parsedOptions[$key] = $value ?? true;
                }
            }

            return;
        }

        $onlyPositional = false;
        foreach (array_slice($argv, $start + 1) as $arg) {
            if (! $onlyPositional && $arg === '--') {
                $onlyPositional = true;
            } elseif (! $onlyPositional && str_starts_with($arg, '--')) {
                [$name, $value]        = array_pad(explode('=', substr($arg, 2), 2), 2, null);
                $this->parsedOptions[$name] = $value ?? true;
            } else {
                $this->parsedPositional[] = $arg;
            }
        }
    }

    protected function optionString(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === true) {
            throw new InvalidArgumentException('Option --' . $name . ' needs a value');
        }

        return $value;
    }

    protected function flag(string $name): bool
    {
        return $this->option($name) !== null;
    }

    protected function context(): ChangeContext
    {
        return ChangeContext::cli($this->flag('force'));
    }

    protected function json(): bool
    {
        return $this->flag('json');
    }

    /**
     * @param mixed $data
     */
    protected function outputJson($data): void
    {
        CLI::write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Prints rows as a table with the given columns.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $columns
     */
    protected function outputTable(array $rows, array $columns): void
    {
        if ($rows === []) {
            CLI::write('(none)');

            return;
        }
        $body = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value  = $row[$column] ?? null;
                $line[] = match (true) {
                    $value === null                            => '-',
                    is_string($value) && str_ends_with($column, '_at') => substr($value, 0, 19),
                    default                                    => (string) $value,
                };
            }
            $body[] = $line;
        }
        CLI::table($body, $columns);
    }

    /**
     * Prints a secret once, clearly marked.
     */
    protected function outputSecret(string $title, array $lines): void
    {
        CLI::newLine();
        CLI::write('  ' . $title . ' (shown only once, store it now):', 'yellow');
        foreach ($lines as $label => $value) {
            CLI::write(sprintf('    %-10s %s', $label . ':', $value), 'green');
        }
        CLI::newLine();
    }

    protected function ok(string $message): void
    {
        CLI::write($message, 'green');
    }

    private function error(string $code, string $message): void
    {
        if ($this->json()) {
            $this->outputJson(['error' => ['code' => $code, 'message' => $message], 'time' => Time::toIso(Time::now())]);

            return;
        }
        CLI::error('ERROR [' . $code . '] ' . $message);
    }
}
