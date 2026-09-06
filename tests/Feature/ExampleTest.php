<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_guests_see_the_pennypot_entry_page(): void
    {
        $response = $this->get('/');

        $response->assertOk()->assertSee('PennyPot');
    }

    public function test_authenticated_users_are_redirected_to_the_dashboard_from_root(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }
}
