<?php

namespace App\Config;

enum DiagnosticSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
