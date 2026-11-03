<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\AccountApproved;
use App\Notifications\AccountRejected;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url]
    public string $status = 'pending';

    #[Url]
    public string $search = '';

    /**
     * Ids of the pending users currently selected for bulk actions.
     *
     * @var array<int, int|string>
     */
    public array $selected = [];

    public ?int $rejectUserId = null;
    public string $rejectionReason = '';
    public ?int $changeRoleUserId = null;
    public string $changeRoleValue = '';
    public ?int $deactivateUserId = null;

    /**
     * Reset the page whenever the status filter changes.
     */
    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    /**
     * Reset the page whenever the search term changes.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * The filtered, paginated list of users for the current view.
     */
    public function users(): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles', 'approvedBy'])
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%');
            }))
            ->orderBy('created_at', 'desc')
            ->paginate(15);
    }

    /**
     * The count of users in each status bucket.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        return [
            UserStatus::Pending->value => User::pending()->count(),
            UserStatus::Approved->value => User::approved()->count(),
            UserStatus::Rejected->value => User::where('status', UserStatus::Rejected->value)->count(),
        ];
    }

    /**
     * The friendly empty-state message for the current filter.
     *
     * @return array{icon: string, text: string}
     */
    public function emptyState(): array
    {
        return match ($this->status) {
            UserStatus::Pending->value => ['icon' => 'check-circle', 'text' => 'No one is waiting for approval.'],
            UserStatus::Approved->value => ['icon' => 'user-group', 'text' => 'No approved users yet.'],
            UserStatus::Rejected->value => ['icon' => 'x-circle', 'text' => 'No rejected users.'],
            default => ['icon' => 'users', 'text' => 'No users found.'],
        };
    }

    /**
     * The roles the current user is allowed to assign.
     *
     * @return array<int, Role>
     */
    public function assignableRoles(): array
    {
        $roles = [Role::User, Role::Admin];

        if ($this->viewer()->hasRole(Role::SuperAdmin->value)) {
            $roles[] = Role::SuperAdmin;
        }

        return $roles;
    }

    /**
     * The human-readable label for a user's primary role.
     */
    public function roleLabel(User $user): string
    {
        return Role::tryFrom((string) $user->getRoleNames()->first())?->label() ?? '—';
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    // -- Permission helpers -------------------------------------------------

    public function canApprove(): bool
    {
        return $this->viewer()->can(Permission::UsersApprove->value);
    }

    public function canManage(): bool
    {
        return $this->viewer()->can(Permission::UsersManage->value);
    }

    public function canManageRoles(): bool
    {
        return $this->viewer()->can(Permission::RolesManage->value);
    }

    // -- Per-row action visibility -----------------------------------------

    public function canApproveUser(User $user): bool
    {
        return $this->canApprove() && ($user->isPending() || $user->isRejected()) && $this->canActOn($user);
    }

    public function canRejectUser(User $user): bool
    {
        return $this->canApprove() && $user->isPending() && $this->canActOn($user);
    }

    public function canChangeRoleFor(User $user): bool
    {
        return $this->canManageRoles() && $this->canActOn($user);
    }

    public function canDeactivateUser(User $user): bool
    {
        return $this->canManage() && $user->isApproved() && $this->canActOn($user);
    }

    public function canReactivateUser(User $user): bool
    {
        return $this->canManage() && ($user->isPending() || $user->isRejected()) && $this->canActOn($user);
    }

    public function hasActions(User $user): bool
    {
        return $this->canApproveUser($user)
            || $this->canRejectUser($user)
            || $this->canChangeRoleFor($user)
            || $this->canDeactivateUser($user)
            || $this->canReactivateUser($user);
    }

    // -- Actions ------------------------------------------------------------

    public function approve(int $id): void
    {
        abort_unless($this->canApprove(), 403);

        $target = $this->target($id);

        abort_unless($target->isPending() || $target->isRejected(), 403);

        $this->markApproved($target);
        $this->selected = array_values(array_diff($this->selected, [$id]));

        $target->notify(new AccountApproved);

        Flux::toast(variant: 'success', text: "{$target->name} approved");
    }

    public function approveSelected(): void
    {
        abort_unless($this->canApprove(), 403);

        $count = 0;

        foreach (User::query()->whereKey($this->selected)->get() as $target) {
            if (! ($target->isPending() || $target->isRejected()) || ! $this->canActOn($target)) {
                continue;
            }

            $this->markApproved($target);
            $target->notify(new AccountApproved);

            $count++;
        }

        $this->selected = [];

        Flux::toast(variant: 'success', text: "{$count} user(s) approved");
    }

    public function reject(int $id, ?string $reason = null): void
    {
        abort_unless($this->canApprove(), 403);

        $target = $this->target($id);

        abort_unless($target->isPending(), 403);

        $reason = trim((string) $reason);
        $reason = $reason === '' ? null : $reason;

        abort_if($reason !== null && mb_strlen($reason) > 500, 422, 'The reason may not be greater than 500 characters.');

        $target->update([
            'status' => UserStatus::Rejected->value,
            'rejection_reason' => $reason,
        ]);

        $this->selected = array_values(array_diff($this->selected, [$id]));

        $target->notify(new AccountRejected($reason));

        Flux::toast(variant: 'success', text: "{$target->name} rejected");
    }

    public function deactivate(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $target = $this->target($id);

        abort_unless($target->isApproved(), 403);

        $target->update([
            'status' => UserStatus::Rejected->value,
            'rejection_reason' => 'Account deactivated by an administrator',
        ]);

        Flux::toast(variant: 'success', text: "{$target->name} deactivated");
    }

    public function reactivate(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $target = $this->target($id);

        abort_unless($target->isPending() || $target->isRejected(), 403);

        $this->markApproved($target);

        Flux::toast(variant: 'success', text: "{$target->name} reactivated");
    }

    public function changeRole(int $id, string $role): void
    {
        abort_unless($this->canManageRoles(), 403);

        $target = $this->target($id);

        $newRole = Role::tryFrom($role);
        abort_unless($newRole !== null, 403);

        $viewer = $this->viewer();

        if ($newRole === Role::SuperAdmin || $target->hasRole(Role::SuperAdmin->value)) {
            abort_unless($viewer->hasRole(Role::SuperAdmin->value), 403);
        }

        if ($target->hasRole(Role::SuperAdmin->value) && $newRole !== Role::SuperAdmin) {
            abort_if(User::role(Role::SuperAdmin->value)->count() <= 1, 403, 'The last super admin cannot be demoted.');
        }

        $target->syncRoles([$newRole->value]);

        Flux::toast(variant: 'success', text: "{$target->name} is now ".$newRole->label());
    }

    // -- Modal openers ------------------------------------------------------

    public function openReject(int $id): void
    {
        $this->rejectUserId = $id;
        $this->rejectionReason = '';
        $this->modal('reject')->show();
    }

    public function confirmReject(): void
    {
        $this->validate([
            'rejectionReason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->reject((int) $this->rejectUserId, $this->rejectionReason ?: null);

        $this->modal('reject')->close();
    }

    public function openChangeRole(int $id): void
    {
        $target = User::findOrFail($id);

        $this->changeRoleUserId = $id;
        $this->changeRoleValue = $target->getRoleNames()->first() ?? Role::User->value;
        $this->modal('change-role')->show();
    }

    public function confirmChangeRole(): void
    {
        $this->changeRole((int) $this->changeRoleUserId, $this->changeRoleValue);

        $this->modal('change-role')->close();
    }

    public function openDeactivate(int $id): void
    {
        $this->deactivateUserId = $id;
        $this->modal('deactivate')->show();
    }

    public function confirmDeactivate(): void
    {
        $this->deactivate((int) $this->deactivateUserId);

        $this->modal('deactivate')->close();
    }

    // -- Internals ----------------------------------------------------------

    private function viewer(): User
    {
        return auth()->user();
    }

    private function target(int $id): User
    {
        $target = User::findOrFail($id);

        abort_unless($this->canActOn($target), 403);

        return $target;
    }

    private function canActOn(User $target): bool
    {
        $viewer = $this->viewer();

        if ($viewer->is($target)) {
            return false;
        }

        if ($target->hasRole(Role::SuperAdmin->value) && ! $viewer->hasRole(Role::SuperAdmin->value)) {
            return false;
        }

        return true;
    }

    private function markApproved(User $target): void
    {
        $target->update([
            'status' => UserStatus::Approved->value,
            'approved_at' => now(),
            'approved_by' => $this->viewer()->id,
            'rejection_reason' => null,
        ]);
    }
}; ?>

@php
    $users = $this->users();
    $stats = $this->stats();
    $empty = $this->emptyState();
@endphp

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Users</flux:heading>
        <flux:subheading size="lg">Approve new sign-ups and manage who can do what.</flux:subheading>
    </div>

    {{-- Stat strip --}}
    <div class="grid grid-cols-3 gap-3">
        <div class="rounded-xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-1.5">
                <flux:icon name="clock" class="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                <span class="text-xl font-semibold text-amber-600 dark:text-amber-400">{{ $stats[UserStatus::Pending->value] }}</span>
            </div>
            <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Pending</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-1.5">
                <flux:icon name="check-circle" class="size-4 shrink-0 text-green-600 dark:text-green-400" />
                <span class="text-xl font-semibold text-green-600 dark:text-green-400">{{ $stats[UserStatus::Approved->value] }}</span>
            </div>
            <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Approved</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-1.5">
                <flux:icon name="x-circle" class="size-4 shrink-0 text-red-600 dark:text-red-400" />
                <span class="text-xl font-semibold text-red-600 dark:text-red-400">{{ $stats[UserStatus::Rejected->value] }}</span>
            </div>
            <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Rejected</div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <flux:radio.group wire:model.live="status" variant="segmented">
                <flux:radio value="pending" label="Pending ({{ $stats[UserStatus::Pending->value] }})" />
                <flux:radio value="approved" label="Approved ({{ $stats[UserStatus::Approved->value] }})" />
                <flux:radio value="rejected" label="Rejected ({{ $stats[UserStatus::Rejected->value] }})" />
                <flux:radio value="all" label="All" />
            </flux:radio.group>
        </div>

        <div class="lg:w-72">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search name or email" />
        </div>
    </div>

    {{-- Bulk action bar --}}
    @if (count($selected) > 0)
        <div class="sticky bottom-0 z-10 flex items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
            <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ count($selected) }} selected</span>

            <div class="flex items-center gap-2">
                <flux:button variant="ghost" wire:click="clearSelection">Clear</flux:button>

                @if ($this->canApprove())
                    <flux:button variant="primary" wire:click="approveSelected">Approve selected</flux:button>
                @endif
            </div>
        </div>
    @endif

    @if ($users->isNotEmpty())
        <flux:card class="overflow-hidden p-4">
            {{-- Desktop --}}
            <div class="hidden md:block">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column class="w-10"></flux:table.column>
                        <flux:table.column>User</flux:table.column>
                        <flux:table.column>Role</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column>Signed up</flux:table.column>
                        <flux:table.column>Approved by</flux:table.column>
                        <flux:table.column class="w-10"><span class="sr-only">Actions</span></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($users as $user)
                            <flux:table.row :key="$user->id">
                                <flux:table.cell>
                                    @if ($user->isPending())
                                        <flux:checkbox wire:model="selected" :value="$user->id" />
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell variant="strong">
                                    <div class="flex items-center gap-3">
                                        <flux:avatar :initials="$user->initials()" size="sm" />
                                        <div class="min-w-0">
                                            <div class="truncate font-medium text-zinc-800 dark:text-white">{{ $user->name }}</div>
                                            <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $user->email }}</div>
                                        </div>
                                    </div>
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge color="teal">{{ $this->roleLabel($user) }}</flux:badge>
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge :color="$user->status->color()">{{ $user->status->label() }}</flux:badge>
                                </flux:table.cell>

                                <flux:table.cell>
                                    <span title="{{ $user->created_at->format('M j, Y g:i A') }}">{{ $user->created_at->diffForHumans() }}</span>
                                </flux:table.cell>

                                <flux:table.cell>
                                    {{ $user->approvedBy?->name ?? '—' }}
                                </flux:table.cell>

                                <flux:table.cell align="end">
                                    @if ($this->hasActions($user))
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" aria-label="Actions" />
                                            <flux:menu>
                                                @if ($this->canApproveUser($user))
                                                    <flux:menu.item icon="check" wire:click="approve({{ $user->id }})">Approve</flux:menu.item>
                                                @endif
                                                @if ($this->canRejectUser($user))
                                                    <flux:menu.item icon="x-mark" variant="danger" wire:click="openReject({{ $user->id }})">Reject…</flux:menu.item>
                                                @endif
                                                @if ($this->canChangeRoleFor($user))
                                                    <flux:menu.item icon="pencil" wire:click="openChangeRole({{ $user->id }})">Change role…</flux:menu.item>
                                                @endif
                                                @if ($this->canDeactivateUser($user))
                                                    <flux:menu.item icon="power" variant="danger" wire:click="openDeactivate({{ $user->id }})">Deactivate</flux:menu.item>
                                                @endif
                                                @if ($this->canReactivateUser($user))
                                                    <flux:menu.item icon="arrow-path" wire:click="reactivate({{ $user->id }})">Reactivate</flux:menu.item>
                                                @endif
                                            </flux:menu>
                                        </flux:dropdown>
                                    @else
                                        <span class="text-zinc-400 dark:text-zinc-500">—</span>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            {{-- Mobile --}}
            <div class="md:hidden">
                @foreach ($users as $user)
                    <div class="border-t border-zinc-100 py-4 first:border-t-0 dark:border-zinc-800">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <flux:avatar :initials="$user->initials()" size="sm" />
                                <div class="min-w-0">
                                    <div class="truncate font-medium text-zinc-800 dark:text-white">{{ $user->name }}</div>
                                    <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $user->email }}</div>
                                </div>
                            </div>

                            @if ($this->hasActions($user))
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" aria-label="Actions" />
                                    <flux:menu>
                                        @if ($this->canApproveUser($user))
                                            <flux:menu.item icon="check" wire:click="approve({{ $user->id }})">Approve</flux:menu.item>
                                        @endif
                                        @if ($this->canRejectUser($user))
                                            <flux:menu.item icon="x-mark" variant="danger" wire:click="openReject({{ $user->id }})">Reject…</flux:menu.item>
                                        @endif
                                        @if ($this->canChangeRoleFor($user))
                                            <flux:menu.item icon="pencil" wire:click="openChangeRole({{ $user->id }})">Change role…</flux:menu.item>
                                        @endif
                                        @if ($this->canDeactivateUser($user))
                                            <flux:menu.item icon="power" variant="danger" wire:click="openDeactivate({{ $user->id }})">Deactivate</flux:menu.item>
                                        @endif
                                        @if ($this->canReactivateUser($user))
                                            <flux:menu.item icon="arrow-path" wire:click="reactivate({{ $user->id }})">Reactivate</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <flux:badge color="teal">{{ $this->roleLabel($user) }}</flux:badge>
                            <flux:badge :color="$user->status->color()">{{ $user->status->label() }}</flux:badge>
                        </div>

                        <div class="mt-3 space-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                            <div>Signed up {{ $user->created_at->diffForHumans() }}</div>
                            <div>Approved by {{ $user->approvedBy?->name ?? '—' }}</div>
                        </div>

                        @if ($user->isPending())
                            <div class="mt-3">
                                <flux:checkbox wire:model="selected" :value="$user->id" label="Select for bulk approval" />
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </flux:card>

        <div>
            <flux:pagination :paginator="$users" />
        </div>
    @else
        <flux:card>
            <div class="flex flex-col items-center justify-center gap-3 py-16 text-center">
                <flux:icon :name="$empty['icon']" class="size-10 text-zinc-400 dark:text-zinc-500" />
                <flux:subheading>{{ $empty['text'] }}</flux:subheading>
            </div>
        </flux:card>
    @endif

    {{-- Reject modal --}}
    <flux:modal name="reject" class="md:max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Reject this sign-up?</flux:heading>
                <flux:subheading>The user will be told their request was declined.</flux:subheading>
            </div>

            <flux:textarea wire:model="rejectionReason" label="Reason (optional)" rows="4" placeholder="Why is this sign-up being rejected?" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="confirmReject">Reject</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Change role modal --}}
    <flux:modal name="change-role" class="md:max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Change role</flux:heading>
                <flux:subheading>Choose a new role for this user.</flux:subheading>
            </div>

            <flux:select wire:model="changeRoleValue" label="Role">
                @foreach ($this->assignableRoles() as $role)
                    <flux:select.option :value="$role->value">{{ $role->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" wire:click="confirmChangeRole">Save</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Deactivate modal --}}
    <flux:modal name="deactivate" class="md:max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Deactivate this user?</flux:heading>
                <flux:subheading>They will be signed out and unable to log in until reactivated.</flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="confirmDeactivate">Deactivate</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
