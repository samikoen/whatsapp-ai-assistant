<?php
namespace Garanti\Api;

use Garanti\Http\HttpClient;
use Garanti\Auth\TokenManager;

class GarantiClient
{
    private HttpClient $http;
    private TokenManager $tm;
    private array $api;
    private array $map;

    public function __construct(HttpClient $http, TokenManager $tm, array $api, array $map)
    {
        $this->http = $http;
        $this->tm = $tm;
        $this->api = $api;
        $this->map = $map;
    }

    /** @return array<int,array> normalize edilmis hareketler */
    public function getTransactions(string $iban, string $from, string $to): array
    {
        $token = $this->tm->getToken();
        $url = $this->api['base_url'] . '/accountTransactions'
             . '?iban=' . urlencode($iban)
             . '&startDate=' . urlencode($from)
             . '&endDate=' . urlencode($to);
        $headers = [
            'Authorization: Bearer ' . $token,
            'consentId: ' . ($this->api['consent_id'] ?? ''),
            'Accept: application/json',
        ];
        $res = $this->http->request('GET', $url, $headers);
        if ($res['status'] === 401) {
            throw new \RuntimeException('Yetki hatasi (401) — token/consent kontrol edin');
        }
        if ($res['status'] !== 200) {
            throw new \RuntimeException('API hata: HTTP ' . $res['status']);
        }
        return $this->normalize(json_decode($res['body'], true) ?? []);
    }

    private function normalize(array $data): array
    {
        $list = $data[$this->map['list']] ?? [];
        $out = [];
        foreach ($list as $item) {
            $out[] = [
                'banka_ref'      => (string)($item[$this->map['banka_ref']] ?? ''),
                'tarih'          => (string)($item[$this->map['tarih']] ?? ''),
                'valor_tarihi'   => $item[$this->map['valor_tarihi']] ?? null,
                'tutar'          => (float)($item[$this->map['tutar']] ?? 0),
                'borc_alacak'    => (string)($item[$this->map['borc_alacak']] ?? ''),
                'para_birimi'    => (string)($item[$this->map['para_birimi']] ?? ''),
                'aciklama'       => $item[$this->map['aciklama']] ?? null,
                'karsi_taraf'    => $item[$this->map['karsi_taraf']] ?? null,
                'bakiye_sonrasi' => isset($item[$this->map['bakiye_sonrasi']])
                                        ? (float)$item[$this->map['bakiye_sonrasi']] : null,
                'ham_json'       => json_encode($item, JSON_UNESCAPED_UNICODE),
            ];
        }
        return $out;
    }
}
