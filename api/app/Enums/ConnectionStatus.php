<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Pending = 'pending';
    case Syncing = 'syncing';
    case Ok = 'ok';
    case Error = 'error';
    // V15: private Steam profile is its own state, never a silent empty sync.
    case ErrorPrivate = 'error_private';
    // V13: disconnect soft-keeps data under this state.
    case Disconnected = 'disconnected';
}
