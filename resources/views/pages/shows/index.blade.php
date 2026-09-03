<?php

use App\Enums\QuizScoringMode;
use App\Enums\ShowActivationMode;
use App\Models\Show;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shows')] class extends Component {
    public string $name = '';
    public string $activationMode = 'manual';
    public bool $isActive = false;
    public ?string $startDate = null;
    public ?string $startTime = null;
    public ?string $endDate = null;
    public ?string $endTime = null;
    public string $scoringMode = 'per_answer';
    public ?int $maximumScore = null;

    /** @return Collection<int, Show> */
    #[Computed]
    public function shows(): Collection
    {
        return Show::query()->with('quiz')->latest('starts_at')->latest('id')->get();
    }

    public function createShow(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'activationMode' => ['required', Rule::enum(ShowActivationMode::class)],
            'isActive' => ['boolean'],
            'startDate' => [Rule::requiredIf($this->activationMode === 'scheduled'), 'nullable', 'date_format:Y-m-d'],
            'startTime' => [Rule::requiredIf($this->activationMode === 'scheduled'), 'nullable', 'date_format:H:i'],
            'endDate' => [Rule::requiredIf($this->activationMode === 'scheduled'), 'nullable', 'date_format:Y-m-d'],
            'endTime' => [Rule::requiredIf($this->activationMode === 'scheduled'), 'nullable', 'date_format:H:i'],
            'scoringMode' => ['required', Rule::enum(QuizScoringMode::class)],
            'maximumScore' => [Rule::requiredIf($this->scoringMode === 'summary'), 'nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        $startsAt = $this->dateTime($validated['startDate'], $validated['startTime']);
        $endsAt = $this->dateTime($validated['endDate'], $validated['endTime']);

        if ($startsAt && $endsAt && $endsAt->lte($startsAt)) {
            $this->addError('endDate', __('The show must end after it starts.'));

            return;
        }

        DB::transaction(function () use ($validated, $startsAt, $endsAt): void {
            $show = Show::query()->create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'activation_mode' => $validated['activationMode'],
                'is_active' => $validated['activationMode'] === 'manual' && $validated['isActive'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            $show->quiz()->create([
                'scoring_mode' => $validated['scoringMode'],
                'maximum_score' => $validated['scoringMode'] === 'summary' ? $validated['maximumScore'] : null,
            ]);
        });

        $this->reset();
        unset($this->shows);
        Flux::modal('create-show')->close();
        Flux::toast(variant: 'success', text: __('Show created.'));
    }

    private function dateTime(?string $date, ?string $time): ?Carbon
    {
        return $date && $time ? Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}") : null;
    }

    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'show';
        $slug = $baseSlug;
        $suffix = 2;

        while (Show::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Shows') }}</flux:heading>
            <flux:subheading>{{ __('Create shows and configure how their quizzes are scored.') }}</flux:subheading>
        </div>
        <flux:modal.trigger name="create-show">
            <flux:button variant="primary">{{ __('New show') }}</flux:button>
        </flux:modal.trigger>
    </div>

    @if ($this->shows->isEmpty())
        <flux:card class="text-center">
            <flux:heading>{{ __('No shows yet') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Create your first show to configure its quiz.') }}</flux:text>
        </flux:card>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Show') }}</flux:table.column>
                <flux:table.column>{{ __('Activation') }}</flux:table.column>
                <flux:table.column>{{ __('Scoring') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->shows as $show)
                    <flux:table.row :key="$show->id">
                        <flux:table.cell variant="strong">{{ $show->name }}</flux:table.cell>
                        <flux:table.cell>{{ $show->activation_mode->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $show->quiz?->scoring_mode->label() ?? __('Not configured') }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$show->isActiveAt() ? 'green' : 'zinc'" size="sm">
                                {{ $show->isActiveAt() ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="text-end">
                            <flux:button :href="route('shows.edit', $show)" variant="ghost" size="sm" wire:navigate>{{ __('Configure') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="create-show" class="md:w-[42rem]">
        <form wire:submit="createShow" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create show') }}</flux:heading>
                <flux:subheading>{{ __('Configure registration timing and quiz scoring.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Show name')" required autofocus />

            <flux:radio.group wire:model.live="activationMode" variant="cards" :label="__('Activation')" class="grid sm:grid-cols-2">
                <flux:radio value="manual" :label="__('Manual')" :description="__('Staff controls whether this show is active.')" />
                <flux:radio value="scheduled" :label="__('Scheduled')" :description="__('Activates between selected dates and times.')" />
            </flux:radio.group>

            @if ($activationMode === 'manual')
                <flux:switch wire:model="isActive" :label="__('Activate this show now')" />
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:date-picker wire:model="startDate" type="input" :label="__('Start date')" />
                    <flux:time-picker wire:model="startTime" type="input" :label="__('Start time')" />
                    <flux:date-picker wire:model="endDate" type="input" :label="__('End date')" />
                    <flux:time-picker wire:model="endTime" type="input" :label="__('End time')" />
                </div>
            @endif

            <flux:separator />

            <flux:radio.group wire:model.live="scoringMode" variant="cards" :label="__('Scoring method')" class="grid sm:grid-cols-2">
                <flux:radio value="per_answer" :label="__('Per-answer scoring')" :description="__('Staff records whether each answer is correct.')" />
                <flux:radio value="summary" :label="__('Summary scoring')" :description="__('Staff enters one final score for the attempt.')" />
            </flux:radio.group>

            @if ($scoringMode === 'summary')
                <flux:input wire:model="maximumScore" type="number" min="1" max="65535" :label="__('Maximum score')" />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Create show') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
