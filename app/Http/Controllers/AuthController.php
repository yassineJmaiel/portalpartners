<?php

namespace App\Http\Controllers;

use App\Portal\Auth;
use App\Portal\Lang;
use App\Portal\PortalError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    private function enter(Request $r, array $out)
    {
        $s = $out['session'];
        Cookie::queue(Cookie::make(Auth::COOKIE, $s['token'], $s['remember'] ? 7 * 1440 : 0, null, null, $r->secure(), true, false, 'Lax'));
        if (! empty($out['device'])) {
            Cookie::queue(Cookie::make(Auth::DEVICE_COOKIE, $out['device'], 30 * 1440, null, null, $r->secure(), true, false, 'Lax'));
        }
        /* a language picked on the sign-in page becomes the person's saved preference */
        $picked = $r->cookie('vpp_lang');
        if ($picked && in_array($picked, Lang::AVAILABLE, true) && $picked !== $out['user']->language) {
            DB::table('users')->where('id', $out['user']->id)->update(['language' => $picked]);
        }
        $r->session()->forget('challenge');
        $r->session()->regenerate();

        return redirect()->intended('/');
    }

    public function show(Request $r)
    {
        if ($r->query('restart')) {
            $r->session()->forget('challenge');
        }
        if ($c = $r->session()->get('challenge')) {
            return view('auth.code', ['challenge' => $c]);
        }
        $demo = (bool) config('portal.demo');

        return view('auth.login', ['demo' => $demo, 'demoPassword' => $demo ? config('portal.demo_password') : null,
            'demoAccounts' => $demo ? DB::table('users as u')->join('organisations as o', 'o.id', '=', 'u.org_id')->where('u.status', 'Active')
                ->orderByRaw("CASE o.id WHEN 'ORG-CMC' THEN 0 ELSE 1 END")->orderBy('o.id')->orderBy('u.id')->limit(10)->get(['u.email', 'u.name', 'u.role', 'o.name as org']) : []]);
    }

    public function login(Request $r)
    {
        $out = Auth::login((string) $r->input('email'), (string) $r->input('password'), $r->boolean('remember'), $r);
        if ($out['step'] === 'done') {
            return $this->enter($r, $out);
        }
        $r->session()->put('challenge', $out);

        return redirect('/login');
    }

    public function verify(Request $r)
    {
        $c = $r->session()->get('challenge') ?? throw new PortalError(400, 'NO_CHALLENGE', 'This sign-in has expired. Start again.');
        $code = implode('', (array) $r->input('code', []));
        try {
            $out = Auth::verify($c['challenge'], $code, $r->boolean('trust'), $r);
        } catch (PortalError $e) {
            if (in_array($e->errorCode, ['NO_CHALLENGE', 'TOO_MANY'], true)) {
                $r->session()->forget('challenge');
            }
            throw $e;
        }

        return $this->enter($r, $out);
    }

    public function resend(Request $r)
    {
        $c = $r->session()->get('challenge') ?? throw new PortalError(400, 'NO_CHALLENGE', 'This sign-in has expired. Start again.');
        $r->session()->put('challenge', ['step' => 'code'] + Auth::resend($c['challenge']));

        return redirect('/login');
    }

    public function forgotForm()
    {
        return view('auth.forgot');
    }

    public function forgot(Request $r)
    {
        Auth::forgot((string) $r->input('email'));

        return redirect('/forgot')->with('sent', true);
    }

    public function logout(Request $r)
    {
        Auth::logout($r->cookie(Auth::COOKIE));
        Cookie::queue(Cookie::forget(Auth::COOKIE));
        $r->session()->invalidate();

        return redirect('/login');
    }

    public function language(Request $r)
    {
        $lang = (string) $r->input('language');
        Lang::remember($lang);
        $s = Auth::sessionFrom($r->cookie(Auth::COOKIE));
        if ($s && in_array($lang, Lang::AVAILABLE, true)) {
            DB::table('users')->where('id', $s['user']->id)->update(['language' => $lang]);
        }

        return redirect()->back();
    }

    private function invite(string $token): ?object
    {
        return DB::table('invites as i')->join('users as u', 'u.id', '=', 'i.user_id')->where('i.id', portal_sha($token))->where('i.used', false)->where('i.expires_at', '>', iso_now())
            ->first(['i.id', 'i.user_id', 'i.kind', 'u.email', 'u.name']);
    }

    public function acceptForm(string $token)
    {
        return view('auth.accept', ['invite' => $this->invite($token)]);
    }

    public function accept(Request $r, string $token)
    {
        $inv = $this->invite($token) ?? throw new PortalError(404, 'INVALID_LINK', 'This link has expired or was already used.');
        $pw = (string) $r->input('password');
        Auth::checkPassword($pw);
        if ($pw !== (string) $r->input('password_confirmation')) {
            throw new PortalError(400, 'MISMATCH', 'The two passwords are different.');
        }
        DB::transaction(function () use ($inv, $pw) {
            DB::table('users')->where('id', $inv->user_id)->update(['password_hash' => Hash::make($pw), 'status' => 'Active', 'failed_count' => 0, 'locked_until' => null]);
            DB::table('invites')->where('id', $inv->id)->update(['used' => true]);
        });
        toast(t('Your password is set. You can sign in now.'));

        return redirect('/login');
    }
}
