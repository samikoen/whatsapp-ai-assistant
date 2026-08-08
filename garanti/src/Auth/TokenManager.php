<?php
namespace Garanti\Auth;

use Garanti\Http\HttpClient;

class TokenManager
{
    private HttpClient $http;
    private array $cfg;
    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(HttpClient $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg = $cfg;
    }

    public function getToken(): string
    {
        // Garanti EHO dokumani: "The access token can be used only one time."
        // cfg['single_use'] = true iken cache atlanir, her istek icin taze token alinir.
        $singleUse = !empty($this->cfg['single_use']);
        if (!$singleUse && $this->token !== null && time() < $this->expiresAt - 30) {
            return $this->token;
        }
        // NOT: Garanti client_credentials akisinda redirect_uri GONDERILMEZ.
        // Gonderildiginde OAuth sunucusu "Invalid Redirect URI" (400) donuyor; redirect_uri
        // yalnizca portalda kayitli callback olarak durur, token isteginde yer almaz.
        // (Sadece cfg['send_redirect_uri'] acikca true ise geriye-donuk uyumluluk icin eklenir.)
        $params = [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
        ];
        if (!empty($this->cfg['send_redirect_uri']) && !empty($this->cfg['redirect_uri'])) {
            $params['redirect_uri'] = $this->cfg['redirect_uri'];
        }
        $res = $this->http->request('POST', $this->cfg['token_url'],
            ['Content-Type: application/x-www-form-urlencoded'], http_build_query($params));
        if ($res['status'] !== 200) {
            $info = '';
            $err = json_decode($res['body'], true);
            if (isset($err['result']['info'])) {
                $info = ' — ' . $err['result']['info'];
            }
            throw new \RuntimeException('Token alinamadi: HTTP ' . $res['status'] . $info);
        }
        $data = json_decode($res['body'], true);
        if (!isset($data['access_token']) || !is_string($data['access_token'])) {
            throw new \RuntimeException('Token yaniti gecersiz');
        }
        $this->token = $data['access_token'];
        $this->expiresAt = time() + (int)($data['expires_in'] ?? 3600);
        return $this->token;
    }

    /** Cache'lenmis token'i gecersiz kilar; sonraki getToken() taze token alir. */
    public function invalidate(): void
    {
        $this->token = null;
        $this->expiresAt = 0;
    }
}
