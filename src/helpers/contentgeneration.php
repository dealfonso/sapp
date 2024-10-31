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
*/

namespace ddn\sapp\helpers;

use ddn\sapp\PDFException;
use ddn\sapp\pdfvalue\PDFValueList;
use ddn\sapp\pdfvalue\PDFValueObject;
use ddn\sapp\pdfvalue\PDFValueReference;
use ddn\sapp\pdfvalue\PDFValueType;
use finfo;

function tx($x, $y): string
{
    return sprintf(' 1 0 0 1 %.2F %.2F cm', $x, $y);
}

function sx($w, $h): string
{
    return sprintf(' %.2F 0 0 %.2F 0 0 cm', $w, $h);
}

function deg2rad($angle): float
{
    return $angle * M_PI / 180;
}

function rx($angle): string
{
    $angle = deg2rad($angle);

    return sprintf(' %.2F %.2F %.2F %.2F 0 0 cm', cos($angle), sin($angle), -sin($angle), cos($angle));
}

/**
 * Creates an image object in the document, using the content of "info"
 *   NOTE: the image inclusion is taken from http://www.fpdf.org/; this is a translation
 *         of function _putimage
 */
function _create_image_objects($info, $object_factory): array
{
    $objects = [];

    $image = call_user_func(
        $object_factory,
        [
            'Type' => '/XObject',
            'Subtype' => '/Image',
            'Width' => $info['w'],
            'Height' => $info['h'],
            'ColorSpace' => [],
            'BitsPerComponent' => $info['bpc'],
            'Length' => strlen((string) $info['data']),
        ]
    );

    switch ($info['cs']) {
        case 'Indexed':
            $data = gzcompress((string) $info['pal']);
            $streamobject = call_user_func($object_factory, [
                'Filter' => '/FlateDecode',
                'Length' => strlen($data),
            ]);
            $streamobject->set_stream($data);

            $image['ColorSpace']->push([
                '/Indexed',
                '/DeviceRGB',
                strlen((string) $info['pal']) / 3 - 1,
                new PDFValueReference($streamobject->get_oid()),
            ]);
            $objects[] = $streamobject;
            break;
        case 'DeviceCMYK':
            $image['Decode'] = new PDFValueList([1, 0, 1, 0, 1, 0, 1, 0]);
            // no break
        default:
            $image['ColorSpace'] = new PDFValueType($info['cs']);
            break;
    }

    if (isset($info['f'])) {
        $image['Filter'] = new PDFValueType($info['f']);
    }

    if (isset($info['dp'])) {
        $image['DecodeParms'] = PDFValueObject::fromstring($info['dp']);
    }

    if (isset($info['trns']) && is_array($info['trns'])) {
        $image['Mask'] = new PDFValueList($info['trns']);
    }

    if (isset($info['smask'])) {
        $smaskinfo = [
            'w' => $info['w'],
            'h' => $info['h'],
            'cs' => 'DeviceGray',
            'bpc' => 8,
            'f' => $info['f'],
            'dp' => '/Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns ' . $info['w'],
            'data' => $info['smask'],
        ];

        // In principle, it may return multiple objects
        $smasks = _create_image_objects($smaskinfo, $object_factory);
        assert($smasks !== []);
        foreach ($smasks as $smask) {
            $objects[] = $smask;
        }

        $image['SMask'] = new PDFValueReference($smask->get_oid());
    }

    $image->set_stream($info['data']);
    array_unshift($objects, $image);

    return $objects;
}

function is_base64($string): bool
{
    // Check if there are valid base64 characters
    if (! preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', (string) $string)) {
        return false;
    }

    // Decode the string in strict mode and check the results
    $decoded = base64_decode((string) $string, true);
    if ($decoded === false) {
        return false;
    }

    // Encode the string again
    return base64_encode($decoded) == $string;
}

/**
 * This function creates the objects needed to add an image to the document, at a specific position and size.
 *   The function is agnostic from the place in which the image is to be created, and just creates the objects
 *   with its contents and prepares the PDF command to place the image
 *
 * @param filename the file name that contains the image, or a string that contains the image (with character '@'
 *                 prepended)
 * @param x points from left in which to appear the image (the units are "content-defined" (i.e. depending on the size of the page))
 * @param y points from bottom in which to appear the image (the units are "content-defined" (i.e. depending on the size of the page))
 * @param w width of the rectangle in which to appear the image (image will be scaled, and the units are "content-defined" (i.e. depending on the size of the page))
 * @param h height of the rectangle in which to appear the image (image will be scaled, and the units are "content-defined" (i.e. depending on the size of the page))
 * @param angle the rotation angle in degrees; the image will be rotated using the center
 * @param keep_proportions if true, the image will keep the proportions when rotated, then the image will not occupy the full
 *
 * @return result an array with the next fields:
 *                  "images": objects of the corresponding images (i.e. position [0] is the image, the rest elements are masks, if needed)
 *                  "resources": PDFValueObject with keys that needs to be incorporated to the resources of the object in which the images will appear
 *                  "alpha": true if the image has alpha
 *                  "command": pdf command to draw the image
 */
function _add_image($object_factory, $filename, $x = 0, $y = 0, $w = 0, $h = 0, $angle = 0, bool $keep_proportions = true): array
{
    if (empty($filename)) {
        throw new PDFException('invalid image name or stream');
    }

    if ($filename[0] === '@') {
        $filecontent = substr((string) $filename, 1);
    } elseif (is_base64($filename)) {
        $filecontent = base64_decode((string) $filename, true);
    } else {
        $filecontent = @file_get_contents($filename);

        if ($filecontent === false) {
            throw new PDFException('failed to get the image');
        }
    }

    $finfo = new finfo();
    $content_type = $finfo->buffer($filecontent, FILEINFO_MIME_TYPE);

    $ext = mime_to_ext($content_type);

    // TODO: support more image types than jpg
    $add_alpha = false;
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $info = _parsejpg($filecontent);
            break;
        case 'png':
            $add_alpha = true;
            $info = _parsepng($filecontent);
            break;
        default:
            throw new PDFException('unsupported mime type');
    }

    // Generate a new identifier for the image
    $info['i'] = 'Im' . get_random_string(4);

    if ($w === null) {
        $w = -96;
    }

    if ($h === null) {
        $h = -96;
    }

    if ($w < 0) {
        $w = -$info['w'] * 72 / $w;
    }

    if ($h < 0) {
        $h = -$info['h'] * 72 / $h;
    }

    if ($w == 0) {
        $w = $h * $info['w'] / $info['h'];
    }

    if ($h == 0) {
        $h = $w * $info['h'] / $info['w'];
    }

    $images_objects = _create_image_objects($info, $object_factory);

    // Generate the command to translate and scale the image

    if ($keep_proportions) {
        $angleRads = deg2rad($angle);
        $W = abs($w * cos($angleRads) + $h * sin($angleRads));
        $H = abs($w * sin($angleRads) + $h * cos($angleRads));
        $rW = $W / $w;
        $rH = $H / $h;
        $r = min($rW, $rH);
        $w = $W * $r;
        $h = $H * $r;
    }

    // Now, how to apply the matrices...
    //   the matrices are not added in the order that we want to apply them; instead, they should be added in the reverse order (as they should be multiplied)
    //   i.e. the nearest matrix to the "/Image Do" will be the one applied.
    //
    // So, if we wanted to rotate using the center of the image and escale the image, we could
    //   A) data = "q " . sx($w, $h) . tx(0.5, 0.5) . rx(90) . tx(-0.5, -0.5) . " /Image Do Q";
    //   or B) data = "q " . tx($w/2, $h/2) . rx(90) . tx(-$w/2, -$h/2) . sx($x, $w)" /Image Do Q";

    /* Option A
    $data .= sx($h, $w);  <-- the order is inverted because now the image is rotated, so that it keeps the original size of the box where it should appear
    $data .= tx(0.5, 0.5);
    $data .= rx(90);
    $data .= tx(-0.5,-0.5);
    */

    /* Option B
    $data .= tx($w / 2, $h / 2);
    $data .= rx(90);
    $data .= tx(-$w / 2, -$h / 2);
    $data .= sx($w, $h);
    */

    $data = 'q';
    $data .= tx($x, $y);
    $data .= sx($w, $h);
    if ($angle != 0) {
        $data .= tx(0.5, 0.5);
        $data .= rx($angle);
        $data .= tx(-0.5, -0.5);
    }

    $data .= sprintf(' /%s Do Q', $info['i']);

    $resources = new PDFValueObject([
        'ProcSet' => ['/PDF', '/Text', '/ImageB', '/ImageC', '/ImageI'],
        'XObject' => new PDFValueObject([
            $info['i'] => new PDFValueReference($images_objects[0]->get_oid()),
        ]),
    ]);

    return [
        'image' => $images_objects[0],
        'command' => $data,
        'resources' => $resources,
        'alpha' => $add_alpha,
    ];
}
