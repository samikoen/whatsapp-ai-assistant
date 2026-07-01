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

    /**
     * Consent kapsamindaki TUM hesaplarin, verilen tarih araligindaki hareketlerini
     * (sayfalari dolasarak) normalize edip dondurur. Her satir kendi 'iban' bilgisini tasir.
     *
     * Gercek Garanti EHO ucu: POST /balancesandmovements/accountinformation/transaction/v1/gettransactions
     * Govde: { consentId, startDate, endDate, pageIndex, pageSize }  (consentId header'da DEGIL, govdede)
     *
     * @return array<int,array>
     */
    public function getTransactions(string $from, string $to, int $pageSize = 500): array
    {
        $token = $this->tm->getToken();
        $url = rtrim($this->api['base_url'], '/')
             . '/balancesandmovements/accountinformation/transaction/v1/gettransactions';
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $out = [];
        $pageIndex = 1;
        do {
            $body = json_encode([
                'consentId' => $this->api['consent_id'] ?? '',
                'startDate' => $from . 'T00:00:00.000',
                'endDate'   => $to . 'T23:59:59.999',
                'pageIndex' => $pageIndex,
                'pageSize'  => $pageSize,
            ], JSON_UNESCAPED_UNICODE);

            $res = $this->http->request('POST', $url, $headers, $body);
            if ($res['status'] === 401) {
                throw new \RuntimeException('Yetki hatasi (401) — token/consent kontrol edin');
            }
            if ($res['status'] !== 200) {
                throw new \RuntimeException('API hata: HTTP ' . $res['status']);
            }

            $data = json_decode($res['body'], true) ?? [];
            $list = $data[$this->map['list']] ?? [];
            foreach ($list as $item) {
                $out[] = $this->normalizeOne($item);
            }
            $count = count($list);
            $pageIndex++;
        } while ($count === $pageSize); // dolu sayfa geldiyse sonraki sayfa var demektir

        return $out;
    }

    /** @return array tek hareketin normalize edilmis hali */
    private function normalizeOne(array $item): array
    {
        return [
            'iban'           => (string)($item[$this->map['iban']] ?? ''),
            'banka_ref'      => (string)($item[$this->map['banka_ref']] ?? ''),
            'tarih'          => (string)($item[$this->map['tarih']] ?? ''),
            'valor_tarihi'   => $item[$this->map['valor_tarihi']] ?? null,
            'tutar'          => (float)($item[$this->map['tutar']] ?? 0),
            'borc_alacak'    => $this->direction($item),
            'para_birimi'    => (string)($item[$this->map['para_birimi']] ?? ($this->api['default_currency'] ?? '')),
            'aciklama'       => $item[$this->map['aciklama']] ?? null,
            'karsi_taraf'    => $this->counterparty($item),
            'bakiye_sonrasi' => isset($item[$this->map['bakiye_sonrasi']])
                                    ? (float)$item[$this->map['bakiye_sonrasi']] : null,
            'ham_json'       => json_encode($item, JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Borc/alacak yonu: acik alan (debitCreditIndicator) varsa onu kullan;
     * yoksa tutar isaretinden turet (negatif = borc 'D', pozitif = alacak 'C').
     * TEYIT: gercek yanitta yon alaninin adi/varligi dogrulanmali.
     */
    private function direction(array $item): string
    {
        $field = $this->map['borc_alacak'] ?? null;
        if ($field !== null && isset($item[$field]) && $item[$field] !== '') {
            return (string)$item[$field];
        }
        $amount = (float)($item[$this->map['tutar']] ?? 0);
        return $amount < 0 ? 'D' : 'C';
    }

    /**
     * Karsi taraf adi: ic ice enrichmentInformation dizisinden
     * (enrichmentCode = 'MUS') enrichmentValue.nameSurnameText / corrNameSurnameText.
     */
    private function counterparty(array $item): ?string
    {
        $listKey = $this->map['enrichment_list'] ?? null;
        if ($listKey === null || empty($item[$listKey]) || !is_array($item[$listKey])) {
            return null;
        }
        foreach ($item[$listKey] as $enr) {
            if (!is_array($enr)) {
                continue;
            }
            $code = $enr[$this->map['enrichment_code_key']] ?? null;
            if ($code !== ($this->map['karsi_taraf_code'] ?? null)) {
                continue;
            }
            $val = $enr[$this->map['enrichment_value_key']] ?? [];
            foreach ((array)($this->map['karsi_taraf_fields'] ?? []) as $f) {
                if (!empty($val[$f])) {
                    return (string)$val[$f];
                }
            }
        }
        return null;
    }
}
