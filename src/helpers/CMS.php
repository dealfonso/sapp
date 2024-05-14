<?php
namespace ddn\sapp\helpers;
/*
// File name   : CMS.php
// Version     : 1.1
// Last Update : 2024-05-02
// Author      : Hida - https://github.com/hidasw
// License     : GNU GPLv3
*/
/**
 * @class cms
 * Manage CMS(Cryptographic Message Syntax) Signature for SAPP PDF
 */
class CMS {
    public $signature_data;

  /**
   * send tsa/ocsp query with curl
   * @param array $reqData
   * @return string response body
   * @public
   */
  public function sendReq($reqData) {
    if (!function_exists('curl_init')) {
      p_error('         Please enable cURL PHP extension!');
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $reqData['uri']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: {$reqData['req_contentType']}",'User-Agent: SAPP PDF'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $reqData['data']);
    if (($reqData['user'] ?? null) && ($reqData['password'] ?? null)) {
        curl_setopt($ch, CURLOPT_USERPWD, $reqData['user'] . ':' . $reqData['password']);
    }
    $tsResponse = curl_exec($ch);

    if($tsResponse) {
      $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
      curl_close($ch);
      $header = substr($tsResponse, 0, $header_size);
      $body = substr($tsResponse, $header_size);
      // Get the HTTP response code
      $headers = explode("\n", $header);
      foreach ($headers as $key => $r) {
        if (stripos($r, 'HTTP/') === 0) {
          list(,$code, $status) = explode(' ', $r, 3);
          break;
        }
      }
      if($code != '200') {
        p_error("      response error! Code=\"$code\", Status=\"".trim($status)."\"");
        return false;
      }
      $contentTypeHeader = '';
      $headers = explode("\n", $header);
      foreach ($headers as $key => $r) {
        // Match the header name up to ':', compare lower case
        if (stripos($r, "Content-Type".':') === 0) {
          list($headername, $headervalue) = explode(":", $r, 2);
          $contentTypeHeader = trim($headervalue);
        }
      }
      if($contentTypeHeader != $reqData['resp_contentType']) {
        p_error("      response content type not {$reqData['resp_contentType']}, but: \"$contentTypeHeader\"");
        return false;
      }
      if(empty($body)) {
        p_error('         error empty response!');
      }
      return $body; // binary response
    }
  }

  /**
   * parse tsa response to array
   * @param string $binaryTsaRespData binary tsa response to parse
   * @return array asn.1 hex structure of tsa response
   */
  private function tsa_parseResp($binaryTsaRespData) {
    if(!@$ar = asn1::parse(bin2hex($binaryTsaRespData), 3)) {
      p_error("      can't parse invalid tsa Response.");
      return false;
    }
    $curr = $ar;
    foreach($curr as $key=>$value) {
      if($value['type'] == '30') {
        $curr['TimeStampResp']=$curr[$key];
        unset($curr[$key]);
      }
    }
    $ar=$curr;
    $curr = $ar['TimeStampResp'];
    foreach($curr as $key=>$value) {
      if(is_numeric($key)) {
        if($value['type'] == '30' && !array_key_exists('status', $curr)) {
          $curr['status']=$curr[$key];
          unset($curr[$key]);
        } else if($value['type'] == '30') {
          $curr['timeStampToken']=$curr[$key];
          unset($curr[$key]);
        }
      }
    }
    $ar['TimeStampResp']=$curr;
    $curr = $ar['TimeStampResp']['timeStampToken'];
    foreach($curr as $key=>$value) {
      if(is_numeric($key)) {
        if($value['type'] == '06') {
          $curr['contentType']=$curr[$key];
          unset($curr[$key]);
        }
        if($value['type'] == 'a0') {
          $curr['content']=$curr[$key];
          unset($curr[$key]);
        }
      }
    }
    $ar['TimeStampResp']['timeStampToken'] = $curr;
    $curr = $ar['TimeStampResp']['timeStampToken']['content'];
    foreach($curr as $key=>$value) {
      if(is_numeric($key)) {
        if($value['type'] == '30') {
          $curr['TSTInfo']=$curr[$key];
          unset($curr[$key]);
        }
      }
    }
    $ar['TimeStampResp']['timeStampToken']['content'] = $curr;
    if(@$ar['TimeStampResp']['timeStampToken']['content']['hexdump'] != '') {
      return $ar;
    } else {
       return false;
    }
  }

  /**
   * Create timestamp query
   * @param string $data binary data to hashed/digested
   * @param string $hashAlg hash algorithm
   * @return string hex TSTinfo.
   */
  protected function createTimestamp($data, $hashAlg='sha1') {
    $TSTInfo=false;
    $tsaQuery = x509::tsa_query($data, $hashAlg);
    $tsaData = $this->signature_data['tsa'];
    $reqData = array(
        'data'=>$tsaQuery,
        'uri'=>$tsaData['host'],
        'req_contentType'=>'application/timestamp-query',
        'resp_contentType'=>'application/timestamp-reply'
    ) + $tsaData;

    p_debug("    sending TSA query to \"".$tsaData['host']."\"...");
    if(!$binaryTsaResp = self::sendReq($reqData)) {
      p_error("      TSA query send FAILED!");
    } else {
      p_debug("      TSA query send OK");
      p_debug("    Parsing Timestamp response...");
      if(!$tsaResp = $this->tsa_parseResp($binaryTsaResp)) {
        p_error("    parsing FAILED!");
      }
      p_debug("    parsing OK");
      $TSTInfo = $tsaResp['TimeStampResp']['timeStampToken']['hexdump'];
    }
    return $TSTInfo;
  }

  /**
   * Perform OCSP/CRL Validation
   * @param array $parsedCert parsed certificate
   * @param string $ocspURI
   * @param string $crlURIorFILE
   * @param string $issuerURIorFILE
   * @return array
   */
  protected function LTVvalidation($parsedCert, $ocspURI=null, $crlURIorFILE=null, $issuerURIorFILE=false) {
    $ltvResult['issuer']=false;
    $ltvResult['ocsp']=false;
    $ltvResult['crl']=false;
    $x509 = new x509;
    $certSigner_parse = $parsedCert;
    p_debug("    getting OCSP address...");
    if($ocspURI===false) {
      p_debug("       OCSP is skipped by request.");
    } else {
      if(empty(trim((string) $ocspURI))) {
        p_debug("      OCSP address \"$ocspURI\" is empty/not set. try getting from certificate AIA OCSP attribute...");
        $ocspURI = @$certSigner_parse['tbsCertificate']['attributes']['1.3.6.1.5.5.7.1.1']['value']['1.3.6.1.5.5.7.48.1'][0];
        if(empty(trim($ocspURI))) {
          p_warning("      OCSP address FAILED! got empty address:\"$ocspURI\"");
        } else {
          p_debug("      OCSP got AIA address:\"$ocspURI\"");
        }
      } else {
        p_debug("      OCSP address is set manually to \"".trim($ocspURI)."\"");
      }
    }
    $ocspURI = trim((string) $ocspURI);
    p_debug("    getting CRL address...");
    if(empty(trim((string) $crlURIorFILE))) {
      p_debug("      CRL address \"$crlURIorFILE\" is empty/not set. try getting location from certificate CDP attribute...");
      $crlURIorFILE = @$certSigner_parse['tbsCertificate']['attributes']['2.5.29.31']['value'][0];
      if(empty(trim($crlURIorFILE))) {
        p_warning("      CRL address FAILED! got empty address:\"$crlURIorFILE\"");
      } else {
        p_debug("      CRL got CDP address:\"$crlURIorFILE\"");
      }
    } else {
      p_debug("      CRL uri or file is set manually to \"".trim($crlURIorFILE)."\"");
    }
    if(empty($ocspURI) && empty($crlURIorFILE)) {
      p_error("    can't get OCSP/CRL address! Process terminated.");
    } else { // Perform if either ocspURI/crlURIorFILE exists
      p_debug("    getting Issuer address...");
      if(empty(trim($issuerURIorFILE))) {
        p_debug("      issuer location address \"$issuerURIorFILE\" is empty/not set. use AIA Issuer attribute from certificate...");
        $issuerURIorFILE = @$certSigner_parse['tbsCertificate']['attributes']['1.3.6.1.5.5.7.1.1']['value']['1.3.6.1.5.5.7.48.2'][0];
      } else {
        p_debug("      issuer location manually specified ($issuerURIorFILE)");
      }
      $issuerURIorFILE = trim($issuerURIorFILE);
      if(empty($issuerURIorFILE)) {
        p_error("      cant get issuer location! Process terminated.");
      } else {
        p_debug("      getting issuer from \"$issuerURIorFILE\"...");
        if($issuerCert = @file_get_contents($issuerURIorFILE)) {
          p_debug("        issuer get OK. size ".round(strlen($issuerCert)/1024,2)."Kb");
          p_debug("      reading issuer certificate...");
          if($issuer_certDER = x509::get_cert($issuerCert)) {
            p_debug("        reading issuer cert OK");
            p_debug("      check if issuer is cert issuer...");
            $certIssuer_parse = $x509::readcert($issuer_certDER, 'oid'); // Parsing Issuer cert
            $certSigner_signatureField = $certSigner_parse['signatureValue'];
            if(openssl_public_decrypt(hex2bin($certSigner_signatureField), $decrypted, $x509::x509_der2pem($issuer_certDER), OPENSSL_PKCS1_PADDING)) {
              p_debug("        OK issuer is cert issuer.");
              $ltvResult['issuer'] = $issuer_certDER;
            } else {
              p_error("        FAILED! issuer is not cert issuer.");
            }
          } else {
            p_error("        reading issuer cert FAILED!");
          }
        } else {
          p_error("        issuer get FAILED.");
        }
      }
    }

    if($ltvResult['issuer']) {
      if(!empty($ocspURI)) {
        p_debug("    OCSP start.");
        $ocspReq_serialNumber = $certSigner_parse['tbsCertificate']['serialNumber'];
        $ocspReq_issuerNameHash = $certIssuer_parse['tbsCertificate']['subject']['sha1'];
        $ocspReq_issuerKeyHash = $certIssuer_parse['tbsCertificate']['subjectPublicKeyInfo']['sha1'];
        $ocspRequestorSubjName = $certSigner_parse['tbsCertificate']['subject']['hexdump'];
        p_debug("      OCSP create request...");
        if($ocspReq = $x509::ocsp_request($ocspReq_serialNumber, $ocspReq_issuerNameHash, $ocspReq_issuerKeyHash, $this->signature_data['signcert'], $this->signature_data['privkey'], $ocspRequestorSubjName)) {
          p_debug("        create request OK.");
          $ocspBinReq = pack("H*", $ocspReq);
          $reqData = array(
                          'data'=>$ocspBinReq,
                          'uri'=>$ocspURI,
                          'req_contentType'=>'application/ocsp-request',
                          'resp_contentType'=>'application/ocsp-response'
                          );
          p_debug("      OCSP send request to \"$ocspURI\"...");
          if($ocspResp = self::sendReq($reqData)) {
            p_debug("        OCSP send request OK.");
            p_debug("      OCSP parsing response...");
            if($ocsp_parse = $x509::ocsp_response_parse($ocspResp, $return)) {
              p_debug("        ocsp response parsed.");
              p_debug("      OCSP check cert validity...");
              $certStatus = $ocsp_parse['responseBytes']['response']['BasicOCSPResponse']['tbsResponseData']['responses'][0]['certStatus'];
              if($certStatus == 'valid') {
                p_debug("        OK. cert VALID.");
                $ocspRespHex = $ocsp_parse['hexdump'];
                $appendOCSP = asn1::expl(1,
                                          asn1::seq(
                                                    $ocspRespHex
                                                    )
                                          );
                $ltvResult['ocsp'] = $appendOCSP;
              } else {
                p_error("        FAILED! cert invalid, status:\"$certStatus\"");
              }
            } else {
              p_error("        ocsp parse FAILED! Ocsp server status \"$return\"");
            }
          } else {
            p_error("        ocsp send request FAILED!");
          }
        } else {
          p_error("      create request FAILED!");
        }
      }

      if(!$ltvResult['ocsp']) {// CRL not processed if OCSP validation already success
        if(!empty($crlURIorFILE)) {
          p_debug("    processing CRL validation since OCSP not done/failed...");
          p_debug("      getting crl from \"$crlURIorFILE\"...");
          if($crl = @file_get_contents($crlURIorFILE)) {
            p_debug("        OK. crl size ".round(strlen($crl)/1024,2)."Kb");
            p_debug("      reading crl...");
            if($crlread=$x509->crl_read($crl)) {
              p_debug("        crl read OK");
              p_debug("      checking if crl issued by CA...");
              $crl_signatureField = $crlread['parse']['signature'];
              if(openssl_public_decrypt(hex2bin($crl_signatureField), $decrypted, $x509::x509_der2pem($issuer_certDER), OPENSSL_PKCS1_PADDING)) {
                p_debug("        OK crl issued by ca");
                p_debug("      check CRL validity...");
                $crl_parse=$crlread['parse'];
                $thisUpdate = str_pad($crl_parse['TBSCertList']['thisUpdate'], 15, "20", STR_PAD_LEFT);
                $thisUpdateTime = strtotime($thisUpdate);
                $nextUpdate = str_pad($crl_parse['TBSCertList']['nextUpdate'], 15, "20", STR_PAD_LEFT);
                $nextUpdateTime = strtotime($nextUpdate);
                $nowz = strtotime("now");
                if(($nowz-$thisUpdateTime) < 0) { // 0 sec after valid
                  p_error("        FAILED! not yet valid! valid at ".date("d/m/Y H:i:s", $thisUpdateTime));
                } elseif(($nextUpdateTime-$nowz) < 1) { // not accept if crl 1 sec remain to expired
                  p_error("        FAILED! Expired crl at ".date("d/m/Y H:i:s", $nextUpdateTime)." and now ".date("d/m/Y H:i:s", $nowz)."!");
                } else {
                  p_debug("        OK CRL still valid until ".date("d/m/Y H:i:s", $nextUpdateTime));
                  $crlCertValid=true;
                  p_debug("      check if cert not revoked...");
                  if(array_key_exists('revokedCertificates', $crl_parse['TBSCertList'])) {
                    $certSigner_serialNumber = $certSigner_parse['tbsCertificate']['serialNumber'];
                    if(array_key_exists($certSigner_serialNumber, $crl_parse['TBSCertList']['revokedCertificates']['lists'])) {
                      $crlCertValid=false;
                      p_error("        FAILED! Certificate Revoked!");
                    }
                  }
                  if($crlCertValid == true) {
                    p_debug("        OK. VALID");
                    $crlHex = current(unpack('H*', $crlread['der']));
                    $appendCrl = asn1::expl(0,
                                              asn1::seq(
                                                        $crlHex
                                                        )
                                              );
                    $ltvResult['crl'] = $appendCrl;
                  }
                }
              } else {
                p_error("        FAILED! Wrong CRL.");
              }
            } else {
              p_error("        FAILED! can't read crl");
            }
          } else {
            p_error("        FAILED! can't get crl");
          }
        }
      }
    }
    if(!$ltvResult['ocsp'] && !$ltvResult['crl']) {
      return false;
    }
    return $ltvResult;
  }

  /**
   * Perform PKCS7 Signing
   * @param string $binaryData
   * @return string hex + padding 0
   * @public
   */
  public function pkcs7_sign($binaryData) {
    $hexOidHashAlgos = array(
        'md2'=>'06082A864886F70D0202',
        'md4'=>'06082A864886F70D0204',
        'md5'=>'06082A864886F70D0205',
        'sha1'=>'06052B0E03021A',
        'sha224'=>'0609608648016503040204',
        'sha256'=>'0609608648016503040201',
        'sha384'=>'0609608648016503040202',
        'sha512'=>'0609608648016503040203'
    );
    $hashAlgorithm = $this->signature_data['hashAlgorithm'];
    if(!array_key_exists($hashAlgorithm, $hexOidHashAlgos)) {
      p_error("not support hash algorithm!");
      return false;
    }
    p_debug("hash algorithm is \"$hashAlgorithm\"");
    $x509 = new x509;
    if(!$certParse = $x509->readcert($this->signature_data['signcert'])) {
      p_error("certificate error! check certificate");
    }
    $hexEmbedCerts[] = bin2hex($x509->get_cert($this->signature_data['signcert']));
    $appendLTV = '';
    $ltvData = $this->signature_data['ltv'];
    if(!empty($ltvData)) {
      p_debug("  LTV Validation start...");
      $LTVvalidation = self::LTVvalidation($certParse, $ltvData['ocspURI'], $ltvData['crlURIorFILE'], $ltvData['issuerURIorFILE']);
      if($LTVvalidation) {
        p_debug("  LTV Validation SUCCESS");
        $hexEmbedCerts[] = bin2hex($LTVvalidation['issuer']);
        $appendLTV = asn1::seq(
            "06092A864886F72F010108". // adbe-revocationInfoArchival (1.2.840.113583.1.1.8)
            asn1::set(
                asn1::seq($LTVvalidation['ocsp'] . $LTVvalidation['crl'])
            )
        );
      } else {
        p_warning("  LTV Validation FAILED!");
      }
    }
    foreach($this->signature_data['extracerts'] ?? [] as $extracert) {
      $hex_extracert = bin2hex($x509->x509_pem2der($extracert));
      if(!in_array($hex_extracert, $hexEmbedCerts)) {
        $hexEmbedCerts[] = $hex_extracert;
      }
    }
    $messageDigest = hash($hashAlgorithm, $binaryData);
    $authenticatedAttributes= asn1::seq(
            '06092A864886F70D010903'. //OBJ_pkcs9_contentType 1.2.840.113549.1.9.3
            asn1::set('06092A864886F70D010701')  //OBJ_pkcs7_data 1.2.840.113549.1.7.1
        ).
        asn1::seq( // signing time
            '06092A864886F70D010905'. //OBJ_pkcs9_signingTime 1.2.840.113549.1.9.5
            asn1::set(
                asn1::utime(date("ymdHis")) //UTTC Time
            )
        ).
        asn1::seq( // messageDigest
            '06092A864886F70D010904'. //OBJ_pkcs9_messageDigest 1.2.840.113549.1.9.4
            asn1::set(asn1::oct($messageDigest))
        ).
        $appendLTV;
    $tohash = asn1::set($authenticatedAttributes);
    $hash = hash($hashAlgorithm, hex2bin($tohash));
    $toencrypt =  asn1::seq(
        asn1::seq($hexOidHashAlgos[$hashAlgorithm]."0500").  // OBJ $messageDigest & OBJ_null
        asn1::oct($hash)
    );
    $pkey = $this->signature_data['privkey'];
    if(!openssl_private_encrypt(hex2bin($toencrypt), $encryptedDigest, $pkey, OPENSSL_PKCS1_PADDING)) {
      p_error("openssl_private_encrypt error! can't encrypt");
      return false;
    }
    $hexencryptedDigest = bin2hex($encryptedDigest);
    $timeStamp = '';
    if(!empty($this->signature_data['tsa'])) {
      p_debug("  Timestamping process start...");
      if($TSTInfo = self::createTimestamp($encryptedDigest, $hashAlgorithm)) {
        p_debug("  Timestamping SUCCESS.");
        $TimeStampToken = asn1::seq(
            "060B2A864886F70D010910020E". // OBJ_id_smime_aa_timeStampToken 1.2.840.113549.1.9.16.2.14
            asn1::set($TSTInfo)
        );
        $timeStamp = asn1::expl(1,$TimeStampToken);
      } else {
        p_warning("  Timestamping FAILED!");
      }
    }
    $issuerName = $certParse['tbsCertificate']['issuer']['hexdump'];
    $serialNumber = $certParse['tbsCertificate']['serialNumber'];
    $signerinfos = asn1::seq(
        asn1::int('1').
        asn1::seq($issuerName . asn1::int($serialNumber)).
        asn1::seq($hexOidHashAlgos[$hashAlgorithm].'0500').
        asn1::expl(0, $authenticatedAttributes).
        asn1::seq(
            '06092A864886F70D010101'. //OBJ_rsaEncryption
            '0500'
        ).
        asn1::oct($hexencryptedDigest).
        $timeStamp
    );
    $crl = asn1::expl(1,
        '00' // crls (not yet used)
    );
    $crl = '';
    $certs = asn1::expl(0,implode('', $hexEmbedCerts));
    $pkcs7contentSignedData = asn1::seq(
        asn1::int('1').
        asn1::set(asn1::seq($hexOidHashAlgos[$hashAlgorithm].'0500')).
        asn1::seq('06092A864886F70D010701'). //OBJ_pkcs7_data
        $certs.
        $crl.
        asn1::set($signerinfos)
    );
    $pkcs7ContentInfo = asn1::seq(
        "06092A864886F70D010702". // Hexadecimal form of pkcs7-signedData
        asn1::expl(0,$pkcs7contentSignedData)
    );
    $pkcs7ContentInfo = str_pad($pkcs7ContentInfo, __SIGNATURE_MAX_LENGTH, '0');
    return $pkcs7ContentInfo;
  }
}
