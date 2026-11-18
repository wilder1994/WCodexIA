<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class HomeController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->role === 'superadmin') {
            return view('home.superadmin', ['user' => $user]);
        }

        if ($user->allows('chat') || $user->allows('versions')) {
            return view('home.access', ['user' => $user]);
        }

        return view('home.admin', ['user' => $user]);
    }
}
