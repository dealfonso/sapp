<?php

namespace ddn\sapp\helpers;

/*
// File name   : CMS.php
// Version     : 1.1
// Last Update : 2024-05-02
// Author      : Hida - https://github.com/hidasw
// License     : GNU GPLv3
*/

use ddn\sapp\PDFException;
use Psr\Log\LoggerInterface;

/**
 * @class cms
 * Manage CMS(Cryptographic Message Syntax) Signature for SAPP PDF
 */
class CMS
{
    public $signature_data;

    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * send tsa/ocsp query with curl
     *
     * @return string response body
     * @public
     */
    public function sendReq(array $reqData): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $reqData['uri']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: ' . $reqData['req_contentType'], 'User-Agent: SAPP PDF']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $reqData['data']);
        if (($reqData['user'] ?? null) && ($reqData['password'] ?? null)) {
            curl_setopt($ch, CURLOPT_USERPWD, $reqData['user'] . ':' . $reqData['password']);
        }

        $tsResponse = curl_exec($ch);

        if (! $tsResponse) {
            throw new PDFException('empty curl response');
        }

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $header = substr($tsResponse, 0, $header_size);
        $body = substr($tsResponse, $header_size);
        // Get the HTTP response code
        $headers = explode("\n", $header);
        foreach ($headers as $r) {
            if (stripos($r, 'HTTP/') === 0) {
                [, $code, $status] = explode(' ', $r, 3);
                break;
            }
        }

        if ($code !== '200') {
            throw new PDFException(sprintf('response error! Code="{$code}", Status="%s"', trim($status ?? '')));
        }

        $contentTypeHeader = '';
        $headers = explode("\n", $header);
        foreach ($headers as $r) {
            // Match the header name up to ':', compare lower case
            if (stripos($r, 'Content-Type:') === 0) {
                [, $headervalue] = explode(':', $r, 2);
                $contentTypeHeader = trim($headervalue);
            }
        }

        if ($contentTypeHeader != $reqData['resp_contentType']) {
            throw new PDFException(sprintf('response content type not %s, but: "%s"', $reqData['resp_contentType'], $contentTypeHeader));
        }

        if ($body === '' || $body === '0') {
            throw new PDFException('error empty response!');
        }

        return $body; // binary response
    }

    /**
     * Perform PKCS7 Signing
     *
     * @return string hex + padding 0
     * @public
     */
    public function pkcs7_sign(string $binaryData): string
    {
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
        $hashAlgorithm = $this->signature_data['hashAlgorithm'];
        if (! array_key_exists($hashAlgorithm, $hexOidHashAlgos)) {
            throw new PDFException('not support hash algorithm!');
        }

        $this->logger?->debug(sprintf('hash algorithm is "%s"', $hashAlgorithm));
        $x509 = new x509();
        if (! $certParse = $x509::readcert($this->signature_data['signcert'])) {
            throw new PDFException('certificate error! check certificate');
        }

        $hexEmbedCerts[] = bin2hex($x509::get_cert($this->signature_data['signcert']));
        $appendLTV = '';
        $ltvData = $this->signature_data['ltv'];
        if (! empty($ltvData)) {
            $this->logger?->debug('  LTV Validation start...');
            $LTVvalidation_ocsp = '';
            $LTVvalidation_crl = '';
            $LTVvalidationEnd = false;

            $isRootCA = false;
            // check whether root ca
            if ($certParse['tbsCertificate']['issuer']['hexdump'] == $certParse['tbsCertificate']['subject']['hexdump'] && openssl_public_decrypt(hex2bin((string) $certParse['signatureValue']), $decrypted, x509::x509_der2pem($x509::get_cert($this->signature_data['signcert'])), OPENSSL_PKCS1_PADDING)) {
                $this->logger?->debug(sprintf('***** "%s" is a ROOT CA. No validation performed ***', $certParse['tbsCertificate']['subject']['2.5.4.3'][0]));
                $isRootCA = true;
            }

            if ($isRootCA == false) {
                $i = 0;
                $LTVvalidation = true;
                $certtoCheck = $certParse;
                while ($LTVvalidation !== false) {
                    $this->logger?->debug(sprintf('========= %d checking "%s"===============', $i, $certtoCheck['tbsCertificate']['subject']['2.5.4.3'][0]));
                    $LTVvalidation = $this->LTVvalidation($certtoCheck);
                    $i++;
                    if ($LTVvalidation) {
                        $curr_issuer = $LTVvalidation['issuer'];
                        $certtoCheck = $x509::readcert($curr_issuer, 'oid');
                        if (@$LTVvalidation['ocsp'] || @$LTVvalidation['crl']) {
                            $LTVvalidation_ocsp .= $LTVvalidation['ocsp'];
                            $LTVvalidation_crl .= $LTVvalidation['crl'];
                            $hexEmbedCerts[] = bin2hex((string) $LTVvalidation['issuer']);
                        }

                        // check whether root ca
                        if ($certtoCheck['tbsCertificate']['issuer']['hexdump'] == $certtoCheck['tbsCertificate']['subject']['hexdump'] && openssl_public_decrypt(hex2bin((string) $certtoCheck['signatureValue']), $decrypted, $x509::x509_der2pem($curr_issuer), OPENSSL_PKCS1_PADDING)) {
                            $this->logger?->debug(sprintf('========= FINISH Reached ROOT CA "%s"===============', $certtoCheck['tbsCertificate']['subject']['2.5.4.3'][0]));
                            $LTVvalidationEnd = true;
                            break;
                        }
                    }
                }

                if ($LTVvalidationEnd) {
                    $this->logger?->debug("  LTV Validation SUCCESS\n");
                    $ocsp = '';
                    if ($LTVvalidation_ocsp !== '' && $LTVvalidation_ocsp !== '0') {
                        $ocsp = asn1::expl(
                            1,
                            asn1::seq(
                                $LTVvalidation_ocsp
                            )
                        );
                    }

                    $crl = '';
                    if ($LTVvalidation_crl !== '' && $LTVvalidation_crl !== '0') {
                        $crl = asn1::expl(
                            0,
                            asn1::seq(
                                $LTVvalidation_crl
                            )
                        );
                    }

                    $appendLTV = asn1::seq(
                        '06092A864886F72F010108' . // adbe-revocationInfoArchival (1.2.840.113583.1.1.8)
                        asn1::set(
                            asn1::seq(
                                $ocsp .
                                $crl
                            )
                        )
                    );
                } else {
                    $this->logger?->warning("LTV Validation FAILED!\n");
                }
            }

            foreach ($this->signature_data['extracerts'] ?? [] as $extracert) {
                $hex_extracert = bin2hex($x509::x509_pem2der($extracert));
                if (! in_array($hex_extracert, $hexEmbedCerts, true)) {
                    $hexEmbedCerts[] = $hex_extracert;
                }
            }
        }

        $messageDigest = hash($hashAlgorithm, $binaryData);
        $authenticatedAttributes = asn1::seq(
            '06092A864886F70D010903' . //OBJ_pkcs9_contentType 1.2.840.113549.1.9.3
                asn1::set('06092A864886F70D010701')  //OBJ_pkcs7_data 1.2.840.113549.1.7.1
        ) .
            asn1::seq( // signing time
                '06092A864886F70D010905' . //OBJ_pkcs9_signingTime 1.2.840.113549.1.9.5
                asn1::set(
                    asn1::utime(date('ymdHis')) //UTTC Time
                )
            ) .
            asn1::seq( // messageDigest
                '06092A864886F70D010904' . //OBJ_pkcs9_messageDigest 1.2.840.113549.1.9.4
                asn1::set(asn1::oct($messageDigest))
            ) .
            $appendLTV;
        $tohash = asn1::set($authenticatedAttributes);
        $hash = hash($hashAlgorithm, hex2bin($tohash));
        $toencrypt = asn1::seq(
            asn1::seq($hexOidHashAlgos[$hashAlgorithm] . '0500') .  // OBJ $messageDigest & OBJ_null
            asn1::oct($hash)
        );
        $pkey = $this->signature_data['privkey'];
        if (! openssl_private_encrypt(hex2bin($toencrypt), $encryptedDigest, $pkey, OPENSSL_PKCS1_PADDING)) {
            throw new PDFException("openssl_private_encrypt error! can't encrypt");
        }

        $hexencryptedDigest = bin2hex($encryptedDigest);
        $timeStamp = '';
        if (! empty($this->signature_data['tsa'])) {
            $this->logger?->debug('  Timestamping process start...');
            if ($TSTInfo = $this->createTimestamp($encryptedDigest, $hashAlgorithm)) {
                $this->logger?->debug('  Timestamping SUCCESS.');
                $TimeStampToken = asn1::seq(
                    '060B2A864886F70D010910020E' . // OBJ_id_smime_aa_timeStampToken 1.2.840.113549.1.9.16.2.14
                    asn1::set($TSTInfo)
                );
                $timeStamp = asn1::expl(1, $TimeStampToken);
            } else {
                $this->logger?->warning('Timestamping FAILED!');
            }
        }

        $issuerName = $certParse['tbsCertificate']['issuer']['hexdump'];
        $serialNumber = $certParse['tbsCertificate']['serialNumber'];
        $signerinfos = asn1::seq(
            asn1::int('1') .
            asn1::seq($issuerName . asn1::int($serialNumber)) .
            asn1::seq($hexOidHashAlgos[$hashAlgorithm] . '0500') .
            asn1::expl(0, $authenticatedAttributes) .
            asn1::seq(
                '06092A864886F70D0101010500'
            ) .
            asn1::oct($hexencryptedDigest) .
            $timeStamp
        );
        $certs = asn1::expl(0, implode('', $hexEmbedCerts));
        $pkcs7contentSignedData = asn1::seq(
            asn1::int('1') .
            asn1::set(asn1::seq($hexOidHashAlgos[$hashAlgorithm] . '0500')) .
            asn1::seq('06092A864886F70D010701') . //OBJ_pkcs7_data
            $certs .
            asn1::set($signerinfos)
        );

        return asn1::seq(
            '06092A864886F70D010702' . // Hexadecimal form of pkcs7-signedData
            asn1::expl(0, $pkcs7contentSignedData)
        );
    }

    /**
     * Create timestamp query
     *
     * @param string $data binary data to hashed/digested
     * @param string $hashAlg hash algorithm
     *
     * @return string hex TSTinfo.
     */
    protected function createTimestamp(string $data, string $hashAlg = 'sha1')
    {
        $tsaQuery = x509::tsa_query($data, $hashAlg);
        $tsaData = $this->signature_data['tsa'];
        $reqData = [
            'data' => $tsaQuery,
            'uri' => $tsaData['host'],
            'req_contentType' => 'application/timestamp-query',
            'resp_contentType' => 'application/timestamp-reply',
        ] + $tsaData;

        $this->logger?->debug('    sending TSA query to "' . $tsaData['host'] . '"...');
        if (($binaryTsaResp = $this->sendReq($reqData)) === '' || ($binaryTsaResp = $this->sendReq($reqData)) === '0') {
            throw new PDFException('TSA query send FAILED!');
        }

        $this->logger?->debug('      TSA query send OK');
        $this->logger?->debug('    Parsing Timestamp response...');
        if (! $tsaResp = $this->tsa_parseResp($binaryTsaResp)) {
            throw new PDFException('parsing FAILED!');
        }

        $this->logger?->debug('    parsing OK');

        return $tsaResp['TimeStampResp']['timeStampToken']['hexdump'];
    }

    /**
     * Perform OCSP/CRL Validation
     *
     * @param array $parsedCert parsed certificate
     *
     * @return array
     */
    protected function LTVvalidation(array $parsedCert): false|array
    {
        $ltvResult['issuer'] = false;
        $ltvResult['ocsp'] = false;
        $ltvResult['crl'] = false;
        $certSigner_parse = $parsedCert;
        $this->logger?->debug('    getting OCSP & CRL address...');
        $this->logger?->debug('      reading AIA OCSP attribute...');
        $ocspURI = @$certSigner_parse['tbsCertificate']['attributes']['1.3.6.1.5.5.7.1.1']['value']['1.3.6.1.5.5.7.48.1'][0];
        if (trim((string) $ocspURI) === '' || trim((string) $ocspURI) === '0') {
            $this->logger?->warning('FAILED!');
        } else {
            $this->logger?->debug(sprintf('OK got address:"%s"', $ocspURI));
        }

        $ocspURI = trim((string) $ocspURI);
        $this->logger?->debug('      reading CRL CDP attribute...');
        $crlURIorFILE = @$certSigner_parse['tbsCertificate']['attributes']['2.5.29.31']['value'][0];
        if (trim($crlURIorFILE ?? '') === '' || trim($crlURIorFILE ?? '') === '0') {
            $this->logger?->warning('FAILED!');
        } else {
            $this->logger?->debug(sprintf('OK got address:"%s"', $crlURIorFILE));
        }

        if (($ocspURI === '' || $ocspURI === '0') && empty($crlURIorFILE)) {
            throw new PDFException("can't get OCSP/CRL address! Process terminated.");
        }

        // Perform if either ocspURI/crlURIorFILE exists
        $this->logger?->debug('    getting Issuer...');
        $this->logger?->debug('      looking for issuer address from AIA attribute...');
        $issuerURIorFILE = @$certSigner_parse['tbsCertificate']['attributes']['1.3.6.1.5.5.7.1.1']['value']['1.3.6.1.5.5.7.48.2'][0];
        $issuerURIorFILE = trim($issuerURIorFILE ?? '');
        if ($issuerURIorFILE === '' || $issuerURIorFILE === '0') {
            $this->logger?->debug('Failed!');
        } else {
            $this->logger?->debug(sprintf('OK got address "%s"...', $issuerURIorFILE));
            $this->logger?->debug(sprintf('      load issuer from "%s"...', $issuerURIorFILE));
            if ($issuerCert = @file_get_contents($issuerURIorFILE)) {
                $this->logger?->debug('OK. size ' . round(strlen($issuerCert) / 1024, 2) . 'Kb');
                $this->logger?->debug('      reading issuer certificate...');
                if ($issuer_certDER = x509::get_cert($issuerCert)) {
                    $this->logger?->debug('OK');
                    $this->logger?->debug('      check if issuer is cert issuer...');
                    $certIssuer_parse = x509::readcert($issuer_certDER, 'oid'); // Parsing Issuer cert
                    $certSigner_signatureField = $certSigner_parse['signatureValue'];
                    if (openssl_public_decrypt(hex2bin((string) $certSigner_signatureField), $decrypted, x509::x509_der2pem($issuer_certDER), OPENSSL_PKCS1_PADDING)) {
                        $this->logger?->debug('OK issuer is cert issuer.');
                        $ltvResult['issuer'] = $issuer_certDER;
                    } else {
                        $this->logger?->warning('FAILED! issuer is not cert issuer.');
                    }
                } else {
                    $this->logger?->warning('FAILED!');
                }
            } else {
                $this->logger?->warning('FAILED!.');
            }
        }

        if (! $ltvResult['issuer']) {
            $this->logger?->debug('      search for issuer in extracerts.....');
            if (array_key_exists('extracerts', $this->signature_data) && $this->signature_data['extracerts'] !== null && count($this->signature_data['extracerts']) > 0) {
                $i = 0;
                foreach ($this->signature_data['extracerts'] as $extracert) {
                    $this->logger?->debug(sprintf('extracerts[%d] ...', $i));
                    $certSigner_signatureField = $certSigner_parse['signatureValue'];
                    if (openssl_public_decrypt(hex2bin((string) $certSigner_signatureField), $decrypted, $extracert, OPENSSL_PKCS1_PADDING)) {
                        $this->logger?->debug('  OK got issuer.');
                        $certIssuer_parse = x509::readcert($extracert, 'oid'); // Parsing Issuer cert
                        $ltvResult['issuer'] = x509::get_cert($extracert);
                    } else {
                        $this->logger?->debug('  FAIL!');
                    }

                    $i++;
                }
            } else {
                throw new PDFException('FAILED! no extracerts available');
            }
        }

        if ($ltvResult['issuer']) {
            if ($ocspURI !== '' && $ocspURI !== '0') {
                $this->logger?->debug('    OCSP start...');
                $ocspReq_serialNumber = $certSigner_parse['tbsCertificate']['serialNumber'];
                $ocspReq_issuerNameHash = $certIssuer_parse['tbsCertificate']['subject']['sha1'];
                $ocspReq_issuerKeyHash = $certIssuer_parse['tbsCertificate']['subjectPublicKeyInfo']['sha1'];
                $this->logger?->debug('      OCSP create request...');
                if ($ocspReq = x509::ocsp_request($ocspReq_serialNumber, $ocspReq_issuerNameHash, $ocspReq_issuerKeyHash)) {
                    $this->logger?->debug('OK.');
                    $ocspBinReq = pack('H*', $ocspReq);
                    $reqData = [
                        'data' => $ocspBinReq,
                        'uri' => $ocspURI,
                        'req_contentType' => 'application/ocsp-request',
                        'resp_contentType' => 'application/ocsp-response',
                    ];
                    $this->logger?->debug(sprintf('      OCSP send request to "%s"...', $ocspURI));
                    if (($ocspResp = $this->sendReq($reqData)) !== '' && ($ocspResp = $this->sendReq($reqData)) !== '0') {
                        $this->logger?->debug('OK.');
                        $this->logger?->debug('      OCSP parsing response...');
                        if ($ocsp_parse = x509::ocsp_response_parse($ocspResp, $return)) {
                            $this->logger?->debug('OK.');
                            $this->logger?->debug('      OCSP check cert validity...');
                            $certStatus = $ocsp_parse['responseBytes']['response']['BasicOCSPResponse']['tbsResponseData']['responses'][0]['certStatus'];
                            if ($certStatus === 'valid') {
                                $this->logger?->debug('OK. VALID.');
                                $ocspRespHex = $ocsp_parse['hexdump'];
                                $ltvResult['ocsp'] = $ocspRespHex;
                            } else {
                                $this->logger?->warning('FAILED! cert not valid, status:"' . strtoupper((string) $certStatus) . '"');
                            }
                        } else {
                            $this->logger?->warning(sprintf('FAILED! Ocsp server status "%s"', $return));
                        }
                    } else {
                        $this->logger?->warning('FAILED!');
                    }
                } else {
                    $this->logger?->warning('      FAILED!');
                }
            }

            // CRL not processed if OCSP validation already success
            if (! $ltvResult['ocsp'] && ! empty($crlURIorFILE)) {
                $this->logger?->debug('    processing CRL validation since OCSP not done/failed...');
                $this->logger?->debug(sprintf('      getting crl from "%s"...', $crlURIorFILE));
                if ($crl = @file_get_contents($crlURIorFILE)) {
                    $this->logger?->debug('OK. size ' . round(strlen($crl) / 1024, 2) . 'Kb');
                    $this->logger?->debug('      reading crl...');
                    if ($crlread = x509::crl_read($crl)) {
                        $this->logger?->debug('OK');
                        $this->logger?->debug('      verify crl signature...');
                        $crl_signatureField = $crlread['parse']['signature'];
                        if (openssl_public_decrypt(hex2bin((string) $crl_signatureField), $decrypted, x509::x509_der2pem($ltvResult['issuer']), OPENSSL_PKCS1_PADDING)) {
                            $this->logger?->debug('OK');
                            $this->logger?->debug('      check CRL validity...');
                            $crl_parse = $crlread['parse'];
                            $thisUpdate = str_pad((string) $crl_parse['TBSCertList']['thisUpdate'], 15, '20', STR_PAD_LEFT);
                            $thisUpdateTime = strtotime($thisUpdate);
                            $nextUpdate = str_pad((string) $crl_parse['TBSCertList']['nextUpdate'], 15, '20', STR_PAD_LEFT);
                            $nextUpdateTime = strtotime($nextUpdate);
                            $nowz = time();
                            if ($nowz - $thisUpdateTime < 0) { // 0 sec after valid
                                throw new PDFException('FAILED! not yet valid! valid at ' . date('d/m/Y H:i:s', $thisUpdateTime));
                            }

                            if ($nextUpdateTime - $nowz < 1) { // not accept if crl 1 sec remain to expired
                                throw new PDFException('FAILED! Expired crl at ' . date('d/m/Y H:i:s', $nextUpdateTime) . ' and now ' . date('d/m/Y H:i:s', $nowz) . '!');
                            }

                            $this->logger?->debug('OK CRL still valid until ' . date('d/m/Y H:i:s', $nextUpdateTime));
                            $crlCertValid = true;
                            $this->logger?->debug('      check if cert not revoked...');
                            if (array_key_exists('revokedCertificates', $crl_parse['TBSCertList'])) {
                                $certSigner_serialNumber = $certSigner_parse['tbsCertificate']['serialNumber'];
                                if (array_key_exists($certSigner_serialNumber, $crl_parse['TBSCertList']['revokedCertificates']['lists'])) {
                                    throw new PDFException('FAILED! Certificate Revoked!');
                                }
                            }

                            if ($crlCertValid) {
                                $this->logger?->debug('OK. VALID');
                                $crlHex = current(unpack('H*', (string) $crlread['der']));
                                $ltvResult['crl'] = $crlHex;
                            }
                        } else {
                            throw new PDFException('FAILED! Wrong CRL.');
                        }
                    } else {
                        throw new PDFException("FAILED! can't read crl");
                    }
                } else {
                    throw new PDFException("FAILED! can't get crl");
                }
            }
        }

        if (! $ltvResult['issuer']) {
            return false;
        }

        if (! $ltvResult['ocsp'] && ! $ltvResult['crl']) {
            return false;
        }

        return $ltvResult;
    }

    /**
     * parse tsa response to array
     *
     * @param string $binaryTsaRespData binary tsa response to parse
     *
     * @return array asn.1 hex structure of tsa response
     */
    private function tsa_parseResp(string $binaryTsaRespData)
    {
        if (! @$ar = asn1::parse(bin2hex($binaryTsaRespData), 3)) {
            throw new PDFException("can't parse invalid tsa Response.");
        }

        $curr = $ar;
        foreach ($curr as $key => $value) {
            if ($value['type'] == '30') {
                $curr['TimeStampResp'] = $curr[$key];
                unset($curr[$key]);
            }
        }

        $ar = $curr;
        $curr = $ar['TimeStampResp'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] == '30' && ! array_key_exists('status', $curr)) {
                    $curr['status'] = $curr[$key];
                    unset($curr[$key]);
                } elseif ($value['type'] == '30') {
                    $curr['timeStampToken'] = $curr[$key];
                    unset($curr[$key]);
                }
            }
        }

        $ar['TimeStampResp'] = $curr;
        $curr = $ar['TimeStampResp']['timeStampToken'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key)) {
                if ($value['type'] == '06') {
                    $curr['contentType'] = $curr[$key];
                    unset($curr[$key]);
                }

                if ($value['type'] === 'a0') {
                    $curr['content'] = $curr[$key];
                    unset($curr[$key]);
                }
            }
        }

        $ar['TimeStampResp']['timeStampToken'] = $curr;
        $curr = $ar['TimeStampResp']['timeStampToken']['content'];
        foreach ($curr as $key => $value) {
            if (is_numeric($key) && $value['type'] == '30') {
                $curr['TSTInfo'] = $curr[$key];
                unset($curr[$key]);
            }
        }

        $ar['TimeStampResp']['timeStampToken']['content'] = $curr;
        if (@$ar['TimeStampResp']['timeStampToken']['content']['hexdump'] != '') {
            return $ar;
        }

        return false;
    }
}
