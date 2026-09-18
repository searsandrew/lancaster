<?php

use App\Enums\QuizScoringMode;
use App\Enums\AnswerMatchMethod;
use App\Enums\QuestionAnswerType;
use App\Jobs\SubscribeParticipantToEmailList;
use App\Models\Participant;
use App\Models\Question;
use App\Models\QuizAnswer;
use App\Models\QuizEntry;
use App\Models\Show;
use App\Services\QuizAnswerMatcher;
use App\Services\QuizProgression;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.auth')] #[Title('Join the quiz')] class extends Component
{
    public ?Show $show = null;
    public string $firstName = '';
    public string $lastName = '';
    public string $email = '';
    public string $phoneNumber = '';
    public bool $marketingOptIn = true;
    public bool $registered = false;
    public bool $recovering = false;
    public string $recoveryCode = '';
    public string $submittedAnswer = '';
    #[Locked]
    public ?bool $lastAnswerCorrect = null;

    public function mount(): void
    {
        $this->show = $this->currentShow();

        if ($this->show) {
            $participantId = session($this->sessionKey($this->show));
            $this->registered = $participantId && $this->show->participants()->whereKey($participantId)->exists();
        }
    }

    public function register(): void
    {
        $show = $this->currentShow();

        if (! $show) {
            $this->show = null;
            $this->addError('show', __('Registration is not currently available.'));

            return;
        }

        $this->show = $show;
        $this->firstName = trim($this->firstName);
        $this->lastName = trim($this->lastName);
        $this->email = mb_strtolower(trim($this->email));
        $this->phoneNumber = trim($this->phoneNumber);

        $validated = $this->validate([
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email:rfc',
                'max:255',
                Rule::unique('participants', 'email')->where(
                    fn (Builder $query): Builder => $query->where('show_id', $show->id),
                ),
            ],
            'phoneNumber' => ['nullable', 'string', 'max:50'],
            'marketingOptIn' => ['boolean'],
        ]);

        $participant = $show->participants()->create([
            'first_name' => $validated['firstName'],
            'last_name' => $validated['lastName'],
            'email' => $validated['email'],
            'phone_number' => $validated['phoneNumber'] !== '' ? $validated['phoneNumber'] : null,
            'marketing_opt_in' => $validated['marketingOptIn'],
        ]);
        $participant->refreshRecoveryCode();

        if ($participant->marketing_opt_in && $show->quiz?->customer?->hasEmailMarketingConnection()) {
            SubscribeParticipantToEmailList::dispatch($participant);
        }

        session()->put($this->sessionKey($show), $participant->id);
        $this->registered = true;
    }

    public function recover(): void
    {
        $show = $this->currentShow();

        if (! $show) {
            $this->addError('recoveryCode', __('Quiz recovery is not currently available.'));
            return;
        }

        $validated = $this->validate(['recoveryCode' => ['required', 'digits:6']]);
        $rateLimitKey = 'quiz-recovery:'.request()->ip().':'.$show->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $this->addError('recoveryCode', __('Too many recovery attempts. Please wait a minute and try again.'));
            return;
        }

        RateLimiter::hit($rateLimitKey, 60);
        $participant = $show->participants()->where('recovery_code', $validated['recoveryCode'])->first();

        if (! $participant) {
            $this->addError('recoveryCode', __('That recovery code was not found.'));
            return;
        }

        session()->put($this->sessionKey($show), $participant->id);
        $this->registered = true;
        $this->recovering = false;
    }

    public function refreshQuiz(QuizProgression $progression): void
    {
        $entry = $this->contestant?->quizEntry;

        if (! $entry || $entry->completed_at || $entry->quiz->scoring_mode !== QuizScoringMode::QuestionAnswer) {
            return;
        }

        $answer = $entry->answers->firstWhere('position', $entry->current_question_position);

        if ($entry->current_question_position === null || ($answer && ! $answer->canRetry($entry->quiz))) {
            $progression->advance($entry);
            unset($this->contestant);
        }
    }

    public function submitAnswer(QuizAnswerMatcher $answerMatcher, QuizProgression $progression, int $questionId, int $attemptNumber = 1): void
    {
        $participant = $this->contestant;
        $entry = $participant?->quizEntry;
        $question = $entry?->quiz->questions->firstWhere('position', $entry->current_question_position);

        abort_unless($participant && $entry && $entry->quiz->scoring_mode === QuizScoringMode::QuestionAnswer
            && $question && $entry->question_released_at && ! $entry->completed_at, 404);

        abort_unless($question->id === $questionId, 409);

        $canonicalQuestion = Question::query()->whereBelongsTo($entry->quiz)->findOrFail($question->id);
        abort_unless($entry->assignedQuestions()->contains('id', $canonicalQuestion->id), 404);
        $validated = $this->validate([
            'submittedAnswer' => [
                'required',
                'string',
                'max:1000',
                Rule::when(
                    $canonicalQuestion->answer_type === QuestionAnswerType::MultipleChoice,
                    Rule::in($canonicalQuestion->answer_options ?? []),
                ),
            ],
        ]);
        $answerText = trim($validated['submittedAnswer']);
        $matchMethod = $answerMatcher->match($canonicalQuestion, $answerText);
        $elapsedMs = max(1, (int) $entry->question_released_at->diffInMilliseconds(now()));

        DB::transaction(function () use ($entry, $canonicalQuestion, $answerText, $matchMethod, $elapsedMs, $attemptNumber, $progression): void {
            $lockedEntry = $entry->newQuery()->lockForUpdate()->findOrFail($entry->id);
            abort_unless(! $lockedEntry->completed_at && $lockedEntry->question_released_at
                && $lockedEntry->current_question_position === $canonicalQuestion->position, 409);
            $previousAnswer = $lockedEntry->answers()->where('position', $canonicalQuestion->position)->first();
            abort_unless($attemptNumber === ($previousAnswer?->attempt_count ?? 0) + 1, 409);
            abort_if($previousAnswer && ! $previousAnswer->canRetry($lockedEntry->quiz), 409);

            $answer = $previousAnswer ?? new QuizAnswer;
            $answer->fill([
                'quiz_entry_id' => $lockedEntry->id,
                'question_id' => $canonicalQuestion->id,
                'question_prompt' => $canonicalQuestion->prompt,
                'submitted_answer' => $answerText,
                'position' => $canonicalQuestion->position,
                'is_correct' => $matchMethod !== AnswerMatchMethod::None,
                'automatic_match_method' => $matchMethod,
                'elapsed_ms' => $elapsedMs,
                'submitted_at' => now(),
                'attempt_count' => $attemptNumber,
            ])->save();

            $progression->advance($lockedEntry);
        }, attempts: 5);

        $this->lastAnswerCorrect = $canonicalQuestion->answer_type === QuestionAnswerType::MultipleChoice && $entry->quiz->show_answer_feedback
            ? $matchMethod !== AnswerMatchMethod::None : null;
        $this->submittedAnswer = '';
        unset($this->contestant);
    }

    #[Computed]
    public function contestant(): ?Participant
    {
        if (! $this->show || ! $this->registered) {
            return null;
        }

        $participantId = session($this->sessionKey($this->show));

        return $this->show->participants()
            ->with([
                'quizEntry.quiz.questions' => fn ($query) => $query->select('id', 'quiz_id', 'prompt', 'image_path', 'answer_type', 'answer_options', 'position'),
                'quizEntry.answers',
            ])
            ->find($participantId);
    }

    /** @return Collection<int, QuizEntry> */
    #[Computed]
    public function topEntries(): Collection
    {
        if (! $this->show?->quiz || ! $this->show->isActiveAt()) {
            return new Collection;
        }

        return $this->show->quiz->entries()
            ->with('participant:id,first_name,last_name')
            ->ranked()
            ->limit(3)
            ->get();
    }

    private function currentShow(): ?Show
    {
        $activeShows = Show::query()->activeAt()->with('quiz.customer')->limit(2)->get();

        return $activeShows->count() === 1 ? $activeShows->first() : null;
    }

    private function sessionKey(Show $show): string
    {
        return "quiz_participant_{$show->id}";
    }

};
?>

<div class="flex flex-col gap-6">
    @if ($registered && $show?->quiz?->scoring_mode === QuizScoringMode::QuestionAnswer)
        <div wire:poll.1s="refreshQuiz" class="space-y-6 text-center">
            @php($contestant = $this->contestant)
            <div>
                <flux:heading size="xl">{{ __('Hi, :name!', ['name' => $contestant?->first_name ?? $firstName]) }}</flux:heading>
                <flux:text>{{ $show->name }}</flux:text>
            </div>

            @if ($lastAnswerCorrect !== null)
                <flux:callout :variant="$lastAnswerCorrect ? 'success' : 'warning'">
                    <flux:callout.heading>{{ __('Your last answer') }}</flux:callout.heading>
                    <flux:callout.text>{{ $lastAnswerCorrect ? __('Correct answer!') : __('Incorrect answer.') }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($contestant?->quizEntry?->completed_at)
                <flux:callout variant="success" icon="check-circle">{{ __('Quiz complete! Your result is on the leaderboard.') }}</flux:callout>
            @elseif ($contestant?->quizEntry?->current_question_position)
                @php($question = $contestant->quizEntry->quiz->questions->firstWhere('position', $contestant->quizEntry->current_question_position))
                @php($answer = $contestant->quizEntry->answers->firstWhere('position', $contestant->quizEntry->current_question_position))
                @php($assignedQuestions = $contestant->quizEntry->assignedQuestions())
                @php($currentQuestionNumber = $question ? $assignedQuestions->search(fn ($assignedQuestion) => $assignedQuestion->is($question)) + 1 : null)
                @php($canRetry = $answer?->canRetry($contestant->quizEntry->quiz) ?? false)
                @if ($lastAnswerCorrect === null && $answer && $question?->answer_type === QuestionAnswerType::MultipleChoice && $contestant->quizEntry->quiz->show_answer_feedback)
                    <flux:callout :variant="$answer->is_correct ? 'success' : 'warning'">{{ $answer->is_correct ? __('Correct answer!') : __('Incorrect answer.') }}</flux:callout>
                @endif
                @if ($answer && ! $canRetry)
                    <flux:callout icon="clock">{{ __('Answer received. Loading the next question…') }}</flux:callout>
                @elseif ($question)
                    <flux:card class="space-y-5 text-left">
                        <flux:text class="text-xs font-semibold uppercase tracking-widest">{{ __('Question :current of :total', ['current' => $currentQuestionNumber, 'total' => $assignedQuestions->count()]) }}</flux:text>
                        <flux:heading size="xl">{{ $question->prompt }}</flux:heading>
                        @if ($question->image_path)
                            <img src="{{ Storage::disk('public')->url($question->image_path) }}" alt="{{ __('Question reference image') }}" class="max-h-96 w-full rounded-xl border border-zinc-200 object-contain dark:border-white/10" />
                        @endif
                        @if ($canRetry)
                            <flux:callout variant="warning">{{ __('Try again. Extra attempts remaining: :count.', ['count' => $contestant->quizEntry->quiz->second_chance_attempts - $answer->attempt_count + 1]) }}</flux:callout>
                        @endif
                        <form wire:submit="submitAnswer({{ $question->id }}, {{ ($answer?->attempt_count ?? 0) + 1 }})" class="space-y-4" wire:key="answer-form-{{ $question->id }}-{{ $answer?->attempt_count ?? 0 }}">
                            @if ($question->answer_type === QuestionAnswerType::MultipleChoice)
                                <flux:radio.group wire:model="submittedAnswer" :label="__('Choose your answer')" variant="cards" class="grid gap-3">
                                    @foreach ($question->answer_options ?? [] as $option)
                                        <flux:radio :value="$option" :label="$option" wire:key="choice-{{ $question->id }}-{{ md5($option) }}" />
                                    @endforeach
                                </flux:radio.group>
                            @else
                                <flux:textarea wire:model="submittedAnswer" :label="__('Your answer')" rows="3" maxlength="1000" required autofocus />
                            @endif
                            <flux:error name="submittedAnswer" />
                            <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled" wire:target="submitAnswer">{{ __('Submit answer') }}</flux:button>
                        </form>
                    </flux:card>
                @endif
            @else
                <flux:callout icon="clock">{{ $contestant?->quizEntry ? __('Waiting for the next question…') : __('Leave this window open and come over to our booth to start your quiz.') }}</flux:callout>
            @endif
        </div>
    @elseif ($registered)
        <div class="space-y-6 text-center">
            <flux:heading size="xl">{{ __('You’re in, :name!', ['name' => $firstName]) }}</flux:heading>
            <flux:text>{{ __('Leave this window open and come over to our booth to start your quiz.') }}</flux:text>
            <flux:callout variant="success" icon="check-circle">
                {{ __('You’re registered for :show.', ['show' => $show->name]) }}
            </flux:callout>
        </div>
    @elseif ($show && $recovering)
        <div class="space-y-4">
            <flux:heading size="xl">{{ __('Recover your quiz') }}</flux:heading>
            <form wire:submit="recover" class="space-y-4">
                <flux:input wire:model="recoveryCode" inputmode="numeric" maxlength="6" :label="__('Six-digit recovery code')" required autofocus />
                <flux:button type="submit" variant="primary" class="w-full">{{ __('Recover quiz') }}</flux:button>
                <flux:button type="button" variant="ghost" class="w-full" wire:click="$set('recovering', false)">{{ __('Back to registration') }}</flux:button>
            </form>
        </div>
    @elseif ($show)
        <div class="space-y-2 text-center">
            <flux:heading size="xl">{{ __('Join the quiz') }}</flux:heading>
            <flux:text>{{ $show->name }}</flux:text>
        </div>

        @if ($show->quiz?->registration_image_path || $show->quiz?->registration_message)
            <div class="space-y-4">
                @if ($show->quiz?->registration_image_path)
                    <img
                        src="{{ Storage::disk('public')->url($show->quiz->registration_image_path) }}"
                        alt="{{ __('Quiz registration information') }}"
                        class="mx-auto max-h-64 w-full rounded-xl object-contain"
                    />
                @endif

                @if ($show->quiz?->registration_message)
                    <flux:callout>
                        <div class="whitespace-pre-line text-sm">{{ $show->quiz->registration_message }}</div>
                    </flux:callout>
                @endif
            </div>
        @endif

        <form wire:submit="register" class="flex flex-col gap-6">
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="firstName" :label="__('First name')" autocomplete="given-name" required autofocus />
                <flux:input wire:model="lastName" :label="__('Last name')" autocomplete="family-name" required />
            </div>

            <flux:input wire:model="email" type="email" :label="__('Email address')" autocomplete="email" required />

            <flux:input
                wire:model="phoneNumber"
                type="tel"
                :label="__('Phone number')"
                autocomplete="tel"
                maxlength="50"
                :description="__('Optional: We will only use your phone number to notify you if you win the drawing')"
            />

            <flux:checkbox wire:model="marketingOptIn" :label="__('Keep me updated by email')" :description="__('You can unsubscribe at any time.')" />

            <flux:error name="show" />

            <flux:button variant="primary" type="submit" class="w-full">
                {{ __('Join the quiz') }}
            </flux:button>
            @if ($show->quiz?->scoring_mode === QuizScoringMode::QuestionAnswer)
                <flux:button type="button" variant="ghost" class="w-full" wire:click="$set('recovering', true)">{{ __('Recover an existing quiz') }}</flux:button>
            @endif
        </form>

        @if ($show->quiz)
            <flux:card wire:poll.5s class="space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg">{{ __('Top 3') }}</flux:heading>
                    <flux:text class="text-xs">{{ __('Leaderboard') }}</flux:text>
                </div>
                @if ($this->topEntries->isEmpty())
                    <flux:text>{{ __('Be the first on the leaderboard! Complete your quiz at our booth to set the score to beat.') }}</flux:text>
                @else
                    <ol class="space-y-3">
                        @foreach ($this->topEntries as $entry)
                            <li wire:key="signup-leader-{{ $entry->id }}" class="flex items-center gap-3">
                                <flux:badge :color="$loop->first ? 'amber' : 'zinc'" size="sm">#{{ $loop->iteration }}</flux:badge>
                                <div class="min-w-0 flex-1">
                                    <flux:text class="truncate font-medium">{{ $entry->participant->first_name }} {{ $entry->participant->last_name }}</flux:text>
                                </div>
                                <div class="shrink-0 text-right">
                                    <flux:text class="font-semibold">{{ trans_choice(':count point|:count points', $entry->score, ['count' => $entry->score]) }}</flux:text>
                                    <flux:text class="text-xs tabular-nums">{{ number_format($entry->elapsed_ms / 1000, 3) }}s</flux:text>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                    <flux:text class="text-xs">{{ __('Higher score wins. Fastest time breaks ties.') }}</flux:text>
                @endif
            </flux:card>
        @endif
    @else
        <div class="space-y-6 text-center">
            <flux:heading size="xl">{{ __('Quiz registration') }}</flux:heading>
            <flux:callout variant="warning" icon="clock">
                {{ __('Registration is not currently available. Please check with the event staff.') }}
            </flux:callout>
        </div>
    @endif

    <flux:text class="text-center text-xs">
        <flux:link :href="route('login')" wire:navigate>{{ __('Staff sign in') }}</flux:link>
    </flux:text>
</div>
