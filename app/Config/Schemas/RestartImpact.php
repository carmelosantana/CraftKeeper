<?php

namespace App\Config\Schemas;

/**
 * What it takes for a change to this field to take effect on a running
 * server — shown to the operator before they approve a proposal (Task
 * 8/9's "restart effect").
 */
enum RestartImpact: string
{
    /** Takes effect immediately; no reload or restart needed. */
    case None = 'none';

    /** Needs a config/plugin reload (e.g. an RCON reload command). */
    case Reload = 'reload';

    /** Only takes effect after a full server restart. */
    case Restart = 'restart';
}
