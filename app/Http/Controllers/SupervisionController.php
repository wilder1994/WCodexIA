<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

final class SupervisionController extends Controller
{
    public function index(): View
    {
        return view('supervision.index');
    }

    public function data(): JsonResponse
    {
        return response()->json($this->panel('get', '/api/versions'));
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $status = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
        ])['status'];

        return response()->json($this->panel('post', '/api/versions/'.rawurlencode($id).'/decision', [
            'status' => $status,
        ]));
    }

    /**
     * @param  array<string, string>  $body
     * @return array<string, mixed>
     */
    private function panel(string $method, string $path, array $body = []): array
    {
        $base = rtrim((string) config('wcodex.panel_url'), '/');

        try {
            $pending = Http::timeout(8)->acceptJson();
            $response = $method === 'post'
                ? $pending->post($base.$path, $body)
                : $pending->get($base.$path);
        } catch (ConnectionException) {
            return ['error' => 'El panel de versiones no responde.'];
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : ['error' => 'Respuesta inválida del panel.'];
    }
}
