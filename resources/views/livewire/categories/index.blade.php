<?php

use App\Models\Category;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $name = '';
    public string $type = 'expense';
    public ?int $editingCategoryId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Category::class);
    }

    public function getCategoriesProperty()
    {
        return Auth::user()->categories()->orderBy('type')->orderBy('name')->get();
    }

    public function saveCategory(): void
    {
        $this->authorize('create', Category::class);

        Auth::user()->categories()->create($this->validate($this->rules()));

        $this->resetForm();
        session()->flash('status', 'Category created.');
    }

    public function editCategory(int $categoryId): void
    {
        $category = Category::findOrFail($categoryId);
        $this->authorize('update', $category);

        $this->editingCategoryId = $category->id;
        $this->name = $category->name;
        $this->type = $category->type;
    }

    public function updateCategory(): void
    {
        $category = Category::findOrFail($this->editingCategoryId);
        $this->authorize('update', $category);

        $validated = $this->validate($this->rules());

        if ($category->type !== $validated['type'] && $category->transactions()->exists()) {
            $this->addError('type', 'A category with transactions cannot change type.');

            return;
        }

        $category->update($validated);

        $this->resetForm();
        session()->flash('status', 'Category updated.');
    }

    public function deleteCategory(int $categoryId): void
    {
        $category = Category::findOrFail($categoryId);
        $this->authorize('delete', $category);

        $category->delete();

        if ($this->editingCategoryId === $categoryId) {
            $this->resetForm();
        }

        session()->flash('status', 'Category deleted.');
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    private function rules(): array
    {
        $uniqueName = Rule::unique('categories', 'name')
            ->where(fn ($query) => $query
                ->where('user_id', Auth::id())
                ->where('type', $this->type));

        if ($this->editingCategoryId) {
            $uniqueName->ignore($this->editingCategoryId);
        }

        return [
            'name' => ['required', 'string', 'max:255', $uniqueName],
            'type' => ['required', Rule::in(Category::TYPES)],
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['name', 'editingCategoryId']);
        $this->type = 'expense';
    }
}; ?>

<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('Categories') }}
    </h2>
</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <section class="p-6 bg-white shadow-sm sm:rounded-lg">
            <header>
                <h3 class="text-lg font-medium text-gray-900">
                    {{ $editingCategoryId ? __('Edit category') : __('Add category') }}
                </h3>
            </header>

            <form wire:submit="{{ $editingCategoryId ? 'updateCategory' : 'saveCategory' }}" class="mt-6 space-y-6">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <x-input-label for="name" :value="__('Name')" />
                        <x-text-input wire:model="name" id="name" class="mt-1 block w-full" type="text" maxlength="255" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="type" :value="__('Type')" />
                        <select wire:model="type" id="type" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                            @foreach (\App\Models\Category::TYPES as $categoryType)
                                <option value="{{ $categoryType }}">{{ ucfirst($categoryType) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('type')" class="mt-2" />
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <x-primary-button>{{ $editingCategoryId ? __('Update category') : __('Add category') }}</x-primary-button>

                    @if ($editingCategoryId)
                        <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                    @endif
                </div>
            </form>
        </section>

        <section class="p-6 bg-white shadow-sm sm:rounded-lg">
            <header>
                <h3 class="text-lg font-medium text-gray-900">{{ __('Your categories') }}</h3>
            </header>

            @if (session('status'))
                <p class="mt-4 text-sm font-medium text-green-600">{{ session('status') }}</p>
            @endif

            <div class="mt-6 space-y-4">
                @forelse ($this->categories as $category)
                    <article wire:key="category-{{ $category->id }}" class="flex flex-col gap-4 border border-gray-200 rounded-md p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h4 class="font-medium text-gray-900">{{ $category->name }}</h4>
                            <p class="text-sm text-gray-600">{{ ucfirst($category->type) }}</p>
                        </div>

                        <div class="flex gap-2">
                            <x-secondary-button type="button" wire:click="editCategory({{ $category->id }})">{{ __('Edit') }}</x-secondary-button>
                            <x-danger-button type="button" wire:click="deleteCategory({{ $category->id }})" wire:confirm="{{ __('Delete this category? Transactions will remain uncategorized.') }}">{{ __('Delete') }}</x-danger-button>
                        </div>
                    </article>
                @empty
                    <p class="text-sm text-gray-600">{{ __('No categories yet.') }}</p>
                @endforelse
            </div>
        </section>
    </div>
</div>