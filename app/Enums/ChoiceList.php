<?php

namespace App\Enums;

/** The admin-editable lists (SPEC §3.4). */
enum ChoiceList: string
{
    case Os = 'os';
    case BackupLocation = 'backup_location';
}
