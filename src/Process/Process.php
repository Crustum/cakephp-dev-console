<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Process;

/**
 * Value object for a single dev process.
 *
 * Port of `Illuminate\Foundation\DevCommand`.
 *
 * @phpstan-type ProcessArray array{command: string, name: string, color: string|null, source: array{file?: string, line?: int, class?: string, function?: string}, priority: int}
 */
class Process
{
    /**
     * Priority for commands registered by the framework defaults.
     *
     * @var int
     */
    public const PRIORITY_DEFAULT = 0;

    /**
     * Priority for commands registered by vendor packages.
     *
     * @var int
     */
    public const PRIORITY_VENDOR = 1;

    /**
     * Priority for commands registered by the application.
     *
     * @var int
     */
    public const PRIORITY_USERLAND = 2;

    /**
     * Color of the process when output to the console.
     *
     * @var string|null
     */
    protected ?string $color = null;

    /**
     * @param string $command Shell command, e.g. `php bin/cake.php server`.
     * @param array{file?: string, line?: int, class?: string, function?: string} $source debug_backtrace info.
     * @param string|null $name Tab label, e.g. `server`.
     * @param int $priority One of the PRIORITY_* constants.
     */
    public function __construct(
        protected string $command,
        protected array $source,
        protected ?string $name = null,
        protected int $priority = self::PRIORITY_USERLAND,
    ) {
        $this->name ??= self::nameFromCommand($command);
    }

    /**
     * Derive the tab label from a command string.
     *
     * @param string $command Command string.
     * @return string First word of the command.
     */
    public static function nameFromCommand(string $command): string
    {
        $name = strstr($command, ' ', true);

        return $name === false ? $command : $name;
    }

    /**
     * Get the process name.
     *
     * @return string
     */
    public function name(): string
    {
        /** @var string $name */
        $name = $this->name;

        return $name;
    }

    /**
     * Get the process priority.
     *
     * @return int
     */
    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * Set the process color.
     *
     * @param string $color Hex color, e.g. `#93c5fd`.
     * @return $this
     */
    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /**
     * Set the process color to blue.
     *
     * @return $this
     */
    public function blue(): static
    {
        return $this->color(ProcessColor::BLUE->value);
    }

    /**
     * Set the process color to purple.
     *
     * @return $this
     */
    public function purple(): static
    {
        return $this->color(ProcessColor::PURPLE->value);
    }

    /**
     * Set the process color to pink.
     *
     * @return $this
     */
    public function pink(): static
    {
        return $this->color(ProcessColor::PINK->value);
    }

    /**
     * Set the process color to orange.
     *
     * @return $this
     */
    public function orange(): static
    {
        return $this->color(ProcessColor::ORANGE->value);
    }

    /**
     * Set the process color to green.
     *
     * @return $this
     */
    public function green(): static
    {
        return $this->color(ProcessColor::GREEN->value);
    }

    /**
     * Set the process color to yellow.
     *
     * @return $this
     */
    public function yellow(): static
    {
        return $this->color(ProcessColor::YELLOW->value);
    }

    /**
     * Get the process as an array.
     *
     * @return array{command: string, name: string, color: string|null, source: array{file?: string, line?: int, class?: string, function?: string}, priority: int}
     */
    public function toArray(): array
    {
        return [
            'command' => $this->command,
            'name' => $this->name(),
            'color' => $this->color,
            'source' => $this->source,
            'priority' => $this->priority,
        ];
    }
}
