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

    private function client(HttpFake $http, TokenManager $tm): GarantiClient
    {
        $fieldMap = require __DIR__ . '/../config/field_map.php';
        return new GarantiClient($http, $tm,
            ['base_url' => 'https://apis.garantibbva.com.tr', 'consent_id' => 'CONSENT1',
             'default_currency' => 'TL'],
            $fieldMap);
    }

    public function test_normalizes_transactions_from_fixture(): void
    {
        $http = new HttpFake();
        $tm = $this->tm($http);
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_transactions.json'));

        $rows = $this->client($http, $tm)->getTransactions('2026-06-15', '2026-06-30');

        $this->assertCount(2, $rows);
        // Ilk hareket: A (alacak) -> ic gosterim 'C'; karsi taraf corrNameSurnameText'ten
        $this->assertSame('2026-06-30T09:10:11.123456', $rows[0]['banka_ref']);
        $this->assertSame('C', $rows[0]['borc_alacak']);
        $this->assertSame('2026-06-30', $rows[0]['tarih']);        // activityDate
        $this->assertSame('2026-07-01', $rows[0]['valor_tarihi']); // valueDate
        $this->assertSame(1500.50, $rows[0]['tutar']);
        $this->assertSame('ACME LTD', $rows[0]['karsi_taraf']);    // corrNameSurnameText (SAHIBI degil)
        $this->assertSame('TL', $rows[0]['para_birimi']);
        $this->assertSame('TR450006200013500006294536', $rows[0]['iban']);
        $this->assertNotEmpty($rows[0]['ham_json']);
        // Ikinci hareket: B (borc) -> 'D'; currencyCode null -> default_currency
        $this->assertSame('D', $rows[1]['borc_alacak']);
        $this->assertSame('TL', $rows[1]['para_birimi']);
        $this->assertSame('TEDARIKCI AS', $rows[1]['karsi_taraf']);
    }

    public function test_posts_to_gettransactions_with_consent_in_body(): void
    {
        $http = new HttpFake();
        $tm = $this->tm($http);
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_transactions.json'));

        $this->client($http, $tm)->getTransactions('2026-06-15', '2026-06-30');

        $apiCall = $http->calls[1]; // 0 = token, 1 = gettransactions
        $this->assertSame('POST', $apiCall['method']);
        $this->assertStringContainsString('gettransactions', $apiCall['url']);
        $flatHeaders = implode("\n", $apiCall['headers']);
        $this->assertStringContainsString('Authorization: Bearer AAA', $flatHeaders);
        // consentId govdede gonderilir, header'da degil
        $this->assertStringContainsString('CONSENT1', (string)$apiCall['body']);
        $this->assertStringContainsString('pageIndex', (string)$apiCall['body']);
    }

    public function test_splits_ranges_longer_than_30_days_into_windows(): void
    {
        $http = new HttpFake();
        $tm = $this->tm($http);
        $empty = json_encode(['result' => ['returnCode' => 200], 'transactions' => []]);
        $http->push(200, $empty); // pencere 1
        $http->push(200, $empty); // pencere 2
        $http->push(200, $empty); // pencere 3

        // 75 gunluk aralik -> 30+30+15 = 3 pencere (banka limiti: max 30 gun)
        $rows = $this->client($http, $tm)->getTransactions('2026-04-01', '2026-06-14');

        $this->assertSame([], $rows);
        $this->assertCount(4, $http->calls); // 1 token + 3 pencere
        $this->assertStringContainsString('2026-04-01T00:00:00', (string)$http->calls[1]['body']);
        $this->assertStringContainsString('2026-04-30T23:59:59', (string)$http->calls[1]['body']);
        $this->assertStringContainsString('2026-05-01T00:00:00', (string)$http->calls[2]['body']);
        $this->assertStringContainsString('2026-06-14T23:59:59', (string)$http->calls[3]['body']);
    }

    public function test_retries_once_with_fresh_token_on_401(): void
    {
        $http = new HttpFake();
        $tm = $this->tm($http); // token 1 kuyrukta
        $http->push(401, json_encode(['result' => ['code' => 401, 'info' => 'Invalid Credentials']]));
        $http->push(200, json_encode(['access_token' => 'BBB', 'expires_in' => 3600])); // taze token
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_transactions.json'));

        $rows = $this->client($http, $tm)->getTransactions('2026-06-15', '2026-06-30');

        $this->assertCount(2, $rows);
        // cagri sirasi: token(AAA), api(401), token(BBB), api(200)
        $this->assertCount(4, $http->calls);
        $this->assertStringContainsString('Bearer BBB', implode("\n", $http->calls[3]['headers']));
    }

    public function test_gets_account_information_with_balances(): void
    {
        $http = new HttpFake();
        $tm = $this->tm($http);
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_information.json'));

        $accounts = $this->client($http, $tm)->getAccountInformation();

        $this->assertCount(1, $accounts);
        $this->assertSame('TR450006200013500006294536', $accounts[0]['iban']);
        $this->assertSame('TL', $accounts[0]['para_birimi']); // "TL " trim edilir
        $this->assertSame(24700.00, $accounts[0]['bakiye']);
        $this->assertSame(24700.00, $accounts[0]['kullanilabilir_bakiye']);
        $call = $http->calls[1];
        $this->assertStringContainsString('getaccountinformation', $call['url']);
        $this->assertStringContainsString('CONSENT1', (string)$call['body']);
    }
}
