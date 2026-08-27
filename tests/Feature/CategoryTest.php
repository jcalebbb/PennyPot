<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_the_categories_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('categories.index'))
            ->assertOk()
            ->assertSeeVolt('categories.index');
    }

    public function test_guest_cannot_view_the_categories_page(): void
    {
        $this->get(route('categories.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_create_income_and_expense_categories(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('categories.index')
            ->set('name', 'Salary')
            ->set('type', 'income')
            ->call('saveCategory')
            ->assertHasNoErrors();

        Volt::test('categories.index')
            ->set('name', 'Food')
            ->set('type', 'expense')
            ->call('saveCategory')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('categories', ['user_id' => $user->id, 'name' => 'Salary', 'type' => 'income']);
        $this->assertDatabaseHas('categories', ['user_id' => $user->id, 'name' => 'Food', 'type' => 'expense']);
    }

    public function test_user_only_sees_their_own_categories(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Category::factory()->for($user)->create(['name' => 'My category']);
        Category::factory()->for($otherUser)->create(['name' => 'Private category']);

        $this->actingAs($user)
            ->get(route('categories.index'))
            ->assertSee('My category')
            ->assertDontSee('Private category');
    }

    public function test_user_can_update_and_delete_their_own_category(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['name' => 'Old name']);

        $this->actingAs($user);

        Volt::test('categories.index')
            ->call('editCategory', $category->id)
            ->set('name', 'Updated name')
            ->call('updateCategory')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Updated name']);

        Volt::test('categories.index')
            ->call('deleteCategory', $category->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_user_cannot_update_or_delete_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $category = Category::factory()->for($otherUser)->create();

        $this->actingAs($user);

        Volt::test('categories.index')
            ->set('editingCategoryId', $category->id)
            ->call('updateCategory')
            ->assertForbidden();

        Volt::test('categories.index')
            ->call('deleteCategory', $category->id)
            ->assertForbidden();
    }

    public function test_duplicate_category_names_are_rejected_for_the_same_user_and_type(): void
    {
        $user = User::factory()->create();
        Category::factory()->for($user)->create(['name' => 'Other', 'type' => 'expense']);

        $this->actingAs($user);

        Volt::test('categories.index')
            ->set('name', 'Other')
            ->set('type', 'expense')
            ->call('saveCategory')
            ->assertHasErrors('name');
    }

    public function test_different_users_can_use_the_same_category_name(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->actingAs($firstUser);
        Volt::test('categories.index')
            ->set('name', 'Food')
            ->set('type', 'expense')
            ->call('saveCategory')
            ->assertHasNoErrors();

        $this->actingAs($secondUser);
        Volt::test('categories.index')
            ->set('name', 'Food')
            ->set('type', 'expense')
            ->call('saveCategory')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('categories', 2);
    }
}
