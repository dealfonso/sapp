<?php

namespace ddn\sapp\helpers;

/*
// File name   : x509.php
// Version     : 1.1
// Last Update : 2024-04-30
// Author      : Hida - https://github.com/hidasw
// License     : GNU GPLv3
*/

/**
 * @class x509
 * Perform some x509 operation
 */
class x509
{
    /*
     * create tsa request/query with nonce and cert req extension
     * @param string $binaryData raw/binary data of tsa query
     * @param string $hashAlg hash Algorithm
     * @return string binary tsa query
     * @public
     */
    public static function tsa_query($binaryData, $hashAlg = 'sha256'): false|string
    {
        $hashAlg = strtolower((string) $hashAlg);
        $hexOidHashAlgos = [
            'md2' => '06082A864886F70D0202',
            'md4' => '06082A864886F70D0204',
            'md5' => '06082A864886F70D0205',
            'sha1' => '06052B0E03021A',
            'sha224' => '0609608648016503040204',
            'sha256' => '0609608648016503040201',
            'sha384' => '0609608648016503040202',
            'sha512' => '0609608648016503040203',
        ];
        if (! array_key_exists($hashAlg, $hexOidHashAlgos)) {
            return false;
        }
        $hash = hash($hashAlg, (string) $binaryData);
        $tsReqData = asn1::seq(
            asn1::int(1) .
            asn1::seq(
                asn1::seq($hexOidHashAlgos[$hashAlg] . '0500') . // object OBJ $hexOidHashAlgos[$hashAlg] & OBJ_null
                asn1::oct($hash)
            ) .
            asn1::int(hash('crc32', random_int(0, mt_getrandmax())) . '001') . // tsa nonce
            '0101ff' // req return cert
        );

        return hex2bin($tsReqData);
    }

    /**
     * Parsing ocsp response data
     *
     * @param string $binaryOcspResp binary ocsp response
     *
     * @return array ocsp response structure
     */
    public static function ocsp_response_parse(string $binaryOcspResp, &$status = '')
    {
        $hex = current(unpack('H*', $binaryOcspResp));
        $parse = asn1::parse($hex, 10);
        if ($parse[0]['type'] == '30') {
            $ocsp = $parse[0];
        } else {
            return false;
        }
        foreach ($ocsp as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] === '0a') {
                    $ocsp['responseStatus'] = $value['value_hex'];
                    unset($ocsp[$key]);
                }
                if ($value['type'] === 'a0') {
                    $ocsp['responseBytes'] = $value;
                    unset($ocsp[$key]);
                }
            } else {
                unset($ocsp['depth']);
                unset($ocsp['type']);
                unset($ocsp['typeName']);
                unset($ocsp['value_hex']);
            }
        }
        //OCSPResponseStatus ::= ENUMERATED
        //    successful            (0),  --Response has valid confirmations
        //    malformedRequest      (1),  --Illegal confirmation request
        //    internalError         (2),  --Internal error in issuer
        //    tryLater              (3),  --Try again later
        //                                --(4) is not used
        //    sigRequired           (5),  --Must sign the request
        //    unauthorized          (6)   --Request unauthorized
        if (@$ocsp['responseStatus'] != '00') {
            $responseStatus['01'] = 'malformedRequest';
            $responseStatus['02'] = 'internalError';
            $responseStatus['03'] = 'tryLater';
            $responseStatus['05'] = 'sigRequired';
            $responseStatus['06'] = 'unauthorized';
            $status = @$responseStatus[$ocsp['responseStatus']];

            return false;
        }
        if (! @$curr = $ocsp['responseBytes']) {
            return false;
        }
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] == '30') {
                    $curr['responseType'] = self::oidfromhex($value[0]['value_hex']);
                    $curr['response'] = $value[1];
                    unset($curr[$key]);
                }
            } else {
                unset($curr['typeName']);
                unset($curr['type']);
                unset($curr['depth']);
            }
        }
        $ocsp['responseBytes'] = $curr;
        $curr = $ocsp['responseBytes']['response'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] == '30') {
                    $curr['BasicOCSPResponse'] = $value;
                    unset($curr[$key]);
                }
            } else {
                unset($curr['typeName']);
                unset($curr['type']);
                unset($curr['depth']);
            }
        }
        $ocsp['responseBytes']['response'] = $curr;
        $curr = $ocsp['responseBytes']['response']['BasicOCSPResponse'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] == '30' && ! array_key_exists('tbsResponseData', $curr)) {
                    $curr['tbsResponseData'] = $value;
                    unset($curr[$key]);
                    continue;
                }
                if ($value['type'] == '30' && ! array_key_exists('signatureAlgorithm', $curr)) {
                    $curr['signatureAlgorithm'] = $value[0]['value_hex'];
                    unset($curr[$key]);
                    continue;
                }
                if ($value['type'] == '03') {
                    $curr['signature'] = substr((string) $value['value_hex'], 2);
                    unset($curr[$key]);
                }
                if ($value['type'] === 'a0') {
                    foreach ($value[0] as $certsK => $certsV) {
                        if (is_numeric($certsK)) {
                            $certs[$certsK] = $certsV['value_hex'];
                        }
                    }
                    $curr['certs'] = $certs;
                    unset($curr[$key]);
                }
            } else {
                unset($curr['typeName']);
                unset($curr['type']);
                unset($curr['depth']);
            }
        }
        $ocsp['responseBytes']['response']['BasicOCSPResponse'] = $curr;
        $curr = $ocsp['responseBytes']['response']['BasicOCSPResponse']['tbsResponseData'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] === 'a0') {
                    $curr['version'] = $value[0]['value'];
                    unset($curr[$key]);
                }
                if ($value['type'] === 'a1' && ! array_key_exists('responderID', $curr)) {
                    $curr['responderID'] = $value;
                    unset($curr[$key]);
                }
                if ($value['type'] === 'a2') {
                    $curr['responderID'] = $value;
                    unset($curr[$key]);
                }
                if ($value['type'] == '18') {
                    $curr['producedAt'] = $value['value'];
                    unset($curr[$key]);
                }
                if ($value['type'] == '30') {
                    $curr['responses'] = $value;
                    unset($curr[$key]);
                }
                if ($value['type'] === 'a1') {
                    $curr['responseExtensions'] = $value;
                    unset($curr[$key]);
                }
            } else {
                unset($curr['typeName']);
                unset($curr['type']);
                unset($curr['depth']);
            }
        }
        $ocsp['responseBytes']['response']['BasicOCSPResponse']['tbsResponseData'] = $curr;
        $curr = $ocsp['responseBytes']['response']['BasicOCSPResponse']['tbsResponseData']['responseExtensions'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] == '30') {
                    $curr['lists'] = $value;
                    unset($curr[$key]);
                }
            } else {
                unset($curr['typeName']);
                unset($curr['type']);
                unset($curr['depth']);
            }
        }
        $ocsp['responseBytes']['response']['BasicOCSPResponse']['tbsResponseData']['responseExtensions'] = $curr;
        $curr = $ocsp['responseBytes']['response']['BasicOCSPResponse']['tbsResponseData']['responseExtensions']['lists'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] == '30') {
                    if ($value[0]['value_hex'] === '2b0601050507300102') { // nonce
                        $curr['nonce'] = $value[0]['value_hex'];
                    } else {
                        $curr[$value[0]['value_hex']] = $value[1];
                    }
                    unset($curr[$key]);
                }
            } else {
                unset($curr['typeName']);
                unset($curr['type']);
                unset($curr['depth']);
            }
        }
        $ocsp['responseBytes']['response']['BasicOCSPResponse']['tbsResponseData']['responseExtensions']['lists'] = $curr;
        $curr = $ocsp['responseBytes']['response']['BasicOCSPResponse']['tbsResponseData']['responses'];
        $i = 0;
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                foreach ($value as $SingleResponseK => $SingleResponseV) {
                    if (is_numeric($SingleResponseK)) {
                        if ($SingleResponseK == 0) {
                            foreach ($SingleResponseV as $certIDk => $certIDv) {
                                if (is_numeric($certIDk)) {
                                    if ($certIDv['type'] == '30') {
                                        $certID['hashAlgorithm'] = $certIDv[0]['value_hex'];
                                    }
                                    if ($certIDv['type'] == '04' && ! array_key_exists('issuerNameHash', $certID)) {
                                        $certID['issuerNameHash'] = $certIDv['value_hex'];
                                    }
                                    if ($certIDv['type'] == '04') {
                                        $certID['issuerKeyHash'] = $certIDv['value_hex'];
                                    }
                                    if ($certIDv['type'] == '02') {
                                        $certID['serialNumber'] = $certIDv['value_hex'];
                                    }
                                }
                            }
                            $cert['certID'] = $certID;
                        }
                        if ($SingleResponseK == 1) {
                            if ($SingleResponseV['type'] == '82') {
                                $certStatus = 'unknown';
                            } elseif ($SingleResponseV['type'] == '80') {
                                $certStatus = 'valid';
                            } else {
                                $certStatus = 'revoked';
                            }
                            $cert['certStatus'] = $certStatus;
                        }
                        if ($SingleResponseK == 2) {
                            $cert['thisUpdate'] = $SingleResponseV['value'];
                        }
                        if ($SingleResponseK == 3) {
                            $cert['nextUpdate'] = $SingleResponseV[0]['value'];
                        }
                        if ($SingleResponseK == 4) {
                            $cert['singleExtensions'] = $SingleResponseV;
                        }
                    }
                }
                $curr[$i] = $cert;
            } else {
                unset($curr[$key]);
                unset($curr['typeName']);
                unset($curr['type']);
                unset($curr['depth']);
            }
        }
        $ocsp['responseBytes']['response']['BasicOCSPResponse']['tbsResponseData']['responses'] = $curr;
        $arrModel = [
            'responseStatus' => '',
            'responseBytes' => [
                'response' => '',
                'responseType' => '',
            ],
        ];
        $differ = array_diff_key($arrModel, $ocsp);
        if (count($differ) == 0) {
            $differ = array_diff_key($arrModel['responseBytes'], $ocsp['responseBytes']);
            if (count($differ) > 0) {
                return false;
            }
        } else {
            return false;
        }

        return $ocsp;
    }

    /**
     * Create ocsp request
     *
     * @param string $serialNumber serial number to check
     * @param string $issuerNameHash sha1 hex form of issuer subject hash
     * @param string $issuerKeyHash sha1 hex form of issuer subject public info hash
     * @param bool|string $signer_cert cert to sign ocsp request
     * @param bool|string $signer_key privkey to sign ocsp request
     * @param bool|string $subjectName hex form of asn1 subject
     *
     * @return string hex form ocsp request
     */
    public static function ocsp_request(string $serialNumber, string $issuerNameHash, string $issuerKeyHash, bool|string $signer_cert = false, bool|string $signer_key = false, bool|string $subjectName = false)
    {
        $hashAlgorithm = asn1::seq(
            '06052B0E03021A' . // OBJ_sha1
            '0500'
        );
        $issuerNameHash = asn1::oct($issuerNameHash);
        $issuerKeyHash = asn1::oct($issuerKeyHash);
        $serialNumber = asn1::int($serialNumber);
        $CertID = asn1::seq($hashAlgorithm . $issuerNameHash . $issuerKeyHash . $serialNumber);
        $Request = asn1::seq($CertID); // one request
        if ($signer_cert) {
            $requestorName = asn1::expl('1', asn1::expl('4', $subjectName));
        } else {
            $requestorName = false;
        }
        $requestList = asn1::seq($Request); // add more request into sequence
        $rand = microtime(true) * random_int(0, mt_getrandmax());
        $nonce = md5(base64_encode($rand) . $rand);
        $ReqExts = asn1::seq(
            '06092B0601050507300102' . // OBJ_id_pkix_OCSP_Nonce
            asn1::oct('0410' . $nonce)
        );
        $requestExtensions = asn1::expl('2', asn1::seq($ReqExts));
        $TBSRequest = asn1::seq($requestorName . $requestList . $requestExtensions);
        $optionalSignature = '';
        if ($signer_cert) {
            if (! openssl_sign(hex2bin($TBSRequest), $signature_value, $signer_key)) {
                return false;
            }
            $signatureAlgorithm = asn1::seq(
                '06092A864886F70D010105' . // OBJ_sha1WithRSAEncryption.
                '0500'
            );
            $signature = asn1::bit('00' . bin2hex($signature_value));
            $signer_cert = self::x509_pem2der($signer_cert);
            $certs = asn1::expl('0', asn1::seq(bin2hex($signer_cert)));
            $optionalSignature = asn1::expl('0', asn1::seq($signatureAlgorithm . $signature . $certs));
        }

        return asn1::seq($TBSRequest . $optionalSignature);
    }

    /**
     * Convert crl from pem to der (binary)
     *
     * @param string $crl pem crl to convert
     *
     * @return string der crl form
     */
    public static function crl_pem2der(string $crl): false|string
    {
        $begin = '-----BEGIN X509 CRL-----';
        $end = '-----END X509 CRL-----';
        $beginPos = stripos($crl, $begin);
        if ($beginPos === false) {
            return false;
        }
        $crl = substr($crl, $beginPos + strlen($begin));
        $endPos = stripos($crl, $end);
        if ($endPos === false) {
            return false;
        }
        $crl = substr($crl, 0, $endPos);
        $crl = str_replace(["\n", "\r"], '', $crl);

        return base64_decode($crl, true);
    }

    /**
     * Read crl from pem or der (binary)
     *
     * @param string $crl pem or der crl
     *
     * @return array der crl and parsed crl
     */
    public static function crl_read(string $crl): false|array
    {
        if (! $crlparse = self::parsecrl($crl)) { // if cant read, thats not crl
            return false;
        }
        if (! $dercrl = self::crl_pem2der($crl)) { // if not pem, thats already der
            $dercrl = $crl;
        }
        $res['der'] = $dercrl;
        $res['parse'] = $crlparse;

        return $res;
    }

    /**
     * Convert x509 pem certificate to x509 der
     *
     * @param string $pem pem form cert
     *
     * @return string der form cert
     */
    public static function x509_pem2der(string $pem): string|false
    {
        $x509_der = false;
        if ($x509_res = @openssl_x509_read($pem)) {
            openssl_x509_export($x509_res, $x509_pem);
            $arr_x509_pem = explode("\n", $x509_pem);
            $numarr = count($arr_x509_pem);
            $i = 0;
            $cert_pem = false;
            foreach ($arr_x509_pem as $val) {
                if ($i > 0 && $i < ($numarr - 2)) {
                    $cert_pem .= $val;
                }
                $i++;
            }
            $x509_der = base64_decode($cert_pem, true);
        }

        return $x509_der;
    }

    /**
     * Convert x509 der certificate to x509 pem form
     *
     * @param string $der_cert der form cert
     *
     * @return string pem form cert
     */
    public static function x509_der2pem(string $der_cert): string
    {
        $x509_pem = "-----BEGIN CERTIFICATE-----\r\n";
        $x509_pem .= chunk_split(base64_encode($der_cert), 64);
        $x509_pem .= "-----END CERTIFICATE-----\r\n";

        return $x509_pem;
    }

    /**
     * get x.509 DER/PEM Certificate and return DER encoded x.509 Certificate
     *
     * @param string $certin pem/der form cert
     *
     * @return string der form cert
     */
    public static function get_cert(string $certin): string|false
    {
        if ($rsccert = @openssl_x509_read($certin)) {
            openssl_x509_export($rsccert, $cert);

            return self::x509_pem2der($cert);
        }
        $pem = @self::x509_der2pem($certin);
        if ($rsccert = @openssl_x509_read($pem)) {
            openssl_x509_export($rsccert, $cert);

            return self::x509_pem2der($cert);
        }
        return false;

    }

    /**
     * parse x.509 DER/PEM Certificate structure
     *
     * @param string $certin pem/der form cert
     * @param bool|string $oidprint show oid as oid number or hex
     *
     * @return array cert structure
     */
    public static function readcert($cert_in, bool|string $oidprint = false)
    {
        if (! $der = self::get_cert($cert_in)) {
            return false;
        }
        $hex = bin2hex($der);
        $curr = asn1::parse($hex, 10);
        foreach ($curr as $key => $value) {
            if ($value['type'] == '30') {
                $curr['cert'] = $curr[$key];
                unset($curr[$key]);
            }
        }
        $ar = $curr;
        $curr = $ar['cert'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] == '30' && ! array_key_exists('tbsCertificate', $curr)) {
                    $curr['tbsCertificate'] = $curr[$key];
                    unset($curr[$key]);
                }
                if ($value['type'] == '30') {
                    $curr['signatureAlgorithm'] = self::oidfromhex($value[0]['value_hex']);
                    unset($curr[$key]);
                }
                if ($value['type'] == '03') {
                    $curr['signatureValue'] = substr((string) $value['value'], 2);
                    unset($curr[$key]);
                }
            } else {
                unset($curr[$key]);
            }
        }
        $ar['cert'] = $curr;
        $ar['cert']['sha1Fingerprint'] = hash('sha1', $der);
        $curr = $ar['cert']['tbsCertificate'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] === 'a0') {
                    $curr['version'] = $value[0]['value'];
                    unset($curr[$key]);
                }
                if ($value['type'] == '02') {
                    $curr['serialNumber'] = $value['value'];
                    unset($curr[$key]);
                }
                if ($value['type'] == '30' && ! array_key_exists('signature', $curr)) {
                    $curr['signature'] = $value[0]['value_hex'];
                    unset($curr[$key]);
                    continue;
                }
                if ($value['type'] == '30' && ! array_key_exists('issuer', $curr)) {
                    foreach ($value as $issuerK => $issuerV) {
                        if (is_numeric($issuerK)) {
                            $issuerOID = $issuerV[0][0]['value_hex'];
                            if ($oidprint === 'oid') {
                                $issuerOID = self::oidfromhex($issuerOID);
                            } elseif ($oidprint === 'hex') {
                            } else {
                                $issuerOID = self::oidfromhex($issuerOID);
                            }
                            $issuer[$issuerOID][] = hex2bin((string) $issuerV[0][1]['value_hex']);
                        }
                    }
                    $hexdump = $value['hexdump'];
                    $issuer['sha1'] = hash('sha1', hex2bin((string) $hexdump));
                    $issuer['opensslHash'] = self::opensslSubjHash($hexdump);
                    $issuer['hexdump'] = $hexdump;
                    $curr['issuer'] = $issuer;
                    unset($curr[$key]);
                    continue;
                }
                if ($value['type'] == '30' && ! array_key_exists('validity', $curr)) {
                    $curr['validity']['notBefore'] = hex2bin((string) $value[0]['value_hex']);
                    $curr['validity']['notAfter'] = hex2bin((string) $value[1]['value_hex']);
                    unset($curr[$key]);
                    continue;
                }
                if ($value['type'] == '30' && ! array_key_exists('subject', $curr)) {
                    foreach ($value as $subjectK => $subjectV) {
                        if (is_numeric($subjectK)) {
                            $subjectOID = $subjectV[0][0]['value_hex'];
                            if ($oidprint === 'oid') {
                                $subjectOID = self::oidfromhex($subjectOID);
                            } elseif ($oidprint === 'hex') {
                            } else {
                                $subjectOID = self::oidfromhex($subjectOID);
                            }
                            $subject[$subjectOID][] = hex2bin((string) $subjectV[0][1]['value_hex']);
                        }
                    }
                    $hexdump = $value['hexdump'];
                    $subject['sha1'] = hash('sha1', hex2bin((string) $hexdump));
                    $subject['opensslHash'] = self::opensslSubjHash($hexdump);
                    $subject['hexdump'] = $hexdump;
                    $curr['subject'] = $subject;
                    unset($curr[$key]);
                    continue;
                }
                if ($value['type'] == '30' && ! array_key_exists('subjectPublicKeyInfo', $curr)) {
                    foreach ($value as $subjectPublicKeyInfoK => $subjectPublicKeyInfoV) {
                        if (is_numeric($subjectPublicKeyInfoK)) {
                            if ($subjectPublicKeyInfoV['type'] == '30') {
                                $subjectPublicKeyInfo['algorithm'] = self::oidfromhex($subjectPublicKeyInfoV[0]['value_hex']);
                            }
                            if ($subjectPublicKeyInfoV['type'] == '03') {
                                $subjectPublicKeyInfo['subjectPublicKey'] = substr((string) $subjectPublicKeyInfoV['value'], 2);
                            }
                        } else {
                            unset($curr[$key]);
                        }
                    }
                    $subjectPublicKeyInfo['hex'] = $value['hexdump'];
                    $subjectPublicKey_parse = asn1::parse($subjectPublicKeyInfo['subjectPublicKey']);
                    $subjectPublicKeyInfo['keyLength'] = (strlen(substr((string) $subjectPublicKey_parse[0][0]['value'], 2)) / 2) * 8;
                    $subjectPublicKeyInfo['sha1'] = hash('sha1', pack('H*', $subjectPublicKeyInfo['subjectPublicKey']));
                    $curr['subjectPublicKeyInfo'] = $subjectPublicKeyInfo;
                    unset($curr[$key]);
                    continue;
                }
                if ($value['type'] === 'a3') {
                    $curr['attributes'] = $value[0];
                    unset($curr[$key]);
                }
            } else {
                $tbsCertificateTag[$key] = $value;
            }
        }
        $ar['cert']['tbsCertificate'] = $curr;
        if (array_key_exists('attributes', $ar['cert']['tbsCertificate'])) {
            $curr = $ar['cert']['tbsCertificate']['attributes'];
            foreach ($curr as $key => $value) {
                if (is_numeric($key)) {
                    if ($value['type'] == '30') {
                        $critical = 0;
                        $extvalue = $value[1];
                        $name_hex = $value[0]['value_hex'];
                        if ($value[1]['type'] == '01' && $value[1]['value_hex'] === 'ff') {
                            $critical = 1;
                            $extvalue = $value[2];
                        }
                        if ($name_hex === '551d0e') { // OBJ_subject_key_identifier
                            $extvalue = $value[1][0]['value_hex'];
                        }
                        if ($name_hex === '551d23') { // OBJ_authority_key_identifier
                            foreach ($value[1][0] as $OBJ_authority_key_identifierKey => $OBJ_authority_key_identifierVal) {
                                if (is_numeric($OBJ_authority_key_identifierKey)) {
                                    if ($OBJ_authority_key_identifierVal['type'] == '80') {
                                        $OBJ_authority_key_identifier['keyid'] = $OBJ_authority_key_identifierVal['value_hex'];
                                    }
                                    if ($OBJ_authority_key_identifierVal['type'] === 'a1') {
                                        $OBJ_authority_key_identifier['issuerName'] = $OBJ_authority_key_identifierVal['value_hex'];
                                    }
                                    if ($OBJ_authority_key_identifierVal['type'] == '82') {
                                        $OBJ_authority_key_identifier['issuerSerial'] = $OBJ_authority_key_identifierVal['value_hex'];
                                    }
                                }
                            }
                            $extvalue = $OBJ_authority_key_identifier;
                        }
                        if ($name_hex === '2b06010505070101') { // OBJ_info_access
                            foreach ($value[1][0] as $OBJ_info_accessK => $OBJ_info_accessV) {
                                if (is_numeric($OBJ_info_accessK)) {
                                    $OBJ_info_accessHEX = $OBJ_info_accessV[0]['value_hex'];
                                    $OBJ_info_accessOID = self::oidfromhex($OBJ_info_accessHEX);
                                    $OBJ_info_accessNAME = $OBJ_info_accessOID;
                                    $OBJ_info_access[$OBJ_info_accessNAME][] = hex2bin((string) $OBJ_info_accessV[1]['value_hex']);
                                }
                            }
                            $extvalue = $OBJ_info_access;
                        }
                        if ($name_hex === '551d1f') { // OBJ_crl_distribution_points 551d1f
                            foreach ($value[1][0] as $OBJ_crl_distribution_pointsK => $OBJ_crl_distribution_pointsV) {
                                if (is_numeric($OBJ_crl_distribution_pointsK)) {
                                    $OBJ_crl_distribution_points[] = hex2bin((string) $OBJ_crl_distribution_pointsV[0][0][0]['value_hex']);
                                }
                            }
                            $extvalue = $OBJ_crl_distribution_points;
                        }
                        if ($name_hex === '551d0f') { // OBJ_key_usage
                            // $extvalue = self::parse_keyUsage($extvalue[0]['value']);
                        }
                        if ($name_hex === '551d13') { // OBJ_basic_constraints
                            $bc['ca'] = '0';
                            $bc['pathLength'] = '';
                            foreach ($extvalue[0] as $bck => $bcv) {
                                if (is_numeric($bck)) {
                                    if ($bcv['type'] == '01') {
                                        if ($bcv['value_hex'] === 'ff') {
                                            $bc['ca'] = '1';
                                        }
                                    }
                                    if ($bcv['type'] == '02') {
                                        $bc['pathLength'] = $bcv['value'];
                                    }
                                }
                            }
                            $extvalue = $bc;
                        }
                        if ($name_hex === '551d25') { // OBJ_ext_key_usage 551d1f
                            foreach ($extvalue[0] as $OBJ_ext_key_usageK => $OBJ_ext_key_usageV) {
                                if (is_numeric($OBJ_ext_key_usageK)) {
                                    $OBJ_ext_key_usageHEX = $OBJ_ext_key_usageV['value_hex'];
                                    $OBJ_ext_key_usageOID = self::oidfromhex($OBJ_ext_key_usageHEX);
                                    $OBJ_ext_key_usageNAME = $OBJ_ext_key_usageOID;
                                    $OBJ_ext_key_usage[] = $OBJ_ext_key_usageNAME;
                                }
                            }
                            $extvalue = $OBJ_ext_key_usage;
                        }
                        $extsVal = [
                            'name_hex' => $value[0]['value_hex'],
                            'name_oid' => self::oidfromhex($value[0]['value_hex']),
                            'name' => self::oidfromhex($value[0]['value_hex']),
                            'critical' => $critical,
                            'value' => $extvalue,
                        ];
                        $extNameOID = $value[0]['value_hex'];
                        if ($oidprint === 'oid') {
                            $extNameOID = self::oidfromhex($extNameOID);
                        } elseif ($oidprint === 'hex') {
                        } else {
                            $extNameOID = self::oidfromhex($extNameOID);
                        }
                        $curr[$extNameOID] = $extsVal;
                        unset($curr[$key]);
                    }
                } else {
                    unset($curr[$key]);
                }
                unset($ar['cert']['tbsCertificate']['attributes']);
                $ar['cert']['tbsCertificate']['attributes'] = $curr;
            }
        }

        return $ar['cert'];
    }

    /**
     * Calculate 32bit (8 hex) openssl subject hash old and new
     *
     * @param string $hex_subjSequence hex subject name sequence
     *
     * @return array subject hash old and new
     */
    private static function opensslSubjHash(string $hex_subjSequence): array
    {
        $parse = asn1::parse($hex_subjSequence, 3);
        $hex_subjSequence_new = '';
        foreach ($parse[0] as $k => $v) {
            if (is_numeric($k)) {
                $hex_subjSequence_new .= asn1::set(
                    asn1::seq(
                        $v[0][0]['hexdump'] .
                        asn1::utf8(strtolower(hex2bin((string) $v[0][1]['value_hex'])))
                    )
                );
            }
        }
        $tohash = pack('H*', $hex_subjSequence_new);
        $openssl_subjHash_new = hash('sha1', $tohash);
        $openssl_subjHash_new = substr($openssl_subjHash_new, 0, 8);
        $openssl_subjHash_new2 = str_split($openssl_subjHash_new, 2);
        $openssl_subjHash_new2 = array_reverse($openssl_subjHash_new2);
        $openssl_subjHash_new = implode('', $openssl_subjHash_new2);
        $openssl_subjHash_old = hash('md5', hex2bin($hex_subjSequence));
        $openssl_subjHash_old = substr($openssl_subjHash_old, 0, 8);
        $openssl_subjHash_old2 = str_split($openssl_subjHash_old, 2);
        $openssl_subjHash_old2 = array_reverse($openssl_subjHash_old2);
        $openssl_subjHash_old = implode('', $openssl_subjHash_old2);

        return [
            'old' => $openssl_subjHash_old,
            'new' => $openssl_subjHash_new,
        ];
    }

    /**
     * parsing crl from pem or der (binary)
     *
     * @param string $crl pem or der crl
     * @param string $oidprint option show obj as hex/oid
     *
     * @return array parsed crl
     */
    private static function parsecrl(array $crl, bool $oidprint = false): false|array
    {
        if ($derCrl = self::crl_pem2der($crl)) {
            $derCrl = bin2hex($derCrl);
        } else {
            $derCrl = bin2hex($crl);
        }
        $curr = asn1::parse($derCrl, 7);
        foreach ($curr as $key => $value) {
            if ($value['type'] == '30') {
                $curr['crl'] = $curr[$key];
                unset($curr[$key]);
            }
        }
        $ar = $curr;
        if (! array_key_exists('crl', $ar)) {
            return false;
        }
        $curr = $ar['crl'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] == '30' && ! array_key_exists('TBSCertList', $curr)) {
                    $curr['TBSCertList'] = $curr[$key];
                    unset($curr[$key]);
                }
                if ($value['type'] == '30') {
                    $curr['signatureAlgorithm'] = self::oidfromhex($value[0]['value_hex']);
                    unset($curr[$key]);
                }
                if ($value['type'] == '03') {
                    $curr['signature'] = substr((string) $value['value'], 2);
                    unset($curr[$key]);
                }
            } else {
                unset($curr[$key]);
            }
        }
        $ar['crl'] = $curr;
        $curr = $ar['crl']['TBSCertList'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] == '02') {
                    $curr['version'] = $curr[$key]['value'];
                    unset($curr[$key]);
                }
                if ($value['type'] == '30' && ! array_key_exists('signature', $curr)) {
                    $curr['signature'] = $value[0]['value_hex'];
                    unset($curr[$key]);
                    continue;
                }
                if ($value['type'] == '30' && ! array_key_exists('issuer', $curr)) {
                    $curr['issuer'] = $value;
                    unset($curr[$key]);
                    continue;
                }
                if ($value['type'] == '17' && ! array_key_exists('thisUpdate', $curr)) {
                    $curr['thisUpdate'] = hex2bin((string) $value['value_hex']);
                    unset($curr[$key]);
                    continue;
                }
                if ($value['type'] == '17' && ! array_key_exists('nextUpdate', $curr)) {
                    $curr['nextUpdate'] = hex2bin((string) $value['value_hex']);
                    unset($curr[$key]);
                    continue;
                }
                if ($value['type'] == '30' && ! array_key_exists('revokedCertificates', $curr)) {
                    $curr['revokedCertificates'] = $value;
                    unset($curr[$key]);
                    continue;
                }
                if ($value['type'] === 'a0') {
                    $curr['crlExtensions'] = $curr[$key];
                    unset($curr[$key]);
                }
            } else {
                unset($curr[$key]);
            }
        }
        $ar['crl']['TBSCertList'] = $curr;
        if (array_key_exists('revokedCertificates', $curr)) {
            $curr = $ar['crl']['TBSCertList']['revokedCertificates'];
            foreach ($curr as $key => $value) {
                if (is_numeric($key)) {
                    if ($value['type'] == '30') {
                        $serial = $value[0]['value'];
                        $revoked['time'] = hex2bin((string) $value[1]['value_hex']);
                        $lists[$serial] = $revoked;
                        unset($curr[$key]);
                    }
                } else {
                    unset($curr['depth']);
                    unset($curr['type']);
                    unset($curr['typeName']);
                }
            }
            $curr['lists'] = $lists;
            $ar['crl']['TBSCertList']['revokedCertificates'] = $curr;
        }
        if (array_key_exists('crlExtensions', $ar['crl']['TBSCertList'])) {
            $curr = $ar['crl']['TBSCertList']['crlExtensions'][0];
            unset($ar['crl']['TBSCertList']['crlExtensions']);
            foreach ($curr as $key => $value) {
                if (is_numeric($key)) {
                    $attributes_name = self::oidfromhex($value[0]['value_hex']);
                    if ($oidprint === 'oid') {
                        $attributes_name = self::oidfromhex($value[0]['value_hex']);
                    }
                    if ($oidprint === 'hex') {
                        $attributes_name = $value[0]['value_hex'];
                    }
                    $attributes_oid = self::oidfromhex($value[0]['value_hex']);
                    if ($value['type'] == '30') {
                        $crlExtensionsValue = $value[1][0];
                        if ($attributes_oid === '2.5.29.20') { // OBJ_crl_number
                            $crlExtensionsValue = $crlExtensionsValue['value'];
                        }
                        if ($attributes_oid === '2.5.29.35') { // OBJ_authority_key_identifier
                            foreach ($crlExtensionsValue as $authority_key_identifierValueK => $authority_key_identifierV) {
                                if (is_numeric($authority_key_identifierValueK)) {
                                    if ($authority_key_identifierV['type'] == '80') {
                                        $authority_key_identifier['keyIdentifier'] = $authority_key_identifierV['value_hex'];
                                    }
                                    if ($authority_key_identifierV['type'] === 'a1') {
                                        $authority_key_identifier['authorityCertIssuer'] = $authority_key_identifierV['value_hex'];
                                    }
                                    if ($authority_key_identifierV['type'] == '82') {
                                        $authority_key_identifier['authorityCertSerialNumber'] = $authority_key_identifierV['value_hex'];
                                    }
                                }
                            }
                            $crlExtensionsValue = $authority_key_identifier;
                        }
                        $attribute_list = $crlExtensionsValue;
                    }
                    $ar['crl']['TBSCertList']['crlExtensions'][$attributes_name] = $attribute_list;
                }
            }
        }
        $curr = $ar['crl']['TBSCertList']['issuer'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] == '31') {
                    if ($oidprint === 'oid') {
                        $subjOID = self::oidfromhex($curr[$key][0][0]['value_hex']);
                    } elseif ($oidprint === 'hex') {
                        $subjOID = $curr[$key][0][0]['value_hex'];
                    } else {
                        $subjOID = self::oidfromhex($curr[$key][0][0]['value_hex']);
                    }
                    $curr[$subjOID][] = hex2bin((string) $curr[$key][0][1]['value_hex']);
                    unset($curr[$key]);
                }
            } else {
                unset($curr['depth']);
                unset($curr['type']);
                unset($curr['typeName']);
                if ($key === 'hexdump') {
                    $curr['sha1'] = hash('sha1', pack('H*', $value));
                }
            }
        }
        $ar['crl']['TBSCertList']['issuer'] = $curr;
        $arrModel['TBSCertList']['version'] = '';
        $arrModel['TBSCertList']['signature'] = '';
        $arrModel['TBSCertList']['issuer'] = '';
        $arrModel['TBSCertList']['thisUpdate'] = '';
        $arrModel['TBSCertList']['nextUpdate'] = '';
        $arrModel['signatureAlgorithm'] = '';
        $arrModel['signature'] = '';
        $crl = $ar['crl'];
        $differ = array_diff_key($arrModel, $crl);
        if (count($differ) == 0) {
            $differ = array_diff_key($arrModel['TBSCertList'], $crl['TBSCertList']);
            if (count($differ) > 0) {
                return false;
            }
        } else {
            foreach ($differ as $val) {
            }

            return false;
        }

        return $ar['crl'];
    }

    /**
     * read oid number of given hex (convert hex to oid)
     *
     * @param string $hex hex form oid number
     *
     * @return string oid number
     */
    private static function oidfromhex(string $hex): string
    {
        $split = str_split($hex, 2);
        $i = 0;
        foreach ($split as $val) {
            $dec = hexdec($val);
            $mplx[$i] = ($dec - 128) * 128;
            $i++;
        }
        $i = 0;
        $nex = false;
        $result = false;
        foreach ($split as $val) {
            $dec = hexdec($val);
            if ($i === 0) {
                if ($dec >= 128) {
                    $nex = (128 * ($dec - 128)) - 80;
                    if ($dec > 129) {
                        $nex = (128 * ($dec - 128)) - 80;
                    }
                    $result = '2.';
                }
                if ($dec >= 80 && $dec < 128) {
                    $first = $dec - 80;
                    $result = "2.{$first}.";
                }
                if ($dec >= 40 && $dec < 80) {
                    $first = $dec - 40;
                    $result = "1.{$first}.";
                }
                if ($dec < 40) {
                    $first = $dec;
                    $result = "0.{$first}.";
                }
            } else {
                if ($dec > 127) {
                    if ($nex == false) {
                        $nex = $mplx[$i];
                    } else {
                        $nex = ($nex * 128) + $mplx[$i];
                    }
                } else {
                    $result .= ($dec + $nex) . '.';
                    if ($dec <= 127) {
                        $nex = 0;
                    }
                }
            }
            $i++;
        }

        return rtrim($result, '.');
    }
}
