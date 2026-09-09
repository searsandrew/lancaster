<?php

namespace App\Enums;

enum AnswerMatchMethod: string
{
    case Exact = 'exact';
    case AcceptedPhrase = 'accepted_phrase';
    case Spelling = 'spelling';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Exact match',
            self::AcceptedPhrase => 'Matched an accepted phrase',
            self::Spelling => 'Accepted a close spelling',
            self::None => 'No automatic match',
        };
    }
}
