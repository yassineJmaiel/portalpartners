<?php

namespace App\Exceptions;

use App\Portal\PortalError;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /* a refusal the person is told about is not a fault of the portal */
    protected $dontReport = [PortalError::class];

    protected $dontFlash = ['current_password', 'password', 'password_confirmation', 'iban'];

    public function register(): void
    {
        /* a refusal a person can act on: a form goes back with what was typed, a page says so where it was asked */
        $this->renderable(function (PortalError $e, $request) {
            if ($e->status === 401 && $e->errorCode === 'SIGNED_OUT') {
                return $request->expectsJson() ? response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], 401) : redirect('/login');
            }
            if ($request->expectsJson()) {
                return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details], $e->status);
            }
            if (! $request->isMethod('GET')) {
                return redirect()->back()->withInput($request->except(['password', 'password_confirmation', 'iban']))->with('portal_error', ['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details]);
            }

            return response()->view('errors.portal', ['status' => $e->status, 'message' => $e->getMessage(), 'details' => $e->details, 'back' => url()->previous()], $e->status >= 400 ? $e->status : 500);
        });
    }
}
