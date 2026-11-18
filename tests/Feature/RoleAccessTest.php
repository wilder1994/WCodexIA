<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_login_and_cannot_open_the_chat(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/chat')->assertRedirect('/login');
        $this->get('/versiones')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Entrar', false);
    }

    public function test_an_admin_sees_their_area_and_not_the_model_tools(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Ana Admin']);

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertSee('Área de administración', false)
            ->assertDontSee('Versiones LoRA', false)
            ->assertDontSee(route('chat.index'), false);

        $this->actingAs($admin)->get('/chat')->assertForbidden();
        $this->actingAs($admin)->get('/versiones')->assertForbidden();
    }

    public function test_a_superadmin_sees_the_versions_and_the_chat(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/*' => Http::response(['models' => []], 200),
            'http://127.0.0.1:8090/*' => Http::response(['versions' => []], 200),
        ]);

        $user = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('Versiones LoRA', false)
            ->assertSee(route('chat.index'), false);

        $this->actingAs($user)->get('/chat')->assertOk()->assertSee('Asistente local', false);
        $this->actingAs($user)->get('/versiones')->assertOk()->assertSee('Versiones LoRA', false);
    }
}
