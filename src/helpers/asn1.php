<?php

namespace ddn\sapp\helpers;

/*
// File name   : asn1.php
// Version     : 1.0.1
// Last Update : 2024-04-30
// Author      : Hida - https://github.com/hidasw
// License     : GNU GPLv3
*/

/**
 * @class asn1
 * Create/Parsing asn.1 in hex form
 */
class asn1
{
    public static function __callStatic($func, $params)
    {
        $func = strtolower((string) $func);
        $asn1Tag = self::asn1Tag($func);
        if ($asn1Tag !== false) {
            $num = $asn1Tag; //value of array
            $hex = $params[0];
            $val = $hex;
            if (in_array($func, ['printable', 'utf8', 'ia5', 'visible', 't61'], true)) { // ($string)
                $val = bin2hex((string) $hex);
            }

            if ($func === 'int') {
                $val = strlen((string) $val) % 2 !== 0 ? '0' . $val : (string) $val;
            }

            if ($func === 'expl') { //expl($num, $hex)
                $num .= $params[0];
                $val = $params[1];
            }

            if ($func === 'impl') { //impl($num="0")
                $val ??= '00';
                $val = strlen((string) $val) % 2 !== 0 ? '0' . $val : $val;

                return $num . $val;
            }

            if ($func === 'other') { //OTHER($id, $hex, $chr = false)
                $id = $params[0];
                $hex = $params[1];
                $chr = @$params[2];
                $str = $hex;
                if ($chr) {
                    $str = bin2hex((string) $hex);
                }

                return $id . self::asn1_header($str) . $str;
            }

            if ($func === 'utime') {
                $time = $params[0]; //yymmddhhiiss
                $oldTz = date_default_timezone_get();
                date_default_timezone_set('UTC');
                $time = date('ymdHis', $time);
                date_default_timezone_set($oldTz);
                $val = bin2hex($time . 'Z');
            }

            if ($func === 'gtime') {
                if (! $time = strtotime((string) $params[0])) {
                    // echo "asn1::GTIME function strtotime cant recognize time!! please check at input=\"{$params[0]}\"";
                    return false;
                }

                $oldTz = date_default_timezone_get();
                // date_default_timezone_set("UTC");
                $time = date('YmdHis', $time);
                date_default_timezone_set($oldTz);
                $val = bin2hex($time . 'Z');
            }

            $hdr = self::asn1_header($val);

            return $num . $hdr . $val;
        }

        // echo "asn1 \"$func\" not exists!";
        return null;

    }

    /**
     * parse asn.1 to array recursively
     *
     * @param string $hex asn.1 hex form
     * @param int $maxDepth maximum parsing depth
     *
     * @return array asn.1 structure recursively to specific depth
     */
    public static function parse(string $hex, int $maxDepth = 5): array
    {
        $result = [];
        static $currentDepth = 0;
        if ($asn1parse_array = self::oneParse($hex)) {
            foreach ($asn1parse_array as $ff) {
                $parse_recursive = false;
                unset($info);
                $k = $ff['typ'];
                $v = $ff['tlv_value'];
                $info['depth'] = $currentDepth;
                $info['hexdump'] = $ff['newhexdump'];
                $info['type'] = $k;
                $info['typeName'] = self::type($k);
                $info['value_hex'] = $v;
                if ($currentDepth <= $maxDepth) {
                    if ($k !== '06') {
                        if (in_array($k, ['13', '18'], true)) {
                            $info['value'] = hex2bin((string) $info['value_hex']);
                        } elseif (in_array($k, ['03', '02', 'a04'], true)) {
                            $info['value'] = $v;
                        } else {
                            $currentDepth++;
                            $parse_recursive = self::parse($v, $maxDepth);
                            $currentDepth--;
                        }
                    }

                    $result[] = $parse_recursive ? array_merge($info, $parse_recursive) : $info;
                }
            }
        }

        return $result;
    }

    // =====Begin ASN.1 Parser section=====
    /**
     * get asn.1 type tag name
     *
     * @param string $id hex asn.1 type tag
     *
     * @return string asn.1 tag name
     */
    protected static function type(string $id): string
    {
        $asn1_Types = [
            '00' => 'ASN1_EOC',
            '01' => 'ASN1_BOOLEAN',
            '02' => 'ASN1_INTEGER',
            '03' => 'ASN1_BIT_STRING',
            '04' => 'ASN1_OCTET_STRING',
            '05' => 'ASN1_NULL',
            '06' => 'ASN1_OBJECT',
            '07' => 'ASN1_OBJECT_DESCRIPTOR',
            '08' => 'ASN1_EXTERNAL',
            '09' => 'ASN1_REAL',
            '0a' => 'ASN1_ENUMERATED',
            '0c' => 'ASN1_UTF8STRING',
            '30' => 'ASN1_SEQUENCE',
            '31' => 'ASN1_SET',
            '12' => 'ASN1_NUMERICSTRING',
            '13' => 'ASN1_PRINTABLESTRING',
            '14' => 'ASN1_T61STRING',
            '15' => 'ASN1_VIDEOTEXSTRING',
            '16' => 'ASN1_IA5STRING',
            '17' => 'ASN1_UTCTIME',
            '18' => 'ASN1_GENERALIZEDTIME',
            '19' => 'ASN1_GRAPHICSTRING',
            '1a' => 'ASN1_VISIBLESTRING',
            '1b' => 'ASN1_GENERALSTRING',
            '1c' => 'ASN1_UNIVERSALSTRING',
            '1d' => 'ASN1_BMPSTRING',
        ];

        return array_key_exists($id, $asn1_Types) ? $asn1_Types[$id] : $id;
    }

    /**
     * parse asn.1 to array
     * to be called from parse() function
     *
     * @param string $hex asn.1 hex form
     *
     * @return array asn.1 structure
     */
    protected static function oneParse(string $hex): array|false
    {
        if ($hex === '') {
            return false;
        }

        if (! @ctype_xdigit($hex) || @strlen($hex) % 2 !== 0) {
            echo "input:\"{$hex}\" not hex string!.\n";

            return false;
        }

        $stop = false;
        while ($stop == false) {
            $asn1_type = substr($hex, 0, 2);
            $tlv_tagLength = hexdec(substr($hex, 2, 2));
            if ($tlv_tagLength > 127) {
                $tlv_lengthLength = $tlv_tagLength - 128;
                $tlv_valueLength = substr($hex, 4, $tlv_lengthLength * 2);
            } else {
                $tlv_lengthLength = 0;
                $tlv_valueLength = substr($hex, 2, 2);
            }

            if ($tlv_lengthLength > 4) { // limit tlv_lengthLength to FFFF
                return false;
            }

            $tlv_valueLength = hexdec($tlv_valueLength);
            $totalTlLength = 2 + 2 + $tlv_lengthLength * 2;
            $tlv_value = substr($hex, $totalTlLength, $tlv_valueLength * 2);
            $remain = substr($hex, $totalTlLength + $tlv_valueLength * 2);
            $newhexdump = substr($hex, 0, $totalTlLength + $tlv_valueLength * 2);
            $result[] = [
                'tlv_tagLength' => strlen(dechex($tlv_tagLength)) % 2 === 0 ? dechex($tlv_tagLength) : '0' . dechex($tlv_tagLength),
                'tlv_lengthLength' => $tlv_lengthLength,
                'tlv_valueLength' => $tlv_valueLength,
                'newhexdump' => $newhexdump,
                'typ' => $asn1_type,
                'tlv_value' => $tlv_value,
            ];
            if ($remain === '') { // if remains string was empty & contents also empty, function return FALSE
                $stop = true;
            } else {
                $hex = $remain;
            }
        }

        return $result;
    }

    // =====End ASN.1 Parser section=====

    // =====Begin ASN.1 Builder section=====
    /**
     * create asn.1 TLV tag length, length length and value length
     * to be called from asn.1 builder functions
     *
     * @param string $str string value of asn.1
     *
     * @return string hex of asn.1 TLV tag length
     */
    protected static function asn1_header(string $str): string
    {
        $len = strlen($str) / 2;
        $ret = dechex($len);
        if (strlen($ret) % 2 !== 0) {
            $ret = '0' . $ret;
        }

        $headerLength = strlen($ret) / 2;
        if ($len > 127) {
            $ret = '8' . $headerLength . $ret;
        }

        return $ret;
    }

    /**
     * create various dynamic function for asn1
     */
    private static function asn1Tag($name): string|false
    {
        $functionList = [
            'seq' => '30',
            'oct' => '04',
            'obj' => '06',
            'bit' => '03',
            'printable' => '13',
            'int' => '02',
            'set' => '31',
            'expl' => 'a',
            'utime' => '17',
            'gtime' => '18',
            'utf8' => '0c',
            'ia5' => '16',
            'visible' => '1a',
            't61' => '14',
            'impl' => '80',
            'other' => '',
        ];
        if (array_key_exists($name, $functionList)) {
            return $functionList[$name];
        }

        // echo "func \"$name\" not available";
        return false;

    }

    // =====End ASN.1 Builder section=====
}
