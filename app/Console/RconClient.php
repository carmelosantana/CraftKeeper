<?php

namespace App\Console;

/**
 * The stable interface every caller depends on: hand it a validated
 * RconCommand, get back the server's RconResponse, or a typed
 * App\Console\Exceptions\RconException. App\Console\MinecraftRconClient
 * is the only implementation.
 */
interface RconClient
{
    public function execute(RconCommand $command): RconResponse;
}
