<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\ManageMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InvalidateMemberSessionsRequest;
use App\Http\Requests\Admin\ListMembersRequest;
use App\Http\Requests\Admin\ResetMemberPasswordRequest;
use App\Http\Requests\Admin\StoreMemberRequest;
use App\Http\Requests\Admin\UpdateMemberRequest;
use App\Http\Requests\Admin\UpdateMemberStatusRequest;
use App\Http\Resources\Admin\MemberResource;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class MemberController extends Controller
{
    public function __construct(private ManageMember $manageMember) {}

    public function index(ListMembersRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $search = $validated['search'] ?? null;

        $members = Member::query()
            ->when(is_string($search) && $search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%");
                });
            })
            ->when(array_key_exists('is_active', $validated), fn (Builder $query): Builder => $query->where('is_active', $validated['is_active']))
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? null);

        return $this->success(MemberResource::collection($members));
    }

    public function store(StoreMemberRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('admin');
        $member = $this->manageMember->create($request->validated(), $actor);

        return $this->success(['member' => MemberResource::make($member)]);
    }

    public function show(Member $member): JsonResponse
    {
        return $this->success(['member' => MemberResource::make($member)]);
    }

    public function update(UpdateMemberRequest $request, Member $member): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('admin');
        $member = $this->manageMember->update($member, $request->validated(), $actor);

        return $this->success(['member' => MemberResource::make($member)]);
    }

    public function updateStatus(UpdateMemberStatusRequest $request, Member $member): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('admin');
        $member = $this->manageMember->updateStatus($member, (bool) $request->validated('is_active'), $actor);

        return $this->success(['member' => MemberResource::make($member)]);
    }

    public function resetPassword(ResetMemberPasswordRequest $request, Member $member): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('admin');
        $member = $this->manageMember->resetPassword($member, $request->validated('password'), $actor);

        return $this->success(['member' => MemberResource::make($member)], message: 'password reset');
    }

    public function invalidateSessions(InvalidateMemberSessionsRequest $request, Member $member): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('admin');
        $member = $this->manageMember->invalidateSessions($member, $actor);

        return $this->success(['member' => MemberResource::make($member)], message: 'sessions invalidated');
    }
}
