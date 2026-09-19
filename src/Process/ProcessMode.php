<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Process;

/**
 * Display mode for the `dev` command.
 *
 * - TABS: each process in its own tab (default, multiplex TUI).
 * - STREAM: all processes merged into one interactive TUI view.
 * - INLINE: plain merged output, no TUI.
 */
enum ProcessMode: string
{
    case TABS = 'tabs';
    case STREAM = 'stream';
    case INLINE = 'inline';
}
