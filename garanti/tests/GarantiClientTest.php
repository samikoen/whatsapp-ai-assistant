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

        $rows = $client->getTransactions('2022-06-01', '2022-06-30');

        $this->assertCount(2, $rows);
        // Ilk hareket: gelen EFT (alacak), karsi taraf ic ice enrichmentInformation'dan cekiliyor
        $this->assertSame('2022-06-30T09:10:11.123456', $rows[0]['banka_ref']);
        $this->assertSame('C', $rows[0]['borc_alacak']);
        $this->assertSame(1500.50, $rows[0]['tutar']);
        $this->assertSame('ACME LTD', $rows[0]['karsi_taraf']);
        $this->assertSame('TRY', $rows[0]['para_birimi']);
        $this->assertSame('TR330006200000000008025893', $rows[0]['iban']);
        $this->assertNotEmpty($rows[0]['ham_json']);
        // Ikinci hareket: fatura odemesi (borc)
        $this->assertSame('D', $rows[1]['borc_alacak']);
        $this->assertSame('TEDARIKCI AS', $rows[1]['karsi_taraf']);
    }

    public function test_posts_to_gettransactions_with_consent_in_body(): void
    {
        $http = new HttpFake();
        $tm = $this->tm($http);
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_transactions.json'));
        $fieldMap = require __DIR__ . '/../config/field_map.php';
        $client = new GarantiClient($http, $tm,
            ['base_url' => 'https://apis.garantibbva.com.tr', 'consent_id' => 'CONSENT1'], $fieldMap);

        $client->getTransactions('2022-06-01', '2022-06-30');

        $apiCall = $http->calls[1]; // 0 = token, 1 = gettransactions
        $this->assertSame('POST', $apiCall['method']);
        $this->assertStringContainsString('gettransactions', $apiCall['url']);
        $flatHeaders = implode("\n", $apiCall['headers']);
        $this->assertStringContainsString('Authorization: Bearer AAA', $flatHeaders);
        // consentId govdede gonderilir, header'da degil
        $this->assertStringContainsString('CONSENT1', (string)$apiCall['body']);
        $this->assertStringContainsString('pageIndex', (string)$apiCall['body']);
    }
}
