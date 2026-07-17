<?php

namespace App\Config\Schemas;

/**
 * How consequential a mistaken edit to this field is — drives the
 * approval flow's risk badge (Task 8/9).
 */
enum ConfigFieldRisk: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
