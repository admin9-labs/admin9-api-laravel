<?php

namespace App\Http\Resources\Member;

use App\Http\Resources\PaginationAwareJsonResource;
use Illuminate\Http\Request;

class MemberResource extends PaginationAwareJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'is_active' => $this->is_active,
            'last_login_at' => $this->dateTimeString($this->last_login_at),
        ];
    }
}
