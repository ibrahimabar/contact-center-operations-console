<?php

namespace App\Enums;

enum InteractionType: string
{
    case Phone = 'phone';
    case Email = 'email';
    case Sms = 'sms';
    case Chat = 'chat';
}
