<?php

namespace App\Enums;

enum LeaderboardDisplayMode: string
{
    case Leaderboard = 'leaderboard';
    case QrCode = 'qr_code';
    case Advertisement = 'advertisement';
}
