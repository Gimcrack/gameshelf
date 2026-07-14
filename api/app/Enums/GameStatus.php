<?php

namespace App\Enums;

enum GameStatus: string
{
    case Unplayed = 'unplayed';
    case Playing = 'playing';
    case Finished = 'finished';
    case Abandoned = 'abandoned';
}
