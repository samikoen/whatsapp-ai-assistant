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
        if ($this->token !== null && time() < $this->expiresAt - 30) {
            return $this->token;
        }
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'redirect_uri'  => $this->cfg['redirect_uri'],
        ]);
        $res = $this->http->request('POST', $this->cfg['token_url'],
            ['Content-Type: application/x-www-form-urlencoded'], $body);
        if ($res['status'] !== 200) {
            throw new \RuntimeException('Token alinamadi: HTTP ' . $res['status']);
        }
        $data = json_decode($res['body'], true);
        if (!isset($data['access_token'])) {
            throw new \RuntimeException('Token yaniti gecersiz');
        }
        $this->token = $data['access_token'];
        $this->expiresAt = time() + (int)($data['expires_in'] ?? 3600);
        return $this->token;
    }
}
