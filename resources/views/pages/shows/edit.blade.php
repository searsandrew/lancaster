<?php

use App\Enums\QuizScoringMode;
use App\Enums\ShowActivationMode;
use App\Models\Question;
use App\Models\Show;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Configure show')] class extends Component {
    use WithFileUploads;

    public Show $show;
    public string $name;
    public string $activationMode;
    public bool $isActive;
    public ?string $startDate = null;
    public ?string $startTime = null;
    public ?string $endDate = null;
    public ?string $endTime = null;
    public string $scoringMode;
    public ?int $maximumScore = null;
    public ?TemporaryUploadedFile $perfectScoreImage = null;
    public string $leaderboardMessage = '';
    public string $newQuestion = '';
    /** @var array<int, string> */
    public array $questionPrompts = [];

    public function mount(Show $show): void
    {
        $this->show = $show->loadMissing('quiz.questions');
        $this->name = $show->name;
        $this->activationMode = $show->activation_mode->value;
        $this->isActive = $show->is_active;
        $this->startDate = $show->starts_at?->format('Y-m-d');
        $this->startTime = $show->starts_at?->format('H:i');
        $this->endDate = $show->ends_at?->format('Y-m-d');
        $this->endTime = $show->ends_at?->format('H:i');
        $this->scoringMode = $show->quiz->scoring_mode->value;
        $this->maximumScore = $show->quiz->maximum_score;
        $this->leaderboardMessage = $show->quiz->leaderboard_message ?? '';
        $this->refreshQuestions();
    }

    public function save(): void
    {
        $validated = $this->validate($this->configurationRules());
        $startsAt = $this->dateTime($validated['startDate'], $validated['startTime']);
        $endsAt = $this->dateTime($validated['endDate'], $validated['endTime']);

        if ($startsAt && $endsAt && $endsAt->lte($startsAt)) {
            $this->addError('endDate', __('The show must end after it starts.'));
            return;
        }

        if ($validated['scoringMode'] === 'per_answer' && $this->show->quiz->questions()->doesntExist()) {
            $this->addError('newQuestion', __('Add at least one question for per-answer scoring.'));
            return;
        }

        $previousImagePath = $this->show->quiz->perfect_score_image_path;
        $perfectScoreImagePath = $this->perfectScoreImage?->store('perfect-score-images', 'public');

        DB::transaction(function () use ($validated, $startsAt, $endsAt, $perfectScoreImagePath): void {
            $this->show->update([
                'name' => $validated['name'],
                'activation_mode' => $validated['activationMode'],
                'is_active' => $validated['activationMode'] === 'manual' && $validated['isActive'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
            $this->show->quiz->update([
                'scoring_mode' => $validated['scoringMode'],
                'maximum_score' => $validated['scoringMode'] === 'summary' ? $validated['maximumScore'] : null,
                'perfect_score_image_path' => $perfectScoreImagePath ?? $this->show->quiz->perfect_score_image_path,
                'leaderboard_message' => trim($validated['leaderboardMessage']) ?: null,
            ]);
        });

        if ($perfectScoreImagePath && $previousImagePath) {
            Storage::disk('public')->delete($previousImagePath);
        }

        $this->perfectScoreImage = null;
        $this->show->quiz->refresh();

        Flux::toast(variant: 'success', text: __('Show configuration saved.'));
    }

    public function addQuestion(): void
    {
        $validated = $this->validate(['newQuestion' => ['required', 'string', 'max:1000']]);
        $position = ((int) $this->show->quiz->questions()->max('position')) + 1;
        $this->show->quiz->questions()->create(['prompt' => $validated['newQuestion'], 'position' => $position]);
        $this->newQuestion = '';
        $this->refreshQuestions();
    }

    public function updateQuestion(int $questionId): void
    {
        $question = $this->question($questionId);
        $validated = $this->validate(["questionPrompts.{$questionId}" => ['required', 'string', 'max:1000']]);
        $question->update(['prompt' => $validated['questionPrompts'][$questionId]]);
    }

    public function removeQuestion(int $questionId): void
    {
        $this->question($questionId)->delete();
        $this->normalizePositions();
        $this->refreshQuestions();
    }

    public function moveQuestion(int $questionId, string $direction): void
    {
        abort_unless(in_array($direction, ['up', 'down'], true), 404);
        $question = $this->question($questionId);
        $adjacent = $this->show->quiz->questions()
            ->where('position', $direction === 'up' ? '<' : '>', $question->position)
            ->orderBy('position', $direction === 'up' ? 'desc' : 'asc')
            ->first();

        if (! $adjacent) {
            return;
        }

        $questionPosition = $question->position;
        $adjacentPosition = $adjacent->position;
        DB::transaction(function () use ($question, $adjacent, $questionPosition, $adjacentPosition): void {
            $question->update(['position' => 0]);
            $adjacent->update(['position' => $questionPosition]);
            $question->update(['position' => $adjacentPosition]);
        });
        $this->refreshQuestions();
    }

    /** @return array<string, array<int, mixed>> */
    private function configurationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'activationMode' => ['required', Rule::enum(ShowActivationMode::class)],
            'isActive' => ['boolean'],
            'startDate' => [Rule::requiredIf($this->activationMode === 'scheduled'), 'nullable', 'date_format:Y-m-d'],
            'startTime' => [Rule::requiredIf($this->activationMode === 'scheduled'), 'nullable', 'date_format:H:i'],
            'endDate' => [Rule::requiredIf($this->activationMode === 'scheduled'), 'nullable', 'date_format:Y-m-d'],
            'endTime' => [Rule::requiredIf($this->activationMode === 'scheduled'), 'nullable', 'date_format:H:i'],
            'scoringMode' => ['required', Rule::enum(QuizScoringMode::class)],
            'maximumScore' => [Rule::requiredIf($this->scoringMode === 'summary'), 'nullable', 'integer', 'min:1', 'max:65535'],
            'perfectScoreImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'leaderboardMessage' => ['nullable', 'string', 'max:160'],
        ];
    }

    private function question(int $questionId): Question
    {
        return $this->show->quiz->questions()->findOrFail($questionId);
    }

    private function refreshQuestions(): void
    {
        $this->questionPrompts = $this->show->quiz->questions()->pluck('prompt', 'id')->all();
    }

    private function normalizePositions(): void
    {
        $this->show->quiz->questions()->get()->each(fn (Question $question, int $index) => $question->update(['position' => $index + 1]));
    }

    private function dateTime(?string $date, ?string $time): ?Carbon
    {
        return $date && $time ? Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}") : null;
    }
}; ?>

<section class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('shows.index')" wire:navigate>{{ __('Shows') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $show->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <form wire:submit="save" class="space-y-8">
        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('Show details') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Show name')" required />
            <flux:radio.group wire:model.live="activationMode" variant="cards" :label="__('Activation')" class="grid sm:grid-cols-2">
                <flux:radio value="manual" :label="__('Manual')" :description="__('Staff controls activation.')" />
                <flux:radio value="scheduled" :label="__('Scheduled')" :description="__('Activates during its schedule.')" />
            </flux:radio.group>
            @if ($activationMode === 'manual')
                <flux:switch wire:model="isActive" :label="__('Show is active')" />
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:date-picker wire:model="startDate" type="input" :label="__('Start date')" />
                    <flux:time-picker wire:model="startTime" type="input" :label="__('Start time')" />
                    <flux:date-picker wire:model="endDate" type="input" :label="__('End date')" />
                    <flux:time-picker wire:model="endTime" type="input" :label="__('End time')" />
                </div>
            @endif

        </flux:card>

        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('Quiz scoring') }}</flux:heading>
            <flux:radio.group wire:model.live="scoringMode" variant="cards" class="grid sm:grid-cols-2">
                <flux:radio value="per_answer" :label="__('Per-answer scoring')" :description="__('Record each answer individually.')" />
                <flux:radio value="summary" :label="__('Summary scoring')" :description="__('Enter one final score.')" />
            </flux:radio.group>
            @if ($scoringMode === 'summary')
                <flux:input wire:model="maximumScore" type="number" min="1" max="65535" :label="__('Maximum score')" />
                @if ($questionPrompts)
                    <flux:callout>{{ __('Questions are retained if you switch back to per-answer scoring.') }}</flux:callout>
                @endif
            @else
                <div class="space-y-3">
                    @foreach ($questionPrompts as $questionId => $prompt)
                        <div class="flex items-start gap-2" wire:key="question-{{ $questionId }}">
                            <flux:input wire:model="questionPrompts.{{ $questionId }}" class="flex-1" />
                            <flux:button type="button" wire:click="moveQuestion({{ $questionId }}, 'up')" size="sm">{{ __('Up') }}</flux:button>
                            <flux:button type="button" wire:click="moveQuestion({{ $questionId }}, 'down')" size="sm">{{ __('Down') }}</flux:button>
                            <flux:button type="button" wire:click="updateQuestion({{ $questionId }})" size="sm">{{ __('Save') }}</flux:button>
                            <flux:button type="button" wire:click="removeQuestion({{ $questionId }})" variant="danger" size="sm">{{ __('Remove') }}</flux:button>
                        </div>
                    @endforeach
                    <div class="flex items-start gap-2">
                        <flux:input wire:model="newQuestion" :placeholder="__('New question or part')" class="flex-1" />
                        <flux:button type="button" wire:click="addQuestion">{{ __('Add question') }}</flux:button>
                    </div>
                    <flux:error name="newQuestion" />
                </div>
            @endif

            <flux:separator />

            <flux:textarea
                wire:model="leaderboardMessage"
                :label="__('Leaderboard message')"
                :description="__('Optional quiz-specific information shown in the bottom bar.')"
                rows="2"
                maxlength="160"
            />

            <flux:separator />

            <div class="space-y-4">
                <div>
                    <flux:heading>{{ __('Perfect score celebration') }}</flux:heading>
                    <flux:text>{{ __('Optionally upload the sticker or prize artwork shown when someone earns a perfect score.') }}</flux:text>
                </div>

                @if ($show->quiz->perfect_score_image_path)
                    <img
                        src="{{ Storage::disk('public')->url($show->quiz->perfect_score_image_path) }}"
                        alt="{{ __('Current perfect score artwork') }}"
                        class="max-h-48 rounded-xl border border-zinc-200 object-contain dark:border-zinc-700"
                    />
                @endif

                <flux:file-upload wire:model="perfectScoreImage" :label="__('Perfect score image')">
                    <flux:file-upload.dropzone
                        :heading="__('Drop an image here or click to browse')"
                        :text="__('JPG, PNG, or WebP up to 5 MB')"
                        with-progress
                    />
                </flux:file-upload>
            </div>
        </flux:card>

        <div class="flex justify-end"><flux:button variant="primary" type="submit">{{ __('Save configuration') }}</flux:button></div>
    </form>
</section>
