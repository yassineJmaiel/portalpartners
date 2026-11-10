<?php

namespace App\Portal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/* Who is asking: a signed-in person, the capability of their role, and the provider
   selected in the session. The scope sent to the CRM is that one provider, so a request
   can never reach another provider's records, and the CRM checks the same scope again.
   "All branches" is a combined, read-only view for organisations with several. */
class Ctx
{
    public const ALL = 'ALL';

    private static ?self $current = null;

    private ?array $profile = null;

    public function __construct(public object $user, public object $session, public array $providers, public ?string $providerId, public bool $all, public array $scope, public ?string $ip, public ?string $device)
    {
    }

    public static function set(?self $ctx): void
    {
        self::$current = $ctx;
    }

    public static function current(): self
    {
        return self::$current ?? throw new PortalError(401, 'SIGNED_OUT', 'Your session has ended. Sign in again.');
    }

    public static function has(): bool
    {
        return self::$current !== null;
    }

    public static function from(object $session, object $user, Request $r): self
    {
        $providers = DB::table('org_providers')->where('org_id', $user->org_id)->orderBy('provider_id')->get(['provider_id as id', 'label'])->all();
        $ids = array_column($providers, 'id');
        $all = $session->provider_id === self::ALL && count($providers) > 1;
        $providerId = $all ? null : $session->provider_id;
        if (! $all && ! in_array($providerId, $ids, true)) {
            $providerId = $ids[0] ?? null;
        }

        return new self($user, $session, $providers, $providerId, $all, $all ? $ids : array_filter([$providerId]), $r->ip(), mb_substr((string) $r->userAgent(), 0, 200));
    }

    public function roleCan(string $cap): bool
    {
        return Vocab::roleCan($this->user->role, $cap);
    }

    /** what the screens may offer: in the combined view, the actions that need one branch are hidden */
    public function can(string $cap): bool
    {
        return $this->roleCan($cap) && ! ($this->all && in_array($cap, Vocab::NEEDS_ONE_BRANCH, true));
    }

    public function need(string $cap): void
    {
        if (! $this->roleCan($cap)) {
            throw new PortalError(403, 'NOT_ALLOWED', 'Your role ('.$this->user->role.') cannot '.mb_strtolower(Vocab::CAPABILITIES[$cap] ?? $cap).'.');
        }
    }

    /** anything that creates or changes a record happens in one branch */
    public function single(): void
    {
        if ($this->all) {
            throw new PortalError(409, 'CHOOSE_BRANCH', 'Choose one branch in the menu to do this. "All branches" shows your records together; it does not submit or change anything.');
        }
    }

    public function crmOpts(array $extra = []): array
    {
        return ['scope' => $this->scope, 'user' => "{$this->user->name} <{$this->user->email}>"] + $extra;
    }

    /** who, which provider, what, when, from where, and (for a change) before → after */
    public function audit(string $action, ?string $subject = null, ?array $detail = null, array $change = []): void
    {
        DB::table('audit')->insert(['at' => iso_now(), 'user_id' => $this->user->id, 'org_id' => $this->user->org_id, 'provider_id' => $this->providerId, 'action' => $action,
            'subject' => $subject, 'detail' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, 'ip' => $this->ip, 'device' => $this->device,
            'before_state' => isset($change['before']) ? json_encode($change['before'], JSON_UNESCAPED_UNICODE) : null,
            'after_state' => isset($change['after']) ? json_encode($change['after'], JSON_UNESCAPED_UNICODE) : null]);
    }

    public function rateLimit(string $key, int $perHour): void
    {
        $since = (time() - 3600) * 1000;
        DB::table('rate_limits')->where('at', '<', $since)->delete();
        if (DB::table('rate_limits')->where('key', $key)->where('at', '>=', $since)->count() >= $perHour) {
            throw new PortalError(429, 'RATE_LIMIT', "You have reached {$perHour} look-ups this hour. This limit protects member data; it resets within the hour.");
        }
        DB::table('rate_limits')->insert(['key' => $key, 'at' => (int) (microtime(true) * 1000)]);
    }

    public function organisation(): object
    {
        return DB::table('organisations')->where('id', $this->user->org_id)->first();
    }

    public function providerLabel(): string
    {
        foreach ($this->providers as $p) {
            if ($p->id === $this->providerId) {
                return (string) ($p->label ?: $p->id);
            }
        }

        return (string) $this->providerId;
    }

    public function branchName(?string $id): string
    {
        foreach ($this->providers as $p) {
            if ($p->id === $id) {
                return (string) ($p->label ?: $p->id);
            }
        }

        return (string) $id;
    }

    /** the provider as the CRM records it; in the combined view, the organisation with each branch's own standing */
    public function profile(): array
    {
        if ($this->profile !== null) {
            return $this->profile;
        }
        $org = $this->organisation();
        if (! $this->all) {
            try {
                return $this->profile = Crm::get("/v1/providers/{$this->providerId}", $this->crmOpts());
            } catch (CrmError $e) {
                return $this->profile = ['id' => $this->providerId, 'name' => $org->name, 'unreachable' => $e->getMessage()];
            }
        }
        $branches = [];
        foreach ($this->providers as $p) {
            try {
                $x = Crm::get("/v1/providers/{$p->id}", $this->crmOpts());
                $branches[] = ['id' => $p->id, 'label' => $p->label, 'name' => $x['name'] ?? null, 'standing' => $x['standing'] ?? null];
            } catch (CrmError $e) {
                $branches[] = ['id' => $p->id, 'label' => $p->label, 'unreachable' => $e->getMessage()];
            }
        }

        return $this->profile = ['id' => self::ALL, 'name' => $org->name, 'all' => true, 'branches' => $branches,
            'standing' => ['status' => 'All branches', 'networkStatus' => count($branches).' branches', 'canSubmit' => false, 'combined' => true,
                'reason' => 'You are looking at all branches together. Choose one branch in the menu to submit or change anything.']];
    }

    public function unread(): int
    {
        $q = DB::table('notifications')->where('org_id', $this->user->org_id)->whereNull('read_at');
        $this->scopeNotifications($q);

        return $q->count();
    }

    public function scopeNotifications($q)
    {
        return $q->where(fn ($w) => $w->whereNull('provider_id')->orWhereIn('provider_id', $this->scope))
            ->where(fn ($w) => $w->whereNull('user_id')->orWhere('user_id', $this->user->id));
    }
}
