<?php

namespace App\Enums;

enum QuizScoringMode: string
{
    case PerAnswer = 'per_answer';
    case Summary = 'summary';

    public function label(): string
    {
        return match ($this) {
            self::PerAnswer => 'Per-answer scoring',
            self::Summary => 'Summary scoring',
        };
    }
}
