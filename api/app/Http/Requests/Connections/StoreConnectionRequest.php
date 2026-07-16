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
            'platform' => ['required', 'string', 'in:steam,gog,xbox'],
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
            // GOG/Xbox OAuth authorization code, exchanged server-side (I.gog/I.xbox).
            'code' => ['exclude_unless:platform,gog,xbox', 'required', 'string'],
            // I.xbox: we control the Azure AD app registration, so (unlike
            // GOG) the FE picks its own redirect_uri — it must be echoed
            // back verbatim in the token exchange (T63).
            'redirect_uri' => ['exclude_unless:platform,xbox', 'required', 'url'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'platform.in' => 'Only Steam, GOG, and Xbox connections are supported right now.',
            'steam_id.required_without' => 'Provide either a SteamID64 or a vanity URL name.',
            'code.required' => 'Provide the login code to connect this account.',
            'redirect_uri.required' => 'Missing OAuth redirect URI.',
        ];
    }
}
