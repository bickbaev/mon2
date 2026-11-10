<?php

namespace App\Enums;

enum ReportKind: string
{
    case Simple = 'simple';
    case List = 'list';
    case Complex = 'complex';
}
