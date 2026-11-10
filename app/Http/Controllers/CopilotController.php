<?php

namespace App\Http\Controllers;

use App\Portal\Copilot;
use App\Portal\Ctx;
use App\Portal\Retention;
use Illuminate\Http\Request;

/* The conversation lives in this sign-in's session and goes with it: a new sign-in starts empty. */
class CopilotController extends Controller
{
    private function key(Ctx $ctx): string
    {
        return "copilot.{$ctx->session->id}.{$ctx->providerId}";
    }

    public function index()
    {
        $ctx = me();
        $ctx->single();

        return view('copilot.index', ['turns' => session($this->key($ctx), [])]);
    }

    public function ask(Request $r)
    {
        $ctx = me();
        $ctx->single();
        $ctx->rateLimit("copilot:{$ctx->user->id}", (int) Retention::config('copilotRateLimitPerHour', 200));
        $turns = session($this->key($ctx), []);
        $about = null;
        foreach (array_reverse($turns) as $x) {
            if (! empty($x['answer']['citations'])) {
                $about = $x['answer']['citations'][0]['id'];
                break;
            }
        }
        $question = trim((string) $r->input('question'));
        $turns[] = ['question' => $question, 'answer' => (new Copilot($ctx))->ask($question, $about)];
        session([$this->key($ctx) => array_slice($turns, -30)]);

        return redirect('/copilot#end');
    }

    public function clear()
    {
        session()->forget($this->key(me()));

        return redirect('/copilot');
    }
}
