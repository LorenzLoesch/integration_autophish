<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
$key = openssl_pkey_get_private(file_get_contents($argv[1]), '');
$cert = openssl_x509_read(file_get_contents($argv[2]));
$details = $key ? openssl_pkey_get_details($key) : false;
$subject = $cert ? openssl_x509_parse($cert) : false;
if (!$details || !$subject || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] !== 4096
    || ($subject['subject']['CN'] ?? '') !== 'integration_autophish'
    || $subject['validFrom_time_t'] > time() || $subject['validTo_time_t'] <= time()
    || !openssl_x509_check_private_key($cert, $key)) {
    fwrite(STDERR, "Signing requires a matching, valid integration_autophish certificate and unencrypted RSA-4096 key\n");
    exit(1);
}
