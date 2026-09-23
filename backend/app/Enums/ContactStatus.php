<?php

namespace App\Enums;

enum ContactStatus: string
{
    case New = 'new';
    case Active = 'active';
    case Pending = 'pending';
    case Resolved = 'resolved';
}
