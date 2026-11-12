<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ChatPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_chat_shows_a_disabled_image_slot(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/*' => Http::response(['models' => []], 200),
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('name="image"', false);
        $response->assertSee('disabled', false);
        $response->assertSee('Espacio de imagen reservado', false);
        $response->assertSee('El modelo todavía no está cargado', false);
    }

    public function test_a_message_is_stored_and_the_session_can_be_reopened(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertStringStartsWith('http://127.0.0.1:11434/', $request->url());

            if (str_ends_with($request->url(), '/api/tags')) {
                return Http::response(['models' => []]);
            }

            return Http::response([
                'message' => ['content' => 'Separa el contrato del repositorio concreto.'],
            ]);
        });

        $this->post('/c', [
            'prompt' => 'Diseña un repositorio para pedidos.',
        ])->assertRedirect();

        $conversation = Conversation::query()->first();
        $this->assertNotNull($conversation);

        $this->post('/c', [
            'conversation_id' => $conversation->id,
            'prompt' => 'Agrega la interfaz del contrato.',
        ])->assertRedirect('/c/'.$conversation->id);

        $this->get('/c/'.$conversation->id)
            ->assertOk()
            ->assertSee('Diseña un repositorio para pedidos.', false)
            ->assertSee('Agrega la interfaz del contrato.', false)
            ->assertSee('Separa el contrato del repositorio concreto.', false);

        $this->get('/')
            ->assertOk()
            ->assertSee('Diseña un repositorio para pedidos.', false);

        $this->assertDatabaseCount('messages', 4);
    }

    public function test_a_new_chat_sends_prior_sessions_to_the_local_model(): void
    {
        Http::preventStrayRequests();
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $this->assertStringStartsWith('http://127.0.0.1:11434/', $request->url());

            if (str_ends_with($request->url(), '/api/chat')) {
                $captured = json_encode($request->data());

                return Http::response([
                    'message' => ['content' => 'Continúo con lo que ya hablamos.'],
                ]);
            }

            return Http::response(['models' => []]);
        });

        $this->post('/c', [
            'prompt' => 'Marca alfa para el repositorio de pedidos.',
        ])->assertRedirect();

        $oldest = Conversation::query()->first();
        $this->assertNotNull($oldest);

        $this->post('/c', [
            'prompt' => 'Marca beta para los contratos.',
        ])->assertRedirect();

        $this->post('/c', [
            'prompt' => 'Qué quedó en las sesiones anteriores?',
            'context_ids' => [$oldest->id],
        ])->assertRedirect();

        $this->assertIsString($captured);
        $this->assertStringContainsString('Memoria de sesiones anteriores', $captured);
        $this->assertStringContainsString('Marca alfa para el repositorio de pedidos.', $captured);
        $this->assertStringContainsString('Marca beta para los contratos.', $captured);

        $this->get('/')
            ->assertOk()
            ->assertSee('name="context_ids[]"', false)
            ->assertSee('Cargar', false);
    }

    public function test_an_uploaded_image_is_rejected_without_calling_the_model(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->post('/c', [
            'prompt' => 'Describe esta captura.',
            'image' => UploadedFile::fake()->image('captura.png'),
        ])->assertRedirect()
            ->assertSessionHasErrors('image');

        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 0);
    }
}
