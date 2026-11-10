<?php

use App\Portal\Lang;
use Illuminate\Support\HtmlString;

/* The portal's small words for views and services. Times are ISO strings in UTC. */

function iso_now(): string
{
    return gmdate('Y-m-d\TH:i:s.v\Z');
}

function iso_later(int $seconds): string
{
    return gmdate('Y-m-d\TH:i:s.v\Z', time() + $seconds);
}

function portal_today(): string
{
    return gmdate('Y-m-d');
}

function portal_sha($v): string
{
    return hash('sha256', (string) $v);
}

function portal_id(string $prefix, int $bytes = 5): string
{
    return $prefix.'-'.strtoupper(bin2hex(random_bytes($bytes)));
}

function days_between($a, $b): int
{
    return (int) round((strtotime(substr((string) $b, 0, 10).' 00:00:00 UTC') - strtotime(substr((string) $a, 0, 10).' 00:00:00 UTC')) / 86400);
}

function add_days($d, int $n): string
{
    return gmdate('Y-m-d', strtotime(substr((string) $d, 0, 10).' 00:00:00 UTC') + $n * 86400);
}

function json_of($v, $fallback = null)
{
    if ($v === null || $v === '') {
        return $fallback;
    }
    $out = json_decode((string) $v, true);

    return $out === null ? $fallback : $out;
}

function t(?string $text, array $vars = []): string
{
    return Lang::t($text, $vars);
}

function plural(int $n, string $one, string $many, array $vars = []): string
{
    return t($n === 1 ? $one : $many, ['n' => $n] + $vars);
}

function money($n, ?string $cur = null): string
{
    if ($n === null || $n === '' || ! is_numeric($n)) {
        return '—';
    }
    $n = (float) $n;
    $decimals = fmod($n, 1.0) !== 0.0 ? 2 : 0;
    $v = Lang::current() === 'fr' ? number_format($n, $decimals, ',', ' ') : number_format($n, $decimals, '.', ',');

    return $cur ? "{$v} {$cur}" : $v;
}

function day($v, bool $noYear = false): string
{
    if (! $v) {
        return '—';
    }
    $ts = strtotime((string) $v);
    if ($ts === false) {
        return (string) $v;
    }

    return Lang::date($ts, $noYear ? 'd MMM' : 'd MMM y');
}

function day_time($v): string
{
    if (! $v) {
        return '—';
    }
    $ts = strtotime(str_replace(' ', 'T', (string) $v));

    return $ts === false ? (string) $v : Lang::date($ts, 'd MMM, HH:mm');
}

function clock_of($v): string
{
    $ts = $v ? strtotime((string) $v) : false;

    return $ts === false ? '' : gmdate('H:i', $ts);
}

function long_date(): string
{
    return Lang::date(time(), 'EEEE d MMMM y');
}

function ago($v): string
{
    if (! $v) {
        return '';
    }
    $s = time() - (int) strtotime(str_replace(' ', 'T', (string) $v));
    if ($s < 60) {
        return t('just now');
    }
    if ($s < 3600) {
        return t('{n} min ago', ['n' => round($s / 60)]);
    }
    if ($s < 86400) {
        return t('{n} h ago', ['n' => round($s / 3600)]);
    }

    return day($v);
}

function initials(?string $s): string
{
    $words = array_slice(array_values(array_filter(preg_split('/\s+/u', (string) ($s ?: '?')))), 0, 2);

    return mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), $words)));
}

function icon(string $n, int $s = 18, string $class = '', string $style = ''): HtmlString
{
    static $paths = null;
    $paths ??= require __DIR__.'/icons.php';
    $d = $paths[$n] ?? $paths['info'];

    return new HtmlString('<svg class="ic '.e($class).'" width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" aria-hidden="true"'.($style ? ' style="'.e($style).'"' : '').'><path d="'.$d.'"/></svg>');
}

function pill(?string $text, string $tone = 'muted', bool $dot = true, ?string $title = null): HtmlString
{
    return new HtmlString('<span class="pill t-'.e($tone).'"'.($title ? ' title="'.e($title).'"' : '').'>'.($dot ? '<span class="d"></span>' : '').e($text).'</span>');
}

function claim_pill(?string $status): HtmlString
{
    $s = \App\Portal\Vocab::claimStage($status);

    return pill(t($s['label']), $s['tone'], true, t($s['desc']));
}

function pa_pill(?string $status, bool $partly = false): HtmlString
{
    $s = \App\Portal\Vocab::paStage($status, $partly);

    return pill(t($s['label']), $s['tone']);
}

function amt($n, ?string $cur, ?string $sub = null): HtmlString
{
    return new HtmlString('<span class="amt num">'.e(money($n, $cur)).($sub ? '<small>'.e(t($sub)).'</small>' : '').'</span>');
}

function me(): \App\Portal\Ctx
{
    return \App\Portal\Ctx::current();
}

function can(string $capability): bool
{
    return me()->can($capability);
}

/** a toast on the next page; tone is green, teal, amber or red */
function toast(string $text, string $tone = 'green'): void
{
    session()->flash('toast', ['text' => $text, 'tone' => $tone]);
}
