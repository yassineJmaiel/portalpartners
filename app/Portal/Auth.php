<?php

namespace App\Portal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/* Password, second factor, sessions, trusted devices:
     · 5 wrong passwords lock the account for 15 minutes;
     · Suspended and Invited users cannot sign in;
     · roles that handle money or the team, and anyone who turned it on, enter a 6-digit code;
     · a code lasts 5 minutes, allows 5 tries, and can be resent after 30 s;
     · "trust this device" skips the code for 30 days;
     · a session ends after 30 minutes without activity, or 8 hours in all;
       "keep me signed in" extends that to 7 days.
   The code goes through the one send seam; with no e-mail transport it is recorded but
   not transmitted, and in demo mode the sign-in screen shows it, saying plainly that it does. */
class Auth
{
    public const COOKIE = 'vpp_session';

    public const DEVICE_COOKIE = 'vpp_device';

    private const LOCK_AFTER = 5;

    private const LOCK_MINUTES = 15;

    private const CODE_MINUTES = 5;

    private const CODE_TRIES = 5;

    public const RESEND_SECONDS = 30;

    private const IDLE_MINUTES = 30;

    private const SESSION_HOURS = 8;

    private const REMEMBER_DAYS = 7;

    private const TRUST_DAYS = 30;

    private static function log(?object $user, ?string $email, Request $r, string $outcome): void
    {
        DB::table('signins')->insert(['user_id' => $user->id ?? null, 'email' => $email, 'at' => iso_now(), 'ip' => $r->ip(), 'ua' => mb_substr((string) $r->userAgent(), 0, 200), 'outcome' => $outcome]);
    }

    private static function fail(int $status, string $code, string $message): never
    {
        throw new PortalError($status, $code, $message);
    }

    private static function needsCode(object $user): bool
    {
        return (bool) $user->mfa || in_array($user->role, Vocab::MFA_REQUIRED, true);
    }

    public static function startSession(object $user, Request $r, bool $remember): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $first = DB::table('org_providers')->where('org_id', $user->org_id)->orderBy('provider_id')->value('provider_id');
        $expires = $remember ? iso_later(self::REMEMBER_DAYS * 86400) : iso_later(self::SESSION_HOURS * 3600);
        DB::table('portal_sessions')->insert(['id' => portal_sha($token), 'user_id' => $user->id, 'provider_id' => $first, 'created_at' => iso_now(), 'expires_at' => $expires,
            'last_seen_at' => iso_now(), 'ip' => $r->ip(), 'ua' => mb_substr((string) $r->userAgent(), 0, 200), 'remember' => $remember]);
        DB::table('users')->where('id', $user->id)->update(['last_login_at' => iso_now(), 'failed_count' => 0, 'locked_until' => null]);

        return compact('token', 'remember');
    }

    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 2).str_repeat('•', max(1, mb_strlen($name) - 2))."@{$domain}";
    }

    private static function sendCode(object $user, bool $remember): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $id = bin2hex(random_bytes(16));
        DB::table('challenges')->insert(['id' => $id, 'user_id' => $user->id, 'code_hash' => portal_sha("{$id}:{$code}"), 'expires_at' => iso_later(self::CODE_MINUTES * 60), 'created_at' => iso_now(), 'remember' => $remember]);
        $sent = Deliver::send(['channel' => 'email', 'recipient' => $user->email, 'subject' => 'Your VSahha Provider Portal sign-in code',
            'body' => "Your sign-in code is {$code}. It expires in ".self::CODE_MINUTES.' minutes. If you did not try to sign in, tell your portal administrator.']);

        return ['challenge' => $id, 'expiresInSeconds' => self::CODE_MINUTES * 60, 'sentTo' => self::maskEmail($user->email), 'transmitted' => $sent['transmitted'],
            'demoCode' => config('portal.demo') ? $code : null];
    }

    public static function login(string $email, string $password, bool $remember, Request $r): array
    {
        $mail = mb_strtolower(trim($email));
        $user = DB::table('users')->where('email', $mail)->first();
        if (! $user) {
            self::log(null, $mail, $r, 'unknown email');
            self::fail(401, 'BAD_CREDENTIALS', 'The e-mail or password is not right.');
        }
        if ($user->locked_until && $user->locked_until > iso_now()) {
            self::log($user, $mail, $r, 'locked');
            self::fail(423, 'LOCKED', 'Too many wrong passwords. Try again after '.gmdate('H:i', strtotime($user->locked_until)).'.');
        }
        if (! $user->password_hash || ! Hash::check($password, $user->password_hash)) {
            $fails = $user->failed_count + 1;
            $lock = $fails >= self::LOCK_AFTER ? iso_later(self::LOCK_MINUTES * 60) : null;
            DB::table('users')->where('id', $user->id)->update(['failed_count' => $lock ? 0 : $fails, 'locked_until' => $lock]);
            self::log($user, $mail, $r, $lock ? 'locked after wrong passwords' : 'wrong password');
            $left = self::LOCK_AFTER - $fails;
            if ($lock) {
                self::fail(423, 'LOCKED', 'Too many wrong passwords. The account is locked for '.self::LOCK_MINUTES.' minutes.');
            }
            self::fail(401, 'BAD_CREDENTIALS', 'The e-mail or password is not right.'.($left <= 2 ? " {$left} attempt".($left === 1 ? '' : 's').' left before the account locks.' : ''));
        }
        if ($user->status !== 'Active') {
            self::log($user, $mail, $r, "refused ({$user->status})");
            self::fail(403, 'NOT_ACTIVE', $user->status === 'Invited' ? 'This invitation has not been accepted yet. Use the link in the invitation e-mail.' : 'This account is suspended. Ask your portal administrator.');
        }
        if (self::needsCode($user)) {
            $device = (string) $r->cookie(self::DEVICE_COOKIE);
            $trusted = $device !== '' && DB::table('trusted_devices')->where('id', portal_sha($device))->where('user_id', $user->id)->where('expires_at', '>', iso_now())->exists();
            if (! $trusted) {
                self::log($user, $mail, $r, 'password ok, code sent');

                return ['step' => 'code'] + self::sendCode($user, $remember);
            }
        }
        self::log($user, $mail, $r, 'signed in');

        return ['step' => 'done', 'session' => self::startSession($user, $r, $remember), 'user' => $user];
    }

    public static function verify(string $challenge, string $code, bool $trustDevice, Request $r): array
    {
        $c = DB::table('challenges')->where('id', $challenge)->first();
        if (! $c || $c->used) {
            self::fail(400, 'NO_CHALLENGE', 'This sign-in has expired. Start again.');
        }
        $user = DB::table('users')->where('id', $c->user_id)->first();
        if ($c->expires_at < iso_now()) {
            self::fail(400, 'EXPIRED', 'The code has expired. Ask for a new one.');
        }
        if ($c->tries >= self::CODE_TRIES) {
            self::fail(429, 'TOO_MANY', 'Too many wrong codes. Start the sign-in again.');
        }
        if (! hash_equals($c->code_hash, portal_sha("{$c->id}:".trim($code)))) {
            DB::table('challenges')->where('id', $c->id)->increment('tries');
            self::log($user, $user->email, $r, 'wrong code');
            $left = self::CODE_TRIES - $c->tries - 1;
            self::fail(401, 'BAD_CODE', $left > 0 ? "That code is not right. {$left} attempt".($left === 1 ? '' : 's').' left.' : 'Too many wrong codes. Start the sign-in again.');
        }
        DB::table('challenges')->where('id', $c->id)->update(['used' => true]);
        $device = null;
        if ($trustDevice) {
            $device = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            DB::table('trusted_devices')->insert(['id' => portal_sha($device), 'user_id' => $user->id, 'expires_at' => iso_later(self::TRUST_DAYS * 86400), 'created_at' => iso_now()]);
        }
        self::log($user, $user->email, $r, 'signed in with code');

        return ['session' => self::startSession($user, $r, (bool) $c->remember), 'device' => $device, 'user' => $user];
    }

    public static function resend(string $challenge): array
    {
        $c = DB::table('challenges')->where('id', $challenge)->first();
        if (! $c || $c->used) {
            self::fail(400, 'NO_CHALLENGE', 'This sign-in has expired. Start again.');
        }
        $wait = (int) ceil(strtotime($c->resent_at ?: $c->created_at) + self::RESEND_SECONDS - microtime(true));
        if ($wait > 0) {
            self::fail(429, 'WAIT', "You can ask for a new code in {$wait} s.");
        }
        DB::table('challenges')->where('id', $c->id)->update(['used' => true]);

        return self::sendCode(DB::table('users')->where('id', $c->user_id)->first(), (bool) $c->remember);
    }

    /** resolve the session cookie and slide the idle window */
    public static function sessionFrom(?string $token): ?array
    {
        if (! $token) {
            return null;
        }
        $s = DB::table('portal_sessions')->where('id', portal_sha($token))->first();
        if (! $s) {
            return null;
        }
        $idleLimit = gmdate('Y-m-d\TH:i:s.v\Z', time() - self::IDLE_MINUTES * 60);
        if ($s->expires_at < iso_now() || (! $s->remember && $s->last_seen_at < $idleLimit)) {
            DB::table('portal_sessions')->where('id', $s->id)->delete();

            return null;
        }
        $user = DB::table('users')->where('id', $s->user_id)->first();
        if (! $user || $user->status !== 'Active') {
            return null;
        }
        DB::table('portal_sessions')->where('id', $s->id)->update(['last_seen_at' => iso_now()]);

        return ['session' => $s, 'user' => $user];
    }

    public static function logout(?string $token): void
    {
        if ($token) {
            DB::table('portal_sessions')->where('id', portal_sha($token))->delete();
        }
    }

    /** never says whether the address exists */
    public static function forgot(string $email): void
    {
        $user = DB::table('users')->where('email', mb_strtolower(trim($email)))->first();
        if ($user && $user->status === 'Active') {
            Deliver::send(['channel' => 'email', 'recipient' => $user->email, 'subject' => 'Reset your VSahha Provider Portal password',
                'body' => 'Your portal administrator can reset your password from Team. If you did not ask for this, ignore this message.']);
        }
    }

    public static function checkPassword(string $pw): void
    {
        if (strlen($pw) < 10 || ! preg_match('/[A-Za-z]/', $pw) || ! preg_match('/\d/', $pw)) {
            self::fail(400, 'WEAK_PASSWORD', 'Use at least 10 characters, with letters and numbers.');
        }
    }
}
