<?php

namespace App\Http\Requests\FamilyMembers;

use Illuminate\Foundation\Http\FormRequest;

class StoreFamilyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // V25: FE already resolved+confirmed identity via the existing
            // steam/resolve endpoint — this takes the final SteamID64 only.
            'steam_id' => ['required', 'string', 'regex:/^\d{17}$/'],
        ];
    }
}
