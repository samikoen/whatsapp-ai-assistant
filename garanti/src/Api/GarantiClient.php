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
     * Ortak POST+JSON cagrisi. Dokumana gore access token tek kullanimlik olabilir;
     * 401 gelirse token gecersiz kilinip taze token ile BIR kez tekrar denenir.
     *
     * @return array decode edilmis yanit
     */
    private function postJson(string $path, array $payload): array
    {
        $url = rtrim($this->api['base_url'], '/') . $path;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $headers = [
                'Authorization: Bearer ' . $this->tm->getToken(),
                'Content-Type: application/json',
                'Accept: application/json',
            ];
            $res = $this->http->request('POST', $url, $headers, $body);

            if ($res['status'] === 401 && $attempt === 1) {
                $this->tm->invalidate(); // tek-kullanimlik/suresi gecmis token — tazele ve tekrar dene
                continue;
            }
            if ($res['status'] === 401) {
                throw new \RuntimeException('Yetki hatasi (401) — token/consent kontrol edin');
            }
            if ($res['status'] !== 200) {
                $info = '';
                $err = json_decode($res['body'], true);
                if (isset($err['result']['messageText'])) {
                    $info = ' — ' . $err['result']['messageText'];
                } elseif (isset($err['result']['info'])) {
                    $info = ' — ' . $err['result']['info'];
                }
                throw new \RuntimeException('API hata: HTTP ' . $res['status'] . $info);
            }
            return json_decode($res['body'], true) ?? [];
        }
        throw new \RuntimeException('API hata: beklenmeyen durum'); // buraya dusmez
    }

    /**
     * Consent kapsamindaki TUM hesaplarin, verilen tarih araligindaki hareketlerini
     * (sayfalari dolasarak) normalize edip dondurur. Her satir kendi 'iban' bilgisini tasir.
     *
     * Uc: POST /balancesandmovements/accountinformation/transaction/v1/gettransactions
     *
     * @return array<int,array>
     */
    public function getTransactions(string $from, string $to, int $pageSize = 500): array
    {
        // Banka kurali: tarih araligi 30 gunden fazla olamaz (reasonCode 18),
        // pageSize <= 500 (reasonCode 30). Genis aralik istenirse 30'ar gunluk
        // pencerelere bolunerek cekilir (ilk kurulum/backfill icin).
        $pageSize = min($pageSize, 500);
        $out = [];
        $winStart = strtotime($from);
        $end = strtotime($to);
        while ($winStart <= $end) {
            $winEnd = min(strtotime('+29 days', $winStart), $end);
            $out = array_merge(
                $out,
                $this->fetchWindow(date('Y-m-d', $winStart), date('Y-m-d', $winEnd), $pageSize)
            );
            $winStart = strtotime('+1 day', $winEnd);
        }
        return $out;
    }

    /** Tek bir <=30 gunluk pencerenin tum sayfalarini ceker. @return array<int,array> */
    private function fetchWindow(string $from, string $to, int $pageSize): array
    {
        $out = [];
        $pageIndex = 1;
        do {
            $data = $this->postJson(
                '/balancesandmovements/accountinformation/transaction/v1/gettransactions',
                [
                    'consentId' => $this->api['consent_id'] ?? '',
                    'startDate' => $from . 'T00:00:00.000',
                    'endDate'   => $to . 'T23:59:59.999',
                    'pageIndex' => $pageIndex,
                    'pageSize'  => $pageSize,
                ]
            );
            $list = $data[$this->map['list']] ?? [];
            foreach ($list as $item) {
                $out[] = $this->normalizeOne($item);
            }
            $count = count($list);
            $pageIndex++;
        } while ($count === $pageSize); // dolu sayfa geldiyse sonraki sayfa var demektir

        return $out;
    }

    /**
     * Consent kapsamindaki hesaplarin detay + bakiyelerini dondurur.
     *
     * Uc: POST /balancesandmovements/accountinformation/account/v1/getaccountinformation
     * (resmi dokuman; govdede yalniz consentId zorunlu, tum hesaplar doner)
     *
     * @return array<int,array{iban:string,hesap_no:?string,sube:?string,para_birimi:string,
     *                         bakiye:?float,kullanilabilir_bakiye:?float,ham_json:string}>
     */
    public function getAccountInformation(): array
    {
        $data = $this->postJson(
            '/balancesandmovements/accountinformation/account/v1/getaccountinformation',
            ['consentId' => $this->api['consent_id'] ?? '']
        );

        $out = [];
        foreach (($data[$this->map['ai_list']] ?? []) as $acc) {
            $out[] = [
                'iban'                 => trim((string)($acc[$this->map['ai_iban']] ?? '')),
                'hesap_no'             => isset($acc[$this->map['ai_hesap_no']]) ? (string)$acc[$this->map['ai_hesap_no']] : null,
                'sube'                 => isset($acc[$this->map['ai_sube']]) ? (string)$acc[$this->map['ai_sube']] : null,
                'para_birimi'          => trim((string)($acc[$this->map['ai_para_birimi']] ?? ($this->api['default_currency'] ?? ''))),
                'bakiye'               => $this->balanceOfType($acc, $this->map['ai_balance_type']),
                'kullanilabilir_bakiye'=> $this->balanceOfType($acc, $this->map['ai_available_type']),
                'ham_json'             => json_encode($acc, JSON_UNESCAPED_UNICODE),
            ];
        }
        return $out;
    }

    /** balances[] icinden verilen tipteki tutari ceker (yoksa null). */
    private function balanceOfType(array $acc, string $type): ?float
    {
        foreach (($acc[$this->map['ai_balances']] ?? []) as $b) {
            if (!is_array($b)) {
                continue;
            }
            if (($b[$this->map['ai_balance_type_key']] ?? null) === $type
                && isset($b[$this->map['ai_balance_amount_key']])) {
                return (float)$b[$this->map['ai_balance_amount_key']];
            }
        }
        return null;
    }

    /** @return array tek hareketin normalize edilmis hali */
    private function normalizeOne(array $item): array
    {
        return [
            'iban'           => trim((string)($item[$this->map['iban']] ?? '')),
            'banka_ref'      => (string)($item[$this->map['banka_ref']] ?? ''),
            'tarih'          => (string)($item[$this->map['tarih']] ?? ''),
            'valor_tarihi'   => $item[$this->map['valor_tarihi']] ?? null,
            'tutar'          => (float)($item[$this->map['tutar']] ?? 0),
            'borc_alacak'    => $this->direction($item),
            'para_birimi'    => trim((string)($item[$this->map['para_birimi']] ?? ($this->api['default_currency'] ?? ''))),
            'aciklama'       => $item[$this->map['aciklama']] ?? null,
            'karsi_taraf'    => $this->counterparty($item),
            'bakiye_sonrasi' => isset($item[$this->map['bakiye_sonrasi']])
                                    ? (float)$item[$this->map['bakiye_sonrasi']] : null,
            'ham_json'       => json_encode($item, JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Borc/alacak yonu. Resmi dokuman: txnCreditDebitIndicator = 'A' (alacak) / 'B' (borc).
     * Ic gosterime cevrilir: 'C' (credit/alacak) / 'D' (debit/borc) — DB ve dashboard bunu bekler.
     * Alan yoksa tutar isaretinden turetilir (negatif = borc).
     */
    private function direction(array $item): string
    {
        $field = $this->map['borc_alacak'] ?? null;
        if ($field !== null && isset($item[$field]) && $item[$field] !== '') {
            $raw = strtoupper(trim((string)$item[$field]));
            $translate = $this->map['borc_alacak_map'] ?? [];
            return $translate[$raw] ?? $raw;
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
