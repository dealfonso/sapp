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

use ddn\sapp\helpers\Buffer;
use ddn\sapp\helpers\LoadHelpers;
use ddn\sapp\helpers\StreamReader;
use ddn\sapp\pdfvalue\PDFValue;
use function ddn\sapp\helpers\p_warning;

if (! defined(LoadHelpers::class)) {
    new LoadHelpers();
}

// TODO: use the streamreader to deal with the document in the file, instead of a buffer

class PDFUtilFnc
{
    public static function get_trailer(&$_buffer, int $trailer_pos): PDFValue|false|null
    {
        // Search for the trailer structure
        if (preg_match('/trailer\s*(.*)\s*startxref/ms', (string) $_buffer, $matches, 0, $trailer_pos) !== 1) {
            throw new PDFException('trailer not found');
        }

        $trailer_str = $matches[1];

        // We create the object to parse (this is not innefficient, because it is disposed when returning from the function)
        //   and parse the trailer content.
        $parser = new PDFObjectParser();
        try {
            $trailer_obj = $parser->parsestr($trailer_str);
        } catch (Exception) {
            throw new PDFException('trailer is not valid');
        }

        return $trailer_obj;
    }

    public static function build_xref_1_5($offsets): array
    {
        if (isset($offsets[0])) {
            unset($offsets[0]);
        }
        $k = array_keys($offsets);
        sort($k);

        $indexes = [];
        $i_k = 0;
        $c_k = 0;
        $count = 1;
        $result = '';
        for ($i = 0, $iMax = count($k); $i < $iMax; $i++) {
            if ($c_k === 0) {
                $c_k = $k[$i] - 1;
                $i_k = $k[$i];
                $count = 0;
            }
            if ($k[$i] === $c_k + 1) {
                $count++;
            } else {
                $indexes[] = "{$i_k} {$count}";
                $count = 1;
                $i_k = $k[$i];
            }
            $c_offset = $offsets[$k[$i]];

            if (is_array($c_offset)) {
                $result .= pack('C', 2);
                $result .= pack('N', $c_offset['stmoid']);
                $result .= pack('C', $c_offset['pos']);
            } else {
                if ($c_offset === null) {
                    $result .= pack('C', 0);
                } else {
                    $result .= pack('C', 1);
                }
                $result .= pack('N', $c_offset);
                $result .= pack('C', 0);
            }
            $c_k = $k[$i];
        }
        $indexes[] = "{$i_k} {$count}";
        $indexes = implode(' ', $indexes);

        // p_debug(show_bytes($result, 6));

        return [
            'W' => [1, 4, 1],
            'Index' => $indexes,
            'stream' => $result,
        ];
    }

    /**
     * This function obtains the xref from the cross reference streams (7.5.8 Cross-Reference Streams)
     *   which started in PDF 1.5.
     */
    public static function get_xref_1_5(&$_buffer, int $xref_pos, ?int $depth = null): false|array
    {
        if ($depth !== null) {
            if ($depth <= 0) {
                return false;
            }

            $depth = $depth - 1;
        }

        $xref_o = self::find_object_at_pos($_buffer, null, $xref_pos, []);
        if ($xref_o === false) {
            throw new PDFException("cross reference object not found when parsing xref at position {$xref_pos}", [false, false, false]);
        }

        if (! (isset($xref_o['Type'])) || ($xref_o['Type']->val() !== 'XRef')) {
            throw new PDFException('invalid xref table', [false, false, false]);
        }

        $stream = $xref_o->get_stream(false);
        if ($stream === null) {
            throw new PDFException("cross reference stream not found when parsing xref at position {$xref_pos}", [false, false, false]);
        }

        $W = $xref_o['W']->val(true);
        if (count($W) !== 3) {
            throw new PDFException('invalid cross reference object', [false, false, false]);
        }

        $W[0] = (int) $W[0];
        $W[1] = (int) $W[1];
        $W[2] = (int) $W[2];

        $Size = $xref_o['Size']->get_int();
        if ($Size === false) {
            throw new PDFException('could not get the size of the xref table', [false, false, false]);
        }

        $Index = [0, $Size];
        if (isset($xref_o['Index'])) {
            $Index = $xref_o['Index']->val(true);
        }

        if (count($Index) % 2 !== 0) {
            throw new PDFException('invalid indexes of xref table', [false, false, false]);
        }

        // Get the previous xref table, to build up on it
        $trailer_obj = null;
        $xref_table = [];

        if (($depth === null) || ($depth > 0)) {
            // If still want to get more versions, let's check whether there is a previous xref table or not

            if (isset($xref_o['Prev'])) {
                $Prev = $xref_o['Prev'];
                $Prev = $Prev->get_int();
                if ($Prev === false) {
                    throw new PDFException('invalid reference to a previous xref table', [false, false, false]);
                }

                // When dealing with 1.5 cross references, we do not allow to use other than cross references
                [$xref_table, $trailer_obj] = self::get_xref_1_5($_buffer, $Prev, $depth);
                // p_debug_var($xref_table);
            }
        }

        // p_debug("xref table found at $xref_pos (oid: " . $xref_o->get_oid() . ")");
        $stream_v = new StreamReader($stream);

        // Get the format function to un pack the values
        $get_fmt_function = function ($f) {
            if ($f === false) {
                return false;
            }

            return match ($f) {
                0 => fn ($v): int => 0,
                1 => fn ($v) => unpack('C', str_pad($v, 1, chr(0), STR_PAD_LEFT))[1],
                2 => fn ($v) => unpack('n', str_pad($v, 2, chr(0), STR_PAD_LEFT))[1],
                3, 4 => fn ($v) => unpack('N', str_pad($v, 4, chr(0), STR_PAD_LEFT))[1],
                5, 6, 7, 8 => fn ($v) => unpack('J', str_pad($v, 8, chr(0), STR_PAD_LEFT))[1],
                default => false,
            };
        };

        $fmt_function = [
            $get_fmt_function($W[0]),
            $get_fmt_function($W[1]),
            $get_fmt_function($W[2]),
        ];

        // p_debug("xref entries at $xref_pos for object " . $xref_o->get_oid());
        // p_debug(show_bytes($stream, $W[0] + $W[1] + $W[2]));

        // Parse the stream according to the indexes and the W array
        $index_i = 0;
        while ($index_i < count($Index)) {
            $object_i = $Index[$index_i++];
            $object_count = $Index[$index_i++];

            while (($stream_v->currentchar() !== false) && ($object_count > 0)) {
                $f1 = $W[0] != 0 ? ($fmt_function[0]($stream_v->nextchars($W[0]))) : 1;
                $f2 = $fmt_function[1]($stream_v->nextchars($W[1]));
                $f3 = $fmt_function[2]($stream_v->nextchars($W[2]));

                if (($f1 === false) || ($f2 === false) || ($f3 === false)) {
                    throw new PDFException('invalid stream for xref table', [false, false, false]);
                }

                switch ($f1) {
                    case 0:
                        // Free object
                        $xref_table[$object_i] = null;
                        break;
                    case 1:
                        // Add object
                        $xref_table[$object_i] = $f2;
                        /*
                        TODO: consider creating a generation table, but for the purpose of the xref there is no matter... if the document if well-formed.
                        */
                        if ($f3 !== 0) {
                            p_warning('Objects of non-zero generation are not fully checked... please double check your document and (if possible) please send examples via issues to https://github.com/dealfonso/sapp/issues/');
                        }

                        break;
                    case 2:
                        // Stream object
                        // $f2 is the number of a stream object, $f3 is the index in that stream object
                        $xref_table[$object_i] = [
                            'stmoid' => $f2,
                            'pos' => $f3,
                        ];
                        break;
                    default:
                        throw new PDFException("do not know about entry of type {$f1} in xref table");
                }

                $object_i++;
                $object_count--;
            }
        }

        return [$xref_table, $xref_o->get_value(), '1.5'];
    }

    public static function get_xref_1_4(&$_buffer, $xref_pos, $depth = null): false|array
    {
        if ($depth !== null) {
            if ($depth <= 0) {
                return false;
            }

            $depth = $depth - 1;
        }

        $trailer_pos = strpos((string) $_buffer, 'trailer', $xref_pos);
        $min_pdf_version = '1.4';

        // Get the xref content and make sure that the buffer passed contains the xref tag at the offset provided
        $xref_substr = substr((string) $_buffer, $xref_pos, $trailer_pos - $xref_pos);

        $separator = "\r\n";
        $xref_line = strtok($xref_substr, $separator);
        if ($xref_line !== 'xref') {
            throw new PDFException("xref tag not found at position {$xref_pos}", [false, false, false]);
        }

        // Now parse the lines and build the xref table
        $obj_id = false;
        $obj_count = 0;
        $xref_table = [];

        while (($xref_line = strtok($separator)) !== false) {
            // The first type of entry contains the id of the next object and the amount of continuous objects defined
            if (preg_match('/([0-9]+) ([0-9]+)$/', $xref_line, $matches) === 1) {
                if ($obj_count > 0) {
                    // If still expecting objects, we'll assume that the xref is malformed
                    throw new PDFException("malformed xref at position {$xref_pos}", [false, false, false]);
                }
                $obj_id = (int) $matches[1];
                $obj_count = (int) $matches[2];
                continue;
            }

            // The other type of entry contains the offset of the object, the generation and the command (which is "f" for "free" or "n" for "new")
            if (preg_match('/^([0-9]+) ([0-9]+) (.)\s*/', $xref_line, $matches) === 1) {
                // If no object expected, we'll assume that the xref is malformed
                if ($obj_count === 0) {
                    throw new PDFException("unexpected entry for xref: {$xref_line}", [false, false, false]);
                }

                $obj_offset = (int) $matches[1];
                $obj_generation = (int) $matches[2];
                $obj_operation = $matches[3];

                if ($obj_offset !== 0) {
                    // Make sure that the operation is one of those expected
                    switch ($obj_operation) {
                        case 'f':
                            // * a "f" entry is read as:
                            //      (e.g. for object_id = 6) 0000000015 00001 f
                            //         the next free object is the one with id 15; if wanted to re-use this object id 6, it must be using generation 1
                            //      if the next generation is 65535, it would mean that this ID cannot be used again.
                            // - a "f" entry means that the object is "free" for now
                            // - the "f" entries form a linked list, where the last element in the list must point to "0"
                            //
                            // For the purpose of the xref table, there is no need to take care of the free-object list. And for the purpose
                            //   of SAPP, neither. If ever wanted to add a new object SAPP will get a greater ID than the actual greater one.
                            // TODO: consider taking care of the free linked list, (e.g.) to check consistency
                            $xref_table[$obj_id] = null;
                            break;
                        case 'n':
                            // - a "n" entry means that the object is in the offset, with the given generation
                            // For the purpose of the xref table, there is no issue with non-zero generation; the problem may arise if
                            //  for example, in the xref table we include a generation that is different from the generarion of the object
                            //  in the actual offset.
                            // TODO: consider creating a "generation table"
                            $xref_table[$obj_id] = $obj_offset;
                            if ($obj_generation != 0) {
                                p_warning('Objects of non-zero generation are not fully checked... please double check your document and (if possible) please send examples via issues to https://github.com/dealfonso/sapp/issues/');
                            }
                            break;
                        default:
                            // If it is not one of the expected, let's skip the object
                            throw new PDFException("invalid entry for xref: {$xref_line}", [false, false, false]);
                    }
                }

                --$obj_count;
                $obj_id++;
                continue;
            }

            // If the entry is not recongised, show the error
            throw new PDFException("invalid xref entry {$xref_line}");
        }

        // Get the trailer object
        $trailer_obj = self::get_trailer($_buffer, $trailer_pos);

        // If there exists a previous xref (for incremental PDFs), get it and merge the objects that do not exist in the current xref table
        if (isset($trailer_obj['Prev'])) {
            $xref_prev_pos = $trailer_obj['Prev']->val();
            if (! is_numeric($xref_prev_pos)) {
                throw new PDFException("invalid trailer {$trailer_obj}", [false, false, false]);
            }

            $xref_prev_pos = (int) $xref_prev_pos;

            [$prev_table, $prev_trailer, $prev_min_pdf_version] = self::get_xref_1_4($_buffer, $xref_prev_pos, $depth);

            if ($prev_min_pdf_version !== $min_pdf_version) {
                throw new PDFException('mixed type of xref tables are not supported', [false, false, false]);
            }

            if ($prev_table !== false) {
                foreach ($prev_table as $obj_id => $obj_offset) {              // Not modifying the objects, but to make sure that it does not consume additional memory
                    // If there not exists a new version, we'll acquire it
                    if (! isset($xref_table[$obj_id])) {
                        $xref_table[$obj_id] = $obj_offset;
                    }
                }
            }
        }

        return [$xref_table, $trailer_obj, $min_pdf_version];
    }

    public static function get_xref(string &$_buffer, ?int $xref_pos, ?int $depth = null): array
    {
        // Each xref is immediately followed by a trailer
        $trailer_pos = strpos((string) $_buffer, 'trailer', $xref_pos);
        if ($trailer_pos === false) {
            [$xref_table, $trailer_obj, $min_pdf_version] = self::get_xref_1_5($_buffer, $xref_pos, $depth);
        } else {
            [$xref_table, $trailer_obj, $min_pdf_version] = self::get_xref_1_4($_buffer, $xref_pos, $depth);
        }

        return [$xref_table, $trailer_obj, $min_pdf_version];
    }

    public static function acquire_structure(string &$_buffer, ?int $depth = null): false|array
    {
        // Get the first line and acquire the PDF version of the document
        $separator = "\r\n";
        $pdf_version = strtok($_buffer, $separator);
        if ($pdf_version === false) {
            return false;
        }

        if (preg_match('/^%PDF-[0-9]+\.[0-9]+$/', $pdf_version, $matches) !== 1) {
            throw new PDFException('PDF version string not found');
        }

        if (preg_match_all('/startxref\s*([0-9]+)\s*%%EOF($|[\r\n])/ms', (string) $_buffer, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw new PDFException('failed to get structure');
        }

        $_versions = [];
        /*
        print_r($matches);
        exit();
        */
        foreach ($matches as $match) {
            $_versions[] = $match[2][1] + strlen($match[2][0]);
        }

        // Now get the trailing part and make sure that it has the proper form
        $startxref_pos = strrpos((string) $_buffer, 'startxref');
        if ($startxref_pos === false) {
            throw new PDFException('startxref not found');
        }

        if (preg_match('/startxref\s*([0-9]+)\s*%%EOF\s*$/ms', (string) $_buffer, $matches, 0, $startxref_pos) !== 1) {
            throw new PDFException('startxref and %%EOF not found');
        }

        $xref_pos = (int) $matches[1];

        if ($xref_pos === 0) {
            // This is a dummy xref position from linearized documents
            return [
                'trailer' => false,
                'version' => substr($pdf_version, 1),
                'xref' => [],
                'xrefposition' => 0,
                'xrefversion' => substr($pdf_version, 1),
                'revisions' => $_versions,
            ];
        }

        [$xref_table, $trailer_object, $min_pdf_version] = self::get_xref($_buffer, $xref_pos, $depth);

        // We are providing a lot of information to be able to inspect the problems of a PDF file
        if ($xref_table === false) {
            // TODO: Maybe we could include a "recovery" method for this: if xref is not at pos $xref_pos, we could search for xref by hand
            throw new PDFException('could not find the xref table');
        }

        if ($trailer_object === false) {
            throw new PDFException('could not find the trailer object');
        }

        return [
            'trailer' => $trailer_object,
            'version' => substr($pdf_version, 1),
            'xref' => $xref_table,
            'xrefposition' => $xref_pos,
            'xrefversion' => $min_pdf_version,
            'revisions' => $_versions,
        ];
    }

    /**
     * Function that finds a the object at the specific position in the buffer
     *
     * @param buffer the buffer from which to read the document
     * @param oid the target object id to read (if null, will return the first object, if found)
     * @param offset the offset at which the object is expected to be
     * @param xref_table the xref table, to be able to find indirect objects
     *
     * @return obj the PDFObject obtained from the file or false if could not be found
     */
    public static function find_object_at_pos(&$_buffer, ?int $oid, int $object_offset, $xref_table): false|PDFObject
    {
        $object = self::object_from_string($_buffer, $oid, $object_offset, $offset_end);

        if ($object === false) {
            return false;
        }

        $_stream_pending = false;

        // The distinction is required, because we need to get the proper start for the stream, and if using CRLF instead of LF
        //   - according to https://www.adobe.com/content/dam/acom/en/devnet/pdf/PDF32000_2008.pdf, stream is followed by CRLF
        //     or LF, but not single CR.
        if (substr((string) $_buffer, $offset_end - 7, 7) === "stream\n") {
            $_stream_pending = $offset_end;
        }

        if (substr((string) $_buffer, $offset_end - 7, 8) === "stream\r\n") {
            $_stream_pending = $offset_end + 1;
        }

        // If it expects a stream, get it
        if ($_stream_pending !== false) {
            $length = $object['Length']->get_int();
            if ($length === false) {
                $length_object_id = $object['Length']->get_object_referenced();
                if ($length_object_id === false) {
                    throw new PDFException("could not get stream for object {$oid}");
                }
                $length_object = self::find_object($_buffer, $xref_table, $length_object_id);
                if ($length_object === false) {
                    throw new PDFException("could not get object {$oid}");
                }

                $length = $length_object->get_value()?->get_int();
            }

            if ($length === false) {
                throw new PDFException("could not get stream length for object {$oid}");
            }

            $object->set_stream(substr((string) $_buffer, $_stream_pending, $length), true);
        }

        return $object;
    }

    /**
     * Function that finds a specific object in the document, using the xref table as a base
     *
     * @param buffer the buffer from which to read the document
     * @param xref_table the xref table
     * @param oid the target object id to read
     *
     * @return obj the PDFObject obtained from the file or false if could not be found
     */
    public static function find_object(&$_buffer, $xref_table, int $oid): false|PDFObject
    {
        if ($oid === 0) {
            return false;
        }
        if (! isset($xref_table[$oid])) {
            return false;
        }

        // Find the object and get where it ends
        $object_offset = $xref_table[$oid];

        if (! is_array($object_offset)) {
            return self::find_object_at_pos($_buffer, $oid, $object_offset, $xref_table);
        }
        $object = self::find_object_in_objstm($_buffer, $xref_table, $object_offset['stmoid'], $object_offset['pos'], $oid);

        return $object;
    }

    /**
     * Function that searches for an object in an object stream
     */
    public static function find_object_in_objstm(&$_buffer, $xref_table, int $objstm_oid, $objpos, int $oid): PDFObject
    {
        $objstm = self::find_object($_buffer, $xref_table, $objstm_oid);
        if ($objstm === false) {
            throw new PDFException("could not get object stream {$objstm_oid}");
        }

        if ((($objstm['Extends'] ?? false) !== false)) { // TODO: support them
            throw new PDFException('not supporting extended object streams at this time');
        }

        $First = $objstm['First'] ?? false;
        $N = $objstm['N'] ?? false;
        $Type = $objstm['Type'] ?? false;

        if (($First === false) || ($N === false) || ($Type === false)) {
            throw new PDFException("invalid object stream {$objstm_oid}");
        }

        if ($Type->val() !== 'ObjStm') {
            throw new PDFException("object {$objstm_oid} is not an object stream");
        }

        $First = $First->get_int();
        $N = $N->get_int();

        $stream = $objstm->get_stream(false);
        $index = substr((string) $stream, 0, $First);
        $index = explode(' ', trim($index));
        $stream = substr((string) $stream, $First);

        if (count($index) % 2 !== 0) {
            throw new PDFException("invalid index for object stream {$objstm_oid}");
        }

        $objpos = $objpos * 2;
        if ($objpos > count($index)) {
            throw new PDFException("object {$oid} not found in object stream {$objstm_oid}");
        }

        $offset = (int) $index[$objpos + 1];
        $next = 0;
        $offsets = [];
        for ($i = 1; ($i < count($index)); $i = $i + 2) {
            $offsets[] = (int) $index[$i];
        }

        $offsets[] = strlen($stream);
        sort($offsets);
        for ($i = 0; ($i < count($offsets)) && ($offset >= $offsets[$i]); $i++) {
        }

        $next = $offsets[$i];

        $object_def_str = "{$oid} 0 obj " . substr($stream, $offset, $next - $offset) . ' endobj';

        return self::object_from_string($object_def_str, $oid);
    }

    /**
     * Function that parses an object
     */
    public static function object_from_string(string $buffer, ?int $expected_obj_id, int $offset = 0, ?int &$offset_end = 0): PDFObject
    {
        if (preg_match('/([0-9]+)\s+([0-9+])\s+obj(\s+)/ms', (string) $buffer, $matches, 0, $offset) !== 1) {
            // p_debug_var(substr($buffer))
            throw new PDFException("object is not valid: {$expected_obj_id}");
        }

        $found_obj_header = $matches[0];
        $found_obj_id = (int) $matches[1];
        $found_obj_generation = (int) $matches[2];

        if ($expected_obj_id === null) {
            $expected_obj_id = $found_obj_id;
        }

        if ($found_obj_id !== $expected_obj_id) {
            throw new PDFException("pdf structure is corrupt: found obj {$found_obj_id} while searching for obj {$expected_obj_id} (at {$offset})");
        }

        // The object starts after the header
        $offset = $offset + strlen($found_obj_header);

        // Parse the object
        $parser = new PDFObjectParser();

        $stream = new StreamReader($buffer, $offset);

        $obj_parsed = $parser->parse($stream);
        if ($obj_parsed === false) {
            throw new PDFException("object {$expected_obj_id} could not be parsed");
        }

        switch ($parser->current_token()) {
            case PDFObjectParser::T_OBJECT_END:
                // The object has ended correctly
                break;
            case PDFObjectParser::T_STREAM_BEGIN:
                // There is an stream
                break;
            default:
                throw new PDFException('malformed object');
        }

        $offset_end = $stream->getpos();

        return new PDFObject($found_obj_id, $obj_parsed, $found_obj_generation);
    }

    /**
     * Builds the xref for the document, using the list of objects
     *
     * @param offsets an array indexed by the oid of the objects, with the offset of each
     *  object in the document.
     *
     * @return xref_string a string that contains the xref table, ready to be inserted in the document
     */
    public static function build_xref(array $offsets): string
    {
        $k = array_keys($offsets);
        sort($k);

        $i_k = 0;
        $c_k = 0;
        $count = 1;
        $result = '';
        $references = "0000000000 65535 f \n";
        for ($i = 0, $iMax = count($k); $i < $iMax; $i++) {
            if ($k[$i] === 0) {
                continue;
            }
            if ($k[$i] === $c_k + 1) {
                $count++;
            } else {
                $result = $result . "{$i_k} {$count}\n{$references}";
                $count = 1;
                $i_k = $k[$i];
                $references = '';
            }
            $references .= sprintf("%010d 00000 n \n", $offsets[$k[$i]]);
            $c_k = $k[$i];
        }
        $result = $result . "{$i_k} {$count}\n{$references}";

        return "xref\n{$result}";
    }
}
