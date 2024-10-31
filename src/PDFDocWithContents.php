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

namespace ddn\sapp;

use ddn\sapp\pdfvalue\PDFValueList;
use ddn\sapp\pdfvalue\PDFValueObject;
use ddn\sapp\pdfvalue\PDFValueReference;
use function ddn\sapp\helpers\_add_image;
use function ddn\sapp\helpers\get_random_string;
use function ddn\sapp\helpers\p_warning;

class PDFDocWithContents extends PDFDoc
{
    public const T_STANDARD_FONTS = [
        'Times-Roman',
        'Times-Bold',
        'Time-Italic',
        'Time-BoldItalic',
        'Courier',
        'Courier-Bold',
        'Courier-Oblique',
        'Courier-BoldOblique',
        'Helvetica',
        'Helvetica-Bold',
        'Helvetica-Oblique',
        'Helvetica-BoldOblique',
        'Symbol',
        'ZapfDingbats',
    ];

    /**
     * This is a function that allows to add a very basic text to a page, using a standard font.
     *   The function is mainly oriented to add banners and so on, and not to use for writting.
     *
     * @param page the number of page in which the text should appear
     * @param text the text
     * @param x the x offset from left for the text (we do not take care of margins)
     * @param y the y offset from top for the text (we do not take care of margins)
     * @param params an array of values [ "font" => <fontname>, "size" => <size in pt>,
     *               "color" => <#hexcolor>, "angle" => <rotation angle>]
     */
    public function add_text(int $page_to_appear, $text, $x, $y, $params = []): void
    {
        // TODO: maybe we can create a function that "adds content to a page", and that
        //       function will search for the content field and merge the resources, if
        //       needed
        p_warning('This function still needs work');

        $default = [
            'font' => 'Helvetica',
            'size' => 24,
            'color' => '#000000',
            'angle' => 0,
        ];

        $params = array_merge($default, $params);

        $page_obj = $this->get_page($page_to_appear);
        if ($page_obj === false) {
            throw new PDFException('invalid page');
        }

        $resources_obj = $this->get_indirect_object($page_obj['Resources']);

        if (! in_array($params['font'], self::T_STANDARD_FONTS, true)) {
            throw new PDFException('only standard fonts are allowed Times-Roman, Helvetica, Courier, Symbol, ZapfDingbats');
        }

        $font_id = 'F' . get_random_string(4);
        $resources_obj['Font'][$font_id] = [
            'Type' => '/Font',
            'Subtype' => '/Type1',
            'BaseFont' => '/' . $params['font'],
        ];

        // Get the contents for the page
        $contents_obj = $this->get_indirect_object($page_obj['Contents']);

        $data = $contents_obj->get_stream(false);
        if ($data === false) {
            throw new PDFException('could not interpret the contents of the page');
        }

        // Get the page height, to change the coordinates system (up to down)
        $pagesize = $this->get_page_size($page_to_appear);
        $pagesize_h = (float) ('' . $pagesize[3]) - (float) ('' . $pagesize[1]);

        $angle = $params['angle'];
        $angle *= M_PI / 180;
        $c = cos($angle);
        $s = sin($angle);
        $cx = $x;
        $cy = ($pagesize_h - $y);

        if ($angle !== 0) {
            $rotate_command = sprintf('%.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', $c, $s, -$s, $c, $cx, $cy, -$cx, -$cy);
        }

        $text_command = 'BT ';
        $text_command .= "/{$font_id} " . $params['size'] . ' Tf ';
        $text_command .= sprintf('%.2f %.2f Td ', $x, $pagesize_h - $y); // Ubicar en x, y
        $text_command .= sprintf('(%s) Tj ', $text);
        $text_command .= 'ET ';

        $color = $params['color'];
        if ($color[0] === '#') {
            $colorvalid = true;
            $r = null;
            switch (strlen((string) $color)) {
                case 4:
                    $color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
                    // no break
                case 7:
                    [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
                    break;
                default:
                    throw new PDFException('please use html-like colors (e.g. #ffbbaa)');
            }
            if ($r !== null) {
                $text_command = " q {$r} {$g} {$b} rg {$text_command} Q";
            } // Color RGB
        } else {
            throw new PDFException('please use html-like colors (e.g. #ffbbaa)');
        }

        if ($angle !== 0) {
            $text_command = " q {$rotate_command} {$text_command} Q";
        }

        $data .= $text_command;

        $contents_obj->set_stream($data, false);

        // Update the contents
        $this->add_object($resources_obj);
        $this->add_object($contents_obj);
    }

    /**
     * Adds an image to the document, in the specific page
     *   NOTE: the image inclusion is taken from http://www.fpdf.org/; this is an adaptation
     *         and simplification of function Image(); it does not take care about units nor
     *         page breaks
     *
     * @param page_obj the page object (or the page number) in which the image will appear
     * @param filename the name of the file that contains the image (or the content of the file, with the character '@' prepended)
     * @param x the x position (in pixels) where the image will appear
     * @param y the y position (in pixels) where the image will appear
     * @param w the width of the image
     * @param w the height of the image
     */
    public function add_image($page_obj, $filename, $x = 0, $y = 0, $w = 0, $h = 0): bool
    {
        // TODO: maybe we can create a function that "adds content to a page", and that
        //       function will search for the content field and merge the resources, if
        //       needed
        p_warning('This function still needs work');

        // Check that the page is valid
        if (is_int($page_obj)) {
            $page_obj = $this->get_page($page_obj);
        }

        if ($page_obj === false) {
            throw new PDFException('invalid page');
        }

        // Get the page height, to change the coordinates system (up to down)
        $pagesize = $this->get_page_size($page_obj);
        $pagesize_h = (float) ('' . $pagesize[3]) - (float) ('' . $pagesize[1]);

        _add_image($filename, $x, $pagesize_h - $y, $w, $h);

        throw new PDFException('this function still needs work');
        // Get the resources for the page
        $resources_obj = $this->get_indirect_object($page_obj['Resources']);
        if (! isset($resources_obj['ProcSet'])) {
            $resources_obj['ProcSet'] = new PDFValueList(['/PDF']);
        }
        $resources_obj['ProcSet']->push(['/ImageB', '/ImageC', '/ImageI']);
        if (! isset($resources_obj['XObject'])) {
            $resources_obj['XObject'] = new PDFValueObject();
        }
        $resources_obj['XObject'][$info['i']] = new PDFValueReference($images_objects[0]->get_oid());

        // TODO: get the contents object in which to add the image.
        //       this is a bit hard, because we have multiple options (e.g. the contents is an indirect object
        //       or the contents is an array of objects)
        $contents_obj = $this->get_indirect_object($page_obj['Contents']);

        $data = $contents_obj->get_stream(false);
        if ($data === false) {
            throw new PDFException('could not interpret the contents of the page');
        }

        // Append the command to draw the image
        $data .= $result['command'];

        // Update the contents of the page
        $contents_obj->set_stream($data, false);

        if ($add_alpha === true) {
            $page_obj['Group'] = new PDFValueObject([
                'Type' => '/Group',
                'S' => '/Transparency',
                'CS' => '/DeviceRGB',
            ]);
            $this->add_object($page_obj);
        }

        foreach ([$resources_obj, $contents_obj] as $o) {
            $this->add_object($o);
        }

        return true;
    }
}
