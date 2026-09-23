<?php

namespace App\Enums;

enum UserRole: string
{
    case Agent = 'agent';
    case TeamLead = 'team_lead';
}
