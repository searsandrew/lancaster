<?php

namespace App\Enums;

enum QuestionAnswerType: string
{
    case FreeText = 'free_text';
    case MultipleChoice = 'multiple_choice';

    public function label(): string
    {
        return match ($this) {
            self::FreeText => 'Free text',
            self::MultipleChoice => 'Multiple choice',
        };
    }
}
