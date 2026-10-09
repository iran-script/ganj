<?php

namespace App\Enums;

enum OrganizationMemberStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Left = 'LEFT';
    case Removed = 'REMOVED';
}
