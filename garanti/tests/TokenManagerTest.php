<?php
namespace Garanti\Tests;

use Garanti\Auth\TokenManager;
use PHPUnit\Framework\TestCase;

class TokenManagerTest extends TestCase
{
    private array $cfg = [
        'token_url' => 'https://apis.garantibbva.com.tr/auth/oauth/v2/token',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'redirect_uri' => 'https://x/callback.php',
    ];

    public function test_fetches_token_and_sends_client_credentials(): void
    {
        $http = new HttpFake();
        $http->push(200, json_encode(['access_token' => 'AAA', 'expires_in' => 3600]));
        $tm = new TokenManager($http, $this->cfg);

        $this->assertSame('AAA', $tm->getToken());
        $sent = $http->calls[0];
        $this->assertSame('POST', $sent['method']);
        $this->assertStringContainsString('grant_type=client_credentials', $sent['body']);
        $this->assertStringContainsString('client_id=cid', $sent['body']);
    }

    public function test_caches_token_within_expiry(): void
    {
        $http = new HttpFake();
        $http->push(200, json_encode(['access_token' => 'AAA', 'expires_in' => 3600]));
        $tm = new TokenManager($http, $this->cfg);
        $tm->getToken();
        $tm->getToken();
        $this->assertCount(1, $http->calls); // ikinci cagri cache'ten
    }
}
