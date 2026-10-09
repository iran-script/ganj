<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case OrganizationAdmin = 'ORGANIZATION_ADMIN';
    case GameMaster = 'GAMEMASTER';
    case Staff = 'STAFF';
}
