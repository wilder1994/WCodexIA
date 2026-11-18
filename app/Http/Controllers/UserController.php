<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'users' => User::query()->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateAccount($request);
        $data['role'] = 'admin';
        $data['can_chat'] = $request->boolean('can_chat');
        $data['can_versions'] = $request->boolean('can_versions');

        User::query()->create($data);

        return redirect()->route('users.index');
    }

    public function edit(User $user): View
    {
        return view('users.edit', ['account' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validateAccount($request, $user, requirePassword: false);

        if ($user->role !== 'superadmin') {
            $data['can_chat'] = $request->boolean('can_chat');
            $data['can_versions'] = $request->boolean('can_versions');
        }

        if (! $request->filled('password')) {
            unset($data['password']);
        }

        $user->update($data);

        return redirect()->route('users.index');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()?->is($user)) {
            return back()->withErrors(['user' => 'No puedes borrar tu propia cuenta.']);
        }

        if ($user->role === 'superadmin' && User::query()->where('role', 'superadmin')->count() <= 1) {
            return back()->withErrors(['user' => 'Tiene que quedar al menos un superadmin.']);
        }

        $user->delete();

        return redirect()->route('users.index');
    }

    /**
     * @return array{name: string, email: string, password?: string}
     */
    private function validateAccount(Request $request, ?User $user = null, bool $requirePassword = true): array
    {
        $password = $requirePassword ? ['required', 'string', 'min:8'] : ['nullable', 'string', 'min:8'];

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => $password,
        ]);
    }
}
