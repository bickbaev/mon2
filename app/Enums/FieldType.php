<?php

namespace App\Enums;

enum FieldType: string
{
    case Number = 'number';
    case Text = 'text';
    case Radio = 'radio';
    case Multiselect = 'multiselect';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Date = 'date';
    case Time = 'time';
    case Datetime = 'datetime';
    case File = 'file';
}
