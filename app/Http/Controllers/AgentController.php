<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Agent\Services\ApprovedCommandRunner;
use App\Domain\Agent\Services\ProjectAgent;
use App\Domain\Assistant\Exceptions\LlmUnavailableException;
use App\Domain\Security\Exceptions\WorkspaceBoundaryException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AgentController extends Controller
{
    public function index(Request $request): View
    {
        return view('agent.index', [
            'project' => $request->session()->get('agent.project'),
            'proposal' => $request->session()->get('agent.proposal'),
            'model' => (string) config('wcodex.ollama.model'),
        ]);
    }

    public function propose(Request $request, ProjectAgent $agent): RedirectResponse
    {
        $data = $request->validate([
            'project' => ['required', 'string', 'max:500'],
            'instruction' => ['required', 'string', 'min:1', 'max:4000'],
            'paths' => ['nullable', 'string', 'max:2000'],
        ]);

        $paths = $this->paths((string) ($data['paths'] ?? ''));

        if (count($paths) > 8) {
            return back()->withInput()->withErrors([
                'paths' => 'Indica hasta ocho archivos.',
            ]);
        }

        try {
            $proposal = $agent->propose($data['project'], $data['instruction'], $paths);
        } catch (WorkspaceBoundaryException|LlmUnavailableException $exception) {
            return back()->withInput()->withErrors([
                'project' => $exception->getMessage(),
            ]);
        }

        $request->session()->put('agent.project', $proposal['root']);
        $request->session()->put('agent.proposal', $proposal);

        return redirect()->route('agent.index');
    }

    public function apply(Request $request, ProjectAgent $agent): RedirectResponse
    {
        $proposal = $this->pending($request);

        if ($proposal === null) {
            return back()->withErrors([
                'apply' => 'No hay una propuesta pendiente.',
            ]);
        }

        if (($proposal['applied'] ?? false) === true) {
            return back()->withErrors([
                'apply' => 'Esos cambios ya se escribieron.',
            ]);
        }

        try {
            $proposal = $agent->apply($proposal);
        } catch (WorkspaceBoundaryException $exception) {
            return back()->withErrors([
                'apply' => $exception->getMessage(),
            ]);
        }

        $request->session()->put('agent.proposal', $proposal);

        return redirect()->route('agent.index');
    }

    public function run(Request $request, ApprovedCommandRunner $runner): RedirectResponse
    {
        $proposal = $this->pending($request);
        $command = is_array($proposal) ? ($proposal['command'] ?? null) : null;

        if (! is_array($proposal) || ! is_string($command) || ! is_string($proposal['root'] ?? null)) {
            return back()->withErrors([
                'command' => 'No hay un comando pendiente de aprobación.',
            ]);
        }

        try {
            $result = $runner->run($proposal['root'], $command);
        } catch (WorkspaceBoundaryException $exception) {
            return back()->withErrors([
                'command' => $exception->getMessage(),
            ]);
        }

        $proposal['output'] = $result->output;
        $proposal['command'] = null;
        $request->session()->put('agent.proposal', $proposal);

        return redirect()->route('agent.index');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pending(Request $request): ?array
    {
        $id = (string) $request->validate([
            'proposal_id' => ['required', 'uuid'],
        ])['proposal_id'];
        $proposal = $request->session()->get('agent.proposal');

        if (! is_array($proposal) || ($proposal['id'] ?? '') !== $id) {
            return null;
        }

        return $proposal;
    }

    /**
     * @return list<string>
     */
    private function paths(string $raw): array
    {
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $path): string => trim($path), $parts),
            static fn (string $path): bool => $path !== '',
        ));
    }
}
