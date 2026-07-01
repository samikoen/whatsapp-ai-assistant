<?php
// Account Transactions JSON yanitindaki alan yollari. Gercek ornek gelince guncelle.
// 'list' = hareket dizisinin yanit icindeki anahtari; digerleri her hareket ogesindeki alanlar.
return [
    'list'           => 'transactions',
    'banka_ref'      => 'transactionId',
    'tarih'          => 'transactionDate',
    'valor_tarihi'   => 'valueDate',
    'tutar'          => 'amount',
    'borc_alacak'    => 'debitCreditIndicator', // 'D'/'C' bekleniyor
    'para_birimi'    => 'currency',
    'aciklama'       => 'description',
    'karsi_taraf'    => 'counterpartyName',
    'bakiye_sonrasi' => 'balanceAfter',
];
