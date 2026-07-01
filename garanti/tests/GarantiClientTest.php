<?php
namespace Garanti\Tests;

use Garanti\Api\GarantiClient;
use Garanti\Auth\TokenManager;
use PHPUnit\Framework\TestCase;

class GarantiClientTest extends TestCase
{
    private function tm(HttpFake $http): TokenManager
    {
        $http->push(200, json_encode(['access_token' => 'AAA', 'expires_in' => 3600]));
        return new TokenManager($http, [
            'token_url' => 'https://x/token', 'client_id' => 'c',
            'client_secret' => 's', 'redirect_uri' => 'https://x/cb',
        ]);
    }

    public function test_normalizes_transactions_from_fixture(): void
    {
        $http = new HttpFake();
        $tm = $this->tm($http);
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_transactions.json'));

        $fieldMap = require __DIR__ . '/../config/field_map.php';
        $client = new GarantiClient($http, $tm,
            ['base_url' => 'https://apis.garantibbva.com.tr', 'consent_id' => 'CONSENT1'],
            $fieldMap);

        $rows = $client->getTransactions('TR000000000000000000000001', '2026-06-01', '2026-06-30');

        $this->assertCount(2, $rows);
        $this->assertSame('REF-1001', $rows[0]['banka_ref']);
        $this->assertSame('C', $rows[0]['borc_alacak']);
        $this->assertSame(1500.50, $rows[0]['tutar']);
        $this->assertSame('ACME LTD', $rows[0]['karsi_taraf']);
        $this->assertNotEmpty($rows[0]['ham_json']);
    }

    public function test_sends_bearer_and_consent_headers(): void
    {
        $http = new HttpFake();
        $tm = $this->tm($http);
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_transactions.json'));
        $fieldMap = require __DIR__ . '/../config/field_map.php';
        $client = new GarantiClient($http, $tm,
            ['base_url' => 'https://apis.garantibbva.com.tr', 'consent_id' => 'CONSENT1'], $fieldMap);

        $client->getTransactions('TR0001', '2026-06-01', '2026-06-30');

        $apiCall = $http->calls[1]; // 0 = token, 1 = transactions
        $flat = implode("\n", $apiCall['headers']);
        $this->assertStringContainsString('Authorization: Bearer AAA', $flat);
        $this->assertStringContainsString('CONSENT1', $flat);
    }
}
