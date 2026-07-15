<?php

namespace App\Enums;

enum DeckStatus: string
{
    case Unknown = 'unknown';
    case Unsupported = 'unsupported';
    case Playable = 'playable';
    case Verified = 'verified';
}
