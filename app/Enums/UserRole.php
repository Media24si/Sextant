<?php

namespace App\Enums;

/**
 * The two roles (GOAL "Users"): admins change everything, readers only view.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Reader = 'reader';
}
