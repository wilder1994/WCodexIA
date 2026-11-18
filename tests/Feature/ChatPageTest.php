<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ChatPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'superadmin']));
    }

    public function test_the_chat_shows_an_enabled_image_slot(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/*' => Http::response(['models' => []], 200),
        ]);

        $response = $this->get('/chat');

        $response->assertOk();
        $response->assertSee('name="image"', false);
        $response->assertSee('accept="image/jpeg,image/png,image/webp,image/gif"', false);
        $response->assertSee('aria-label="Adjuntar imagen"', false);
        $response->assertSee('aria-label="Dictar"', false);
        $response->assertSee('La imagen se guarda en el servidor. El modelo recibe el texto leído', false);
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

        $this->get('/chat')
            ->assertOk()
            ->assertSee('Diseña un repositorio para pedidos.', false);

        $this->assertDatabaseCount('messages', 4);
    }

    public function test_a_long_reply_is_streamed_and_stored_whole(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertStringStartsWith('http://127.0.0.1:11434/', $request->url());

            if (str_ends_with($request->url(), '/api/tags')) {
                return Http::response(['models' => []]);
            }

            $this->assertTrue((bool) ($request->data()['stream'] ?? false));
            $body = json_encode(['message' => ['content' => 'Parte uno. ']], JSON_UNESCAPED_UNICODE)."\n"
                .json_encode(['message' => ['content' => 'Parte dos, completa.'], 'done' => true], JSON_UNESCAPED_UNICODE)."\n";

            return Http::response($body);
        });

        $response = $this->withHeader('Accept', 'application/x-ndjson')
            ->post('/c', ['prompt' => str_repeat('Explica el módulo con detalle. ', 30)]);

        $response->assertOk();
        $stream = $response->streamedContent();
        $this->assertStringContainsString('Parte uno.', $stream);
        $this->assertStringContainsString('Parte dos, completa.', $stream);

        $conversation = Conversation::query()->first();
        $this->assertNotNull($conversation);
        $this->get('/c/'.$conversation->id)
            ->assertOk()
            ->assertSee('Parte uno. Parte dos, completa.', false);
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
        $this->assertStringNotContainsString('Marca beta para los contratos.', $captured);

        $this->get('/chat')
            ->assertOk()
            ->assertSee('Marca alfa para el repositorio de pedidos.', false)
            ->assertSee('aria-label="Adjuntar imagen"', false)
            ->assertSee('aria-label="Dictar"', false)
            ->assertDontSee('Cargar', false)
            ->assertDontSee('name="context_ids[]"', false);
    }

    public function test_an_uploaded_image_sends_the_read_text_to_the_model(): void
    {
        Http::preventStrayRequests();
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $this->assertStringStartsWith('http://127.0.0.1:11434/', $request->url());

            if (str_ends_with($request->url(), '/api/chat')) {
                $captured = json_encode($request->data());

                return Http::response([
                    'message' => ['content' => 'No veo la imagen.'],
                ]);
            }

            return Http::response(['models' => []]);
        });

        $this->post('/c', [
            'prompt' => 'Organiza estos nombres de la A a la Z.',
            'image' => $this->namesImage(),
        ])->assertRedirect();

        $conversation = Conversation::query()->first();
        $this->assertNotNull($conversation);
        $message = $conversation->messages()->where('role', 'user')->first();
        $this->assertNotNull($message);
        $this->assertIsString($message->image_path);

        $this->assertIsString($captured);
        $this->assertStringContainsString('ANA', $captured);
        $this->assertStringContainsString('LUIS', $captured);
        $this->assertStringContainsString('MARTA', $captured);
        $this->assertStringContainsString('Organiza estos nombres de la A a la Z.', $captured);
        $this->assertStringNotContainsString('chat-images/', $captured);
        $this->assertStringNotContainsString('iVBOR', $captured);

        $this->get('/c/'.$conversation->id)
            ->assertOk()
            ->assertSee('Organiza estos nombres de la A a la Z.', false)
            ->assertSee(route('chat.image', ['conversation' => $conversation->id, 'message' => $message->id], false), false);

        $this->get(route('chat.image', ['conversation' => $conversation->id, 'message' => $message->id]))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_a_file_that_is_not_an_image_is_rejected_without_calling_the_model(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->post('/c', [
            'prompt' => 'Describe esta captura.',
            'image' => UploadedFile::fake()->create('notas.txt', 10, 'text/plain'),
        ])->assertRedirect()
            ->assertSessionHasErrors('image');

        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_a_conversation_can_be_deleted_from_the_list(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/*' => Http::response(['models' => []], 200),
        ]);
        Storage::fake('local');

        $keep = new Conversation;
        $keep->save();
        $drop = new Conversation;
        $drop->save();
        Message::query()->create([
            'conversation_id' => $keep->id,
            'role' => 'user',
            'content' => 'Conservar esta sesión',
        ]);
        Message::query()->create([
            'conversation_id' => $drop->id,
            'role' => 'user',
            'content' => 'Borrar esta sesión',
            'image_path' => 'chat-images/11111111-1111-1111-1111-111111111111.png',
        ]);
        Storage::disk('local')->put('chat-images/11111111-1111-1111-1111-111111111111.png', 'png');

        $this->get('/chat')
            ->assertOk()
            ->assertSee('aria-label="Opciones de la conversación"', false)
            ->assertSee('Eliminar', false)
            ->assertSee('Borrar esta sesión', false);

        $this->from('/c/'.$drop->id)
            ->delete('/c/'.$drop->id)
            ->assertRedirect('/chat');

        $this->assertDatabaseMissing('conversations', ['id' => $drop->id]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $drop->id]);
        Storage::disk('local')->assertMissing('chat-images/11111111-1111-1111-1111-111111111111.png');

        $this->get('/chat')
            ->assertOk()
            ->assertSee('Conservar esta sesión', false)
            ->assertDontSee('Borrar esta sesión', false);

        $this->delete('/c/'.$drop->id)->assertNotFound();
    }

    private function namesImage(): UploadedFile
    {
        $image = imagecreatetruecolor(480, 220);
        $this->assertNotFalse($image);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 480, 220, $white);
        $font = 'C:\\Windows\\Fonts\\arial.ttf';
        imagettftext($image, 32, 0, 30, 60, $black, $font, 'ANA');
        imagettftext($image, 32, 0, 30, 120, $black, $font, 'LUIS');
        imagettftext($image, 32, 0, 30, 180, $black, $font, 'MARTA');
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wcodex-names.png';
        imagepng($image, $path);

        return new UploadedFile($path, 'nombres.png', 'image/png', null, true);
    }
}
