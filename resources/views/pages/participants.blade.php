<?php

use App\Models\Participant;
use App\Models\Show;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Participants')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $view = 'active';

    #[Url]
    public string $search = '';

    public string $sortBy = 'name';

    public string $sortDirection = 'asc';

    #[Computed]
    public function activeShow(): ?Show
    {
        $activeShows = Show::query()->activeAt()->whereHas('quiz')->limit(2)->get();

        return $activeShows->count() === 1 ? $activeShows->first() : null;
    }

    /** @return LengthAwarePaginator<int, Participant> */
    #[Computed]
    public function participants(): LengthAwarePaginator
    {
        $query = Participant::query()
            ->with(['show:id,name', 'quizEntry:id,participant_id,score,completed_at'])
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%'.mb_strtolower(trim($this->search)).'%';

                $query->where(function (Builder $query) use ($search): void {
                    $query->whereRaw('LOWER(first_name) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$search]);
                });
            });

        if ($this->view === 'active') {
            $query->when(
                $this->activeShow,
                fn (Builder $query, Show $show): Builder => $query->whereBelongsTo($show),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            );
        } else {
            $query->whereIn('id', Participant::query()
                ->selectRaw('MAX(id)')
                ->groupByRaw('LOWER(email)'));
        }

        $this->applySorting($query);

        return $query->paginate(15);
    }

    /** @return Collection<string, EloquentCollection<int, Participant>> */
    #[Computed]
    public function histories(): Collection
    {
        if ($this->view !== 'all') {
            return collect();
        }

        $emails = $this->participants->getCollection()
            ->pluck('email')
            ->map(fn (string $email): string => mb_strtolower($email));

        return Participant::query()
            ->with(['show:id,name', 'quizEntry:id,participant_id,score,completed_at'])
            ->whereIn('email', $emails)
            ->latest('created_at')
            ->get()
            ->groupBy(fn (Participant $participant): string => mb_strtolower($participant->email));
    }

    public function sort(string $column): void
    {
        abort_unless(in_array($column, ['name', 'email', 'registered'], true), 404);

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedView(): void
    {
        $this->resetPage();
    }

    private function applySorting(Builder $query): void
    {
        match ($this->sortBy) {
            'email' => $query->orderBy('email', $this->sortDirection),
            'registered' => $query->orderBy('created_at', $this->sortDirection),
            default => $query
                ->orderBy('last_name', $this->sortDirection)
                ->orderBy('first_name', $this->sortDirection),
        };

        $query->orderBy('id');
    }
};
?>

<section class="w-full space-y-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Participants') }}</flux:heading>
            <flux:subheading>
                {{ $view === 'active'
                    ? ($this->activeShow ? __('Participants registered for :show.', ['show' => $this->activeShow->name]) : __('There is no single active quiz.'))
                    : __('Returning participants are grouped by email with their complete quiz history.') }}
            </flux:subheading>
        </div>

        <flux:radio.group wire:model.live="view" variant="segmented" size="sm" class="w-full sm:w-auto">
            <flux:radio value="active" icon="signal">{{ __('Active quiz') }}</flux:radio>
            <flux:radio value="all" icon="users">{{ __('All participants') }}</flux:radio>
        </flux:radio.group>
    </div>

    <flux:input
        wire:model.live.debounce.300ms="search"
        type="search"
        :label="__('Find participant')"
        :placeholder="__('Search by name or email')"
        clearable
        class="w-full sm:max-w-md"
    />

    @if ($this->participants->isEmpty())
        <flux:card class="text-center">
            <flux:heading>{{ $search !== '' ? __('No participants found') : ($view === 'active' ? __('No active quiz participants') : __('No participants yet')) }}</flux:heading>
            <flux:text class="mt-2">
                {{ $search !== '' ? __('Try a different name or email address.') : ($view === 'active' ? __('Registrations for the active quiz will appear here.') : __('Participant history will appear after someone registers.')) }}
            </flux:text>
        </flux:card>
    @else
        <flux:table :paginate="$this->participants" pagination:scroll-to="#participants-table" container:class="max-h-[70vh]" id="participants-table">
            <flux:table.columns sticky class="bg-white dark:bg-zinc-800">
                <flux:table.column
                    sortable
                    :sorted="$sortBy === 'name'"
                    :direction="$sortDirection"
                    wire:click="sort('name')"
                    sticky
                    class="bg-white dark:bg-zinc-800"
                >{{ __('Participant') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'email'" :direction="$sortDirection" wire:click="sort('email')">{{ __('Email') }}</flux:table.column>
                @if ($view === 'active')
                    <flux:table.column>{{ __('Email signup') }}</flux:table.column>
                    <flux:table.column>{{ __('Quiz status') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'registered'" :direction="$sortDirection" wire:click="sort('registered')">{{ __('Registered') }}</flux:table.column>
                @else
                    <flux:table.column>{{ __('Visits') }}</flux:table.column>
                    <flux:table.column>{{ __('Quiz history') }}</flux:table.column>
                @endif
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->participants as $participant)
                    <flux:table.row :key="$view.'-'.$participant->id">
                        <flux:table.cell variant="strong" sticky class="bg-white dark:bg-zinc-800">
                            {{ $participant->first_name }} {{ $participant->last_name }}
                        </flux:table.cell>
                        <flux:table.cell>{{ $participant->email }}</flux:table.cell>

                        @if ($view === 'active')
                            <flux:table.cell>
                                <flux:badge :color="$participant->marketing_opt_in ? 'green' : 'zinc'" size="sm">
                                    {{ $participant->marketing_opt_in ? __('Accepted') : __('Declined') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$participant->quizEntry?->completed_at ? 'green' : ($participant->quizEntry ? 'amber' : 'zinc')" size="sm">
                                    {{ $participant->quizEntry?->completed_at ? __('Completed') : ($participant->quizEntry ? __('In progress') : __('Waiting')) }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $participant->created_at->format('M j, Y g:i A') }}</flux:table.cell>
                        @else
                            @php($history = $this->histories->get(mb_strtolower($participant->email), collect()))
                            <flux:table.cell>{{ $history->count() }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                <div class="flex min-w-72 flex-wrap gap-2">
                                    @foreach ($history as $visit)
                                        <flux:badge :color="$visit->quizEntry?->completed_at ? 'green' : ($visit->quizEntry ? 'amber' : 'zinc')" size="sm">
                                            {{ $visit->show->name }}
                                            @if ($visit->quizEntry?->completed_at)
                                                · {{ trans_choice(':count point|:count points', $visit->quizEntry->score ?? 0, ['count' => $visit->quizEntry->score ?? 0]) }}
                                            @elseif ($visit->quizEntry)
                                                · {{ __('In progress') }}
                                            @else
                                                · {{ __('Registered') }}
                                            @endif
                                        </flux:badge>
                                    @endforeach
                                </div>
                            </flux:table.cell>
                        @endif
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
