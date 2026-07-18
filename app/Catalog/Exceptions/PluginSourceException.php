<?php

namespace App\Catalog\Exceptions;

use RuntimeException;

/**
 * The base type of every EXPECTED failure mode a catalog source's
 * transport/normalization layer can raise — timeouts, HTTP errors,
 * oversized responses, malformed bodies, "release not found." Every
 * concrete subclass lives in this namespace; App\Catalog\Sources\
 * AbstractPluginSource::search() catches exactly this type (never a
 * bare \Throwable — see its docblock) so a genuinely unexpected bug
 * still surfaces loudly instead of being silently swallowed as "just
 * another degraded source."
 */
abstract class PluginSourceException extends RuntimeException {}
