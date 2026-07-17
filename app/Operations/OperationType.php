<?php

namespace App\Operations;

/**
 * The canonical set of operation types CraftKeeper can propose. Values are
 * fixed by the CraftKeeper V1 plan's Stable Interfaces — every concrete
 * OperationHandler (Tasks 8, 10, 15) declares support for one or more of
 * these via OperationHandler::supports().
 */
enum OperationType: string
{
    case ConfigApply = 'config.apply';
    case ConfigRestore = 'config.restore';
    case PluginInstall = 'plugin.install';
    case PluginUpdate = 'plugin.update';
    case PluginDisable = 'plugin.disable';
    case PluginRemove = 'plugin.remove';
    case PluginRollback = 'plugin.rollback';
    case RconCommand = 'rcon.command';
    case ServerStop = 'server.stop';
}
