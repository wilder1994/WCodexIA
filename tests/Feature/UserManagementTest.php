<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_superadmin_manages_users(): void
    {
        $this->get('/usuarios')->assertRedirect('/login');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/usuarios')->assertForbidden();

        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($superadmin)
            ->get('/usuarios')
            ->assertOk()
            ->assertSee('Nuevo usuario', false);
    }

    public function test_a_new_user_gets_chat_only_until_versions_are_granted(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/*' => Http::response(['models' => []], 200),
        ]);

        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($superadmin)->post('/usuarios', [
            'name' => 'Nora',
            'email' => 'nora@wcodex.cloud',
            'password' => 'clave-segura',
            'can_chat' => '1',
        ])->assertRedirect('/usuarios');

        $nora = User::query()->where('email', 'nora@wcodex.cloud')->firstOrFail();
        $this->assertTrue($nora->can_chat);
        $this->assertFalse($nora->can_versions);
        $this->assertSame('admin', $nora->role);

        $this->actingAs($nora)->get('/chat')->assertOk();
        $this->actingAs($nora)->get('/versiones')->assertForbidden();
        $this->actingAs($nora)->get('/usuarios')->assertForbidden();

        $this->actingAs($superadmin)->put('/usuarios/'.$nora->id, [
            'name' => 'Nora',
            'email' => 'nora@wcodex.cloud',
            'can_chat' => '1',
            'can_versions' => '1',
        ])->assertRedirect('/usuarios');

        $this->actingAs($nora->fresh())->get('/versiones')->assertOk();

        $this->actingAs($superadmin)
            ->delete('/usuarios/'.$nora->id)
            ->assertRedirect('/usuarios');
        $this->assertNull(User::query()->where('email', 'nora@wcodex.cloud')->first());
    }

    public function test_a_superadmin_cannot_delete_their_own_account(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($superadmin)
            ->delete('/usuarios/'.$superadmin->id)
            ->assertRedirect()
            ->assertSessionHasErrors('user');

        $this->assertModelExists($superadmin);
    }
}
