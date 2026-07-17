<?php

namespace App\Config;

/**
 * Which conventional area of the Minecraft server a discovered file
 * belongs to. Purely a classification by path convention — never derived
 * from parsing the file's content (that belongs to Task 7's format
 * adapters and schema registry).
 */
enum DiscoveredFileCategory: string
{
    case Server = 'server';
    case Paper = 'paper';
    case Geyser = 'geyser';
    case Floodgate = 'floodgate';
    case Plugin = 'plugin';
    case Other = 'other';
}
