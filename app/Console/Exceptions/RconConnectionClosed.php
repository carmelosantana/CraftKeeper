<?php

namespace App\Console\Exceptions;

use RuntimeException;

/**
 * The underlying transport reported EOF (or a failed write) before a full
 * packet could be read/sent — distinct from RconTimeout, which means "no
 * terminal signal arrived within budget". This is "the connection is
 * definitely gone", the exact signal App\Operations\Handlers\
 * ServerStopHandler's documented restart-policy poll (Task 11) needs to
 * detect "the server has gone down" versus "the server is just slow".
 */
class RconConnectionClosed extends RuntimeException implements RconException {}
