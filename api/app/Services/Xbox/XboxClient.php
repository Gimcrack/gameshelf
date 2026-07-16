<?php

namespace App\Services\Xbox;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * I.xbox/V60: real Microsoft OAuth, 3-hop token exchange (MS access token →
 * XBL user token → XSTS token) mirrors the community xbox-webapi/xboxreplay
 * pattern — the only Xbox auth route that isn't a password-equivalent
 * session token (§C, V60).
 */
class XboxClient
{
    private const TOKEN_URL = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token';

    private const XBL_AUTH_URL = 'https://user.auth.xboxlive.com/user/authenticate';

    private const XSTS_AUTH_URL = 'https://xsts.auth.xboxlive.com/xsts/authorize';

    private const TITLEHUB_URL = 'https://titlehub.xboxlive.com';

    public const SCOPE = 'XboxLive.signin offline_access';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    public function exchangeCode(string $code, string $redirectUri): ?array
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
        ]);
    }

    /**
     * V14: trade a refresh token for a fresh MS token pair, or null when
     * Microsoft rejects it (revoked/expired).
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    public function refreshTokens(string $refreshToken): ?array
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => self::SCOPE,
        ]);
    }

    /**
     * 2nd + 3rd hop: MS access token → XBL user token → XSTS token. The
     * result's `uhs` + XSTS token together authorize Xbox Live API calls;
     * `xuid` identifies the account (I.xbox connect + every sync).
     *
     * @return array{xsts_token: string, user_hash: string, xuid: string}|null
     */
    public function exchangeForXsts(string $msAccessToken): ?array
    {
        $xblResponse = Http::post(self::XBL_AUTH_URL, [
            'Properties' => [
                'AuthMethod' => 'RPS',
                'SiteName' => 'user.auth.xboxlive.com',
                'RpsTicket' => "d={$msAccessToken}",
            ],
            'RelyingParty' => 'http://auth.xboxlive.com',
            'TokenType' => 'JWT',
        ]);

        if ($xblResponse->failed()) {
            throw new RuntimeException('Xbox Live user authentication failed: '.$xblResponse->status());
        }

        $xblToken = $xblResponse->json('Token');

        $xstsResponse = Http::post(self::XSTS_AUTH_URL, [
            'Properties' => [
                'SandboxId' => 'RETAIL',
                'UserTokens' => [$xblToken],
            ],
            'RelyingParty' => 'http://xboxlive.com',
            'TokenType' => 'JWT',
        ]);

        if ($xstsResponse->status() === 401) {
            // Expired/invalid XBL token, or Microsoft account lacks an Xbox
            // profile — same "reconnect required" shape as a rejected GOG
            // refresh token (V56 class: log the real body, not a bare null).
            Log::warning('Xbox XSTS authorization rejected', [
                'status' => $xstsResponse->status(),
                'body' => $xstsResponse->json(),
            ]);

            return null;
        }

        if ($xstsResponse->failed()) {
            throw new RuntimeException('Xbox XSTS authorization failed: '.$xstsResponse->status());
        }

        return [
            'xsts_token' => $xstsResponse->json('Token'),
            'user_hash' => $xstsResponse->json('DisplayClaims.xui.0.uhs'),
            'xuid' => (string) $xstsResponse->json('DisplayClaims.xui.0.xid'),
        ];
    }

    /**
     * Xbox Live title history — games with Live activity (achievements/
     * stats present). Approximates the library; ⊥ full Microsoft Store
     * purchase history, which no Xbox Live API exposes (§C caveat).
     *
     * @return list<array<string, mixed>>
     */
    public function getTitleHistory(string $xuid, string $xstsToken, string $userHash): array
    {
        $response = Http::withHeaders([
            'Authorization' => "XBL3.0 x={$userHash};{$xstsToken}",
            'x-xbl-contract-version' => '2',
        ])->get(self::TITLEHUB_URL."/users/xuid({$xuid})/titles/titlehistory/decoration/detail");

        if ($response->failed()) {
            throw new RuntimeException('Xbox titlehub request failed: '.$response->status());
        }

        return $response->json('titles', []);
    }

    /**
     * @param  array<string, string>  $params
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    private function requestToken(array $params): ?array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            ...$params,
        ]);

        if ($response->status() === 400 || $response->status() === 401) {
            // V56 class: log the real body — invalid_grant (bad/expired
            // code) and invalid_client (bad app credentials) are different
            // failure classes, collapsing them costs a guess-and-check round trip.
            Log::warning('Microsoft OAuth token request rejected', [
                'grant_type' => $params['grant_type'],
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException('Microsoft OAuth token request failed: '.$response->status());
        }

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => (int) $response->json('expires_in'),
        ];
    }
}
