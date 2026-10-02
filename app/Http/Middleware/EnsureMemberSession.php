<?php

namespace App\Http\Middleware;

use App\Models\Member;
use App\Support\Auth\MemberSessionManager;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureMemberSession
{
    public function __construct(private MemberSessionManager $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $member = $request->user('member');
        if (! $member instanceof Member) {
            throw new AuthenticationException(guards: ['member']);
        }
        $this->sessions->validate($member, $this->sessions->sessionId(Auth::guard('member')->getPayload()));

        return $next($request);
    }
}
