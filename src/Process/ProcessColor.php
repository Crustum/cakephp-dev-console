<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Process;

/**
 * Default color palette for dev processes.
 *
 * Colors are hex strings passed through to the process runner
 * (multiplex / concurrently) for terminal ANSI coloring.
 */
enum ProcessColor: string
{
    case BLUE = '#93c5fd';
    case PURPLE = '#c4b5fd';
    case PINK = '#fb7185';
    case ORANGE = '#fdba74';
    case GREEN = '#86efac';
    case YELLOW = '#fcd34d';
}
