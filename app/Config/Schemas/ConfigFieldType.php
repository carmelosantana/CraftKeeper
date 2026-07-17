<?php

namespace App\Config\Schemas;

enum ConfigFieldType: string
{
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Number = 'number';
    case String = 'string';
    case Array = 'array';
}
