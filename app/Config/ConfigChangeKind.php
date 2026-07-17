<?php

namespace App\Config;

enum ConfigChangeKind: string
{
    case Replace = 'replace';
    case Add = 'add';
    case Remove = 'remove';
}
