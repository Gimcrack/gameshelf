<?php

namespace App\Http\Requests\Connections;

use Illuminate\Foundation\Http\FormRequest;

class StoreConnectionRequest extends FormRequest
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
            // GOG connect arrives with T6; only steam is accepted today.
            'platform' => ['required', 'string', 'in:steam'],
            'steam_id' => ['required_without:vanity_url', 'string', 'regex:/^\d{17}$/'],
            'vanity_url' => ['required_without:steam_id', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'platform.in' => 'Only Steam connections are supported right now.',
            'steam_id.required_without' => 'Provide either a SteamID64 or a vanity URL name.',
        ];
    }
}
