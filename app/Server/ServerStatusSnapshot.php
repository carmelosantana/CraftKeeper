<?php

namespace App\Server;

/**
 * Current server health, with each source's availability computed and
 * reported independently (Task 11's ambiguity resolution #5: "An
 * unavailable RCON endpoint marks ONLY RCON-dependent data degraded —
 * file-based logs remain usable independently"). There is deliberately no
 * single combined "overall status" field here: a consumer (Task 12's UI)
 * renders each card from its own source's availability/reason, so an
 * RCON outage can never leak into how the logs card is rendered, or vice
 * versa.
 */
final readonly class ServerStatusSnapshot
{
    public function __construct(
        public RconStatus $rcon,
        public LogStatus $logs,
    ) {}
}
