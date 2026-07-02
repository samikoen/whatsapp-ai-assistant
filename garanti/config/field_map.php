<?php
// Account Transactions (gettransactions) JSON yanit alan eslemesi.
// GERCEK Garanti EHO yanitina gore ayarlandi (resmi basvuru dokumani, Adim 7).
// Banka gercek uretim yanitinda alan adi farkli cikarsa SADECE burayi guncelle;
// GarantiClient mantigi degismez.
//
// 'list'  = hareket dizisinin yanit icindeki anahtari
// digerleri = her hareket ogesindeki alan adlari
return [
    // --- Account Transactions (gettransactions) — RESMI dokumandan (tahmin degil) ---
    'list'           => 'transactions',
    'banka_ref'      => 'transactionInstanceId', // benzersiz hareket kimligi (ornekte referenceId cakisirken instanceId benzersiz)
    'iban'           => 'IBAN',
    'tarih'          => 'activityDate',          // islem tarihi (valueDate = valor)
    'valor_tarihi'   => 'valueDate',
    'tutar'          => 'amount',
    'borc_alacak'    => 'txnCreditDebitIndicator', // banka degerleri: 'A' = alacak, 'B' = borc
    'borc_alacak_map'=> ['A' => 'C', 'B' => 'D'],  // ic gosterim: C = alacak/credit, D = borc/debit
    'para_birimi'    => 'currencyCode',            // bazen null gelir -> api['default_currency'] fallback
    'aciklama'       => 'explanation',
    'bakiye_sonrasi' => 'balanceAfterTransaction',

    // Karsi taraf: MUS.nameSurnameText HESAP SAHIBININ KENDISI'dir (kullanma!);
    // gercek karsi taraf corrNameSurnameText (EFT dahil ornek yanitlarla dogrulandi).
    'enrichment_list'      => 'enrichmentInformation',
    'enrichment_code_key'  => 'enrichmentCode',
    'enrichment_value_key' => 'enrichmentValue',
    'karsi_taraf_code'     => 'MUS',
    'karsi_taraf_fields'   => ['corrNameSurnameText'],

    // --- Account Information (getaccountinformation) — RESMI dokumandan, tahmin degil ---
    // Yanit: { result, accounts: [ { balances: [{type, Amount}], IBAN, accountNum, unitNum,
    //          currencyCode ("TL " — sonda bosluk olabilir, trim edilir), status, ... } ] }
    'ai_list'               => 'accounts',
    'ai_iban'               => 'IBAN',
    'ai_hesap_no'           => 'accountNum',
    'ai_sube'               => 'unitNum',
    'ai_para_birimi'        => 'currencyCode',
    'ai_balances'           => 'balances',
    'ai_balance_type_key'   => 'type',
    'ai_balance_amount_key' => 'Amount',
    'ai_balance_type'       => 'Balance',           // kapanis bakiyesi olarak kullanilir
    'ai_available_type'     => 'AvailableBalance',  // kullanilabilir bakiye
];
