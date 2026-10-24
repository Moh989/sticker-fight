<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Design;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function form(): View
    {
        return view('store.login');
    }

    public function login(Request $r): RedirectResponse
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($d)) {
            throw ValidationException::withMessages(['email' => 'البريد أو كلمة المرور غير صحيحين.']);
        }$r->session()->regenerate();
        Design::where('guest_session_id', session('guest_id'))->whereNull('user_id')->update(['user_id' => auth()->id()]);
        Asset::where('guest_session_id', session('guest_id'))->whereNull('user_id')->update(['user_id' => auth()->id()]);

        return redirect(in_array(auth()->user()->role, ['admin', 'orders', 'production']) ? '/admin' : '/saved');
    }

    public function register(Request $r): RedirectResponse
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:200|unique:users', 'password' => 'required|string|min:12|confirmed']);
        $user = User::create($d);
        Auth::login($user);
        $r->session()->regenerate();
        Design::where('guest_session_id', session('guest_id'))->whereNull('user_id')->update(['user_id' => $user->id]);
        Asset::where('guest_session_id', session('guest_id'))->whereNull('user_id')->update(['user_id' => $user->id]);

        return redirect('/saved')->with('success', 'تم إنشاء الحساب وربط تصاميم هذه الجلسة.');
    }

    public function logout(Request $r): RedirectResponse
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        if ($r->boolean('new_workspace')) {
            return redirect('/editor')->with('success', 'بدأت مساحة زائر جديدة.');
        }

        return redirect('/');
    }
}
