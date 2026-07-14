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
            'platform' => ['required', 'string', 'in:steam,gog'],
            'steam_id' => [
                'exclude_unless:platform,steam',
                'required_without:vanity_url',
                'string',
                'regex:/^\d{17}$/',
            ],
            'vanity_url' => [
                'exclude_unless:platform,steam',
                'required_without:steam_id',
                'string',
                'max:255',
            ],
            // GOG OAuth authorization code, exchanged server-side (I.gog).
            'code' => ['exclude_unless:platform,gog', 'required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'platform.in' => 'Only Steam and GOG connections are supported right now.',
            'steam_id.required_without' => 'Provide either a SteamID64 or a vanity URL name.',
            'code.required' => 'Provide the GOG login code to connect a GOG account.',
        ];
    }
}
