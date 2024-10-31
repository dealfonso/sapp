<?php
/*
    This file is part of SAPP

    Simple and Agnostic PDF Parser (SAPP) - Parse PDF documents in PHP (and update them)
    Copyright (C) 2020 - Carlos de Alfonso (caralla76@gmail.com)

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Lesser General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU Lesser General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.

    ---------

    The code in this file is an adaptation of a part of the code included in
    fpdf version 1.82 as downloaded from (http://www.fpdf.org/es/dl.php?v=182&f=tgz)

    The fpdf license:

    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software to use, copy, modify, distribute, sublicense, and/or sell
    copies of the software, and to permit persons to whom the software is furnished
    to do so.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED.
*/

namespace ddn\sapp\helpers;

use ddn\sapp\PDFException;

function _parsejpg($filecontent): array
{
    // Extract info from a JPEG file
    $a = getimagesizefromstring($filecontent);
    if (! $a) {
        throw new PDFException('Missing or incorrect image');
    }
    if ($a[2] != 2) {
        throw new PDFException('Not a JPEG image');
    }
    if (! isset($a['channels']) || $a['channels'] == 3) {
        $colspace = 'DeviceRGB';
    } elseif ($a['channels'] == 4) {
        $colspace = 'DeviceCMYK';
    } else {
        $colspace = 'DeviceGray';
    }
    $bpc = $a['bits'] ?? 8;
    $data = $filecontent;

    return [
        'w' => $a[0],
        'h' => $a[1],
        'cs' => $colspace,
        'bpc' => $bpc,
        'f' => 'DCTDecode',
        'data' => $data,
    ];
}

function _parsepng($filecontent): array
{
    // Extract info from a PNG file
    $f = new StreamReader($filecontent);

    return _parsepngstream($f);
}

function _parsepngstream(&$f): array
{
    // Check signature
    if (($res = _readstream($f, 8)) != chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10)) {
        throw new PDFException("Not a PNG image {$res}");
    }

    // Read header chunk
    _readstream($f, 4);
    if (_readstream($f, 4) !== 'IHDR') {
        throw new PDFException('Incorrect PNG image');
    }
    $w = _readint($f);
    $h = _readint($f);
    $bpc = ord(_readstream($f, 1));
    if ($bpc > 8) {
        throw new PDFException('16-bit depth not supported');
    }
    $ct = ord(_readstream($f, 1));
    if ($ct == 0 || $ct == 4) {
        $colspace = 'DeviceGray';
    } elseif ($ct == 2 || $ct == 6) {
        $colspace = 'DeviceRGB';
    } elseif ($ct == 3) {
        $colspace = 'Indexed';
    } else {
        throw new PDFException('Unknown color type');
    }
    if (ord(_readstream($f, 1)) != 0) {
        throw new PDFException('Unknown compression method');
    }
    if (ord(_readstream($f, 1)) != 0) {
        throw new PDFException('Unknown filter method');
    }
    if (ord(_readstream($f, 1)) != 0) {
        throw new PDFException('Interlacing not supported');
    }
    _readstream($f, 4);
    $dp = '/Predictor 15 /Colors ' . ($colspace === 'DeviceRGB' ? 3 : 1) . ' /BitsPerComponent ' . $bpc . ' /Columns ' . $w;

    // Scan chunks looking for palette, transparency and image data
    $pal = '';
    $trns = '';
    $data = '';
    do {
        $n = _readint($f);
        $type = _readstream($f, 4);
        if ($type === 'PLTE') {
            // Read palette
            $pal = _readstream($f, $n);
            _readstream($f, 4);
        } elseif ($type === 'tRNS') {
            // Read transparency info
            $t = _readstream($f, $n);
            if ($ct == 0) {
                $trns = [ord(substr((string) $t, 1, 1))];
            } elseif ($ct == 2) {
                $trns = [ord(substr((string) $t, 1, 1)), ord(substr((string) $t, 3, 1)), ord(substr((string) $t, 5, 1))];
            } else {
                $pos = strpos((string) $t, chr(0));
                if ($pos !== false) {
                    $trns = [$pos];
                }
            }
            _readstream($f, 4);
        } elseif ($type === 'IDAT') {
            // Read image data block
            $data .= _readstream($f, $n);
            _readstream($f, 4);
        } elseif ($type === 'IEND') {
            break;
        } else {
            _readstream($f, $n + 4);
        }
    } while ($n);

    if ($colspace === 'Indexed' && empty($pal)) {
        throw new PDFException('Missing palette in image');
    }
    $info = [
        'w' => $w,
        'h' => $h,
        'cs' => $colspace,
        'bpc' => $bpc,
        'f' => 'FlateDecode',
        'dp' => $dp,
        'pal' => $pal,
        'trns' => $trns,
    ];
    if ($ct >= 4) {
        // Extract alpha channel
        if (! function_exists('gzuncompress')) {
            throw new PDFException('Zlib not available, can\'t handle alpha channel');
        }
        $data = gzuncompress($data);
        if ($data === false) {
            throw new PDFException('failed to uncompress the image');
        }
        $color = '';
        $alpha = '';
        if ($ct == 4) {
            // Gray image
            $len = 2 * $w;
            for ($i = 0; $i < $h; $i++) {
                $pos = (1 + $len) * $i;
                $color .= $data[$pos];
                $alpha .= $data[$pos];
                $line = substr($data, $pos + 1, $len);
                $color .= preg_replace('/(.)./s', '$1', $line);
                $alpha .= preg_replace('/.(.)/s', '$1', $line);
            }
        } else {
            // RGB image
            $len = 4 * $w;
            for ($i = 0; $i < $h; $i++) {
                $pos = (1 + $len) * $i;
                $color .= $data[$pos];
                $alpha .= $data[$pos];
                $line = substr($data, $pos + 1, $len);
                $color .= preg_replace('/(.{3})./s', '$1', $line);
                $alpha .= preg_replace('/.{3}(.)/s', '$1', $line);
            }
        }
        unset($data);
        $data = gzcompress($color);
        $info['smask'] = gzcompress($alpha);
        /*
        $this->WithAlpha = true;
        if($this->PDFVersion<'1.4')
            $this->PDFVersion = '1.4';
            */
    }
    $info['data'] = $data;

    return $info;
}

function _readstream($f, $n): string
{
    $res = '';

    while ($n > 0 && ! $f->eos()) {
        $s = $f->nextchars($n);
        if ($s === false) {
            throw new PDFException('Error while reading the stream');
        }
        $n -= strlen((string) $s);
        $res .= $s;
    }

    if ($n > 0) {
        throw new PDFException('Unexpected end of stream');
    }

    return $res;
}

function _readint(&$f)
{
    // Read a 4-byte integer from stream
    $a = unpack('Ni', (string) _readstream($f, 4));

    return $a['i'];
}

/*
function _readstream($f, $n)
{
    // Read n bytes from stream
    $res = '';
    while($n>0 && !feof($f))
    {
        $s = fread($f,$n);
        if($s===false)
            throw new PDFException('Error while reading stream');
        $n -= strlen($s);
        $res .= $s;
    }
    if($n>0)
        throw new PDFException('Unexpected end of stream');
    return $res;
}

function _readint($f)
{
    // Read a 4-byte integer from stream
    $a = unpack('Ni',_readstream($f,4));
    return $a['i'];
}
*/
