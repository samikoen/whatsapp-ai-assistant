<?php
// Account Transactions (gettransactions) JSON yanit alan eslemesi.
// GERCEK Garanti EHO yanitina gore ayarlandi (resmi basvuru dokumani, Adim 7).
// Banka gercek uretim yanitinda alan adi farkli cikarsa SADECE burayi guncelle;
// GarantiClient mantigi degismez.
//
// 'list'  = hareket dizisinin yanit icindeki anahtari
// digerleri = her hareket ogesindeki alan adlari
return [
    'list'           => 'transactions',
    'banka_ref'      => 'transactionInstanceId', // benzersiz hareket kimligi (idempotency anahtari)
    'iban'           => 'IBAN',
    'tarih'          => 'valueDate',
    'valor_tarihi'   => 'valueDate',
    'tutar'          => 'amount',
    'borc_alacak'    => 'debitCreditIndicator',  // TEYIT: alan yoksa tutar isaretinden turetilir (bkz GarantiClient::direction)
    'para_birimi'    => 'currencyCode',          // TEYIT: alan yoksa api['default_currency'] fallback
    'aciklama'       => 'explanation',
    'bakiye_sonrasi' => 'balanceAfterTransaction',

    // Karsi taraf adi DUZ alan degil; ic ice enrichmentInformation dizisinde geliyor.
    // enrichmentCode = 'MUS' olan ogenin enrichmentValue.nameSurnameText / corrNameSurnameText alani.
    'enrichment_list'      => 'enrichmentInformation',
    'enrichment_code_key'  => 'enrichmentCode',
    'enrichment_value_key' => 'enrichmentValue',
    'karsi_taraf_code'     => 'MUS',
    'karsi_taraf_fields'   => ['nameSurnameText', 'corrNameSurnameText'],
];
