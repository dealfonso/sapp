<?php
/*
    This file is part of SAPP

    Simply A PDF Parser (SAPP) - Parse PDF documents in PHP (and update them)
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

use ddn\sapp\PDFObjectParser;
use \StreamReader;
use \Buffer;

require_once(__DIR__ . "/inc/buffer.php");
require_once(__DIR__ . "/inc/streamreader.php");

// TODO: use the streamreader to deal with the document in the file, instead of a buffer

class PDFBaseDoc extends Buffer {

    protected function get_new_oid() {
        $this->_max_oid++;
        return $this->_max_oid;
    }

    protected static function get_trailer(&$_buffer, $trailer_pos) {
        // Search for the trailer structure
        if (preg_match('/trailer\s*(.*)\s*startxref/ms', $_buffer, $matches, 0, $trailer_pos) !== 1)
            return p_error("trailer not found");
        
        $trailer_str = $matches[1];

        // We create the object to parse (this is not innefficient, because it is disposed when returning from the function)
        //   and parse the trailer content.
        $parser = new PDFObjectParser();
        try {
            $trailer_obj = $parser->parsestr($trailer_str);
        } catch (Exception $e) {
            return p_error("trailer is not valid");
        }

        return $trailer_obj;
    }

    protected static function get_xref(&$_buffer, $xref_pos) {

        // Each xref is immediately followed by a trailer
        $trailer_pos = strpos($_buffer, "trailer", $xref_pos);
        if ($trailer_pos === false)
            return p_error("trailer not found when parsing xref at position $xref_pos", [false, false]);

        // Get the xref content and make sure that the buffer passed contains the xref tag at the offset provided
        $xref_substr = substr($_buffer, $xref_pos, $trailer_pos - $xref_pos);

        $separator = "\r\n";
        $xref_line = strtok($xref_substr, $separator);
        if ($xref_line !== 'xref')
            return p_error("xref tag not found at position $xref_pos", [false, false]);
        
        // Now parse the lines and build the xref table
        $obj_id = false;
        $obj_count = 0;
        $xref_table = [];

        while (($xref_line = strtok($separator)) !== false) {

            // The first type of entry contains the id of the next object and the amount of continuous objects defined
            if (preg_match('/^([0-9]+) ([0-9]+)$/', $xref_line, $matches) === 1) {
                if ($obj_count > 0) {
                    // If still expecting objects, we'll assume that the xref is malformed
                    return p_error("malformed xref at position $xref_pos", [false, false]);
                }
                $obj_id = intval($matches[1]);
                $obj_count = intval($matches[2]);
                continue;
            }

            // The other type of entry contains the offset of the object, the generation and the command (which is "f" for "free" or "n" for "new")
            if (preg_match('/^([0-9]+) ([0-9]+) (.)\s*/', $xref_line, $matches) === 1) {

                // If no object expected, we'll assume that the xref is malformed
                if ($obj_count === 0)
                    return p_error("unexpected entry for xref: $xref_line", [false, false]);

                $obj_offset = intval($matches[1]);
                $obj_generation = intval($matches[2]);
                $obj_operation = $matches[3];

                if ($obj_offset !== 0) {
                    // TODO: Dealing with non-zero generation objects is a future work, as I do not know by now the implications of generation change
                    if (!(($obj_id === 0) && ($obj_generation === 65535))) {
                        if (intval($obj_generation) !== 0) {
                            return p_error("SORRY, but do not know how to deal with non-0 generation objects", [false, false]);
                        }
                    }

                    // Make sure that the operation is one of those expected
                    switch ($obj_operation) {
                        case 'f':
                            $xref_table[$obj_id] = null;
                            break;
                        case 'n':
                            $xref_table[$obj_id] = $obj_offset;
                            break;
                        default:
                            // If it is not one of the expected, let's skip the object
                            p_error("invalid entry for xref: $xref_line", [false, false]);
                    }
                }

                $obj_count-= 1;
                $obj_id++;
                continue;
            }

            // If the entry is not recongised, show the error
            p_error("invalid xref entry $xref_line");
            $xref_line = strtok($separator);
        }

        // Get the trailer object
        $trailer_obj = self::get_trailer($_buffer, $trailer_pos);

        // If there exists a previous xref (for incremental PDFs), get it and merge the objects that do not exist in the current xref table
        if (isset($trailer_obj['Prev'])) {
            
            $xref_prev_pos = $trailer_obj['Prev']->val();
            if (!is_numeric($xref_prev_pos))
                return p_error("invalid trailer $trailer_obj", [false, false]);

            $xref_prev_pos = intval($xref_prev_pos);

            [ $prev_table, $prev_trailer ] = self::get_xref($_buffer, $xref_prev_pos);
            if ($prev_table !== false) {
                foreach ($prev_table as $obj_id => &$obj_offset) {              // Not modifying the objects, but to make sure that it does not consume additional memory
                    // If there not exists a new version, we'll acquire it
                    if (!isset($xref_table[$obj_id])) {
                        $xref_table[$obj_id] = $obj_offset;
                    }
                }
            }
        }

        return [ $xref_table, $trailer_obj ];
    }

    protected static function acquire_structure(&$_buffer) {
        // Get the first line and acquire the PDF version of the document
        $separator = "\r\n";
        $pdf_version = strtok($_buffer, $separator);
        if ($pdf_version === false)
            return false;

        if (preg_match('/^%PDF-[0-9]+\.[0-9]+$/', $pdf_version, $matches) !== 1)
            return p_error("PDF version string not found");

        // Now get the trailing part and make sure that it has the proper form
        $startxref_pos = strrpos($_buffer, "startxref");
        if ($startxref_pos === false)
            return p_error("startxref not found");

        if (preg_match('/startxref\s*([0-9]+)\s*%%EOF\s*$/ms', $_buffer, $matches, 0, $startxref_pos) !== 1)
            return p_error("startxref and %%EOF not found");

        $xref_pos = intval($matches[1]);

        [ $xref_table, $trailer_object ] = self::get_xref($_buffer, $xref_pos);

        // We are providing a lot of information to be able to inspect the problems of a PDF file
        if ($xref_table === false) {
            // TODO: Maybe we could include a "recovery" method for this: if xref is not at pos $xref_pos, we could search for xref by hand
            return p_error("could not find the xref table");
        }

        if ($trailer_object === false)
            return p_error("could not find the trailer object");

        return [
            "trailer" => $trailer_object,
            "version" => substr($pdf_version, 1),
            "xref" => $xref_table,
            "xrefposition" => $xref_pos
        ];
    }

    /**
     * Signs a file using the certificate and key and obtains the signature content padded to the max signature length
     * @param filename the name of the file to sign
     * @param certificate the public key to sign
     * @param key the private key to sign
     * @param tmpfolder the folder in which to store a temporary file needed
     * @return signature the signature, in hexadecimal string, padded to the maximum length (i.e. for PDF) or false in case of error
     */
    protected static function calculate_pkcs7_signature($filenametosign, $certificate, $key, $tmpfolder = "/tmp") {    
        $filesize_original = filesize($filenametosign);
        if ($filesize_original === false)
            return p_error("could not open file $filenametosign");

        $temp_filename = tempnam($tmpfolder, "pdfsign");

        if ($temp_filename === false)
            return p_error("could not create a temporary filename");

        if (openssl_pkcs7_sign($filenametosign, $temp_filename, $certificate, $key, array(), PKCS7_BINARY | PKCS7_DETACHED) !== true) {
            unlink($temp_filename);
            return p_error("failed to sign file $tempfile");
        }

        $signature = file_get_contents($temp_filename);
        // extract signature
        $signature = substr($signature, $filesize_original);
        $signature = substr($signature, (strpos($signature, "%%EOF\n\n------") + 13));

        $tmparr = explode("\n\n", $signature);
        $signature = $tmparr[1];
        // decode signature
        $signature = base64_decode(trim($signature));

        // convert signature to hex
        $signature = current(unpack('H*', $signature));
        $signature = str_pad($signature, __SIGNATURE_MAX_LENGTH, '0');       

        return $signature;
    }   
    
    /**
     * Function that finds a specific object in the document, using the xref table as a base
     * @param buffer the buffer from which to read the document
     * @param xref_table the xref table
     * @param oid the target object id to read 
     * @return obj the PDFObject obtained from the file or false if could not be found
     */
    protected static function find_object(&$_buffer, $xref_table, $oid) {
        if ($oid === 0) return false;
        if (!isset($xref_table[$oid])) return false;

        // Find the object and get where it ends
        $object_offset = $xref_table[$oid];
        $object = self::object_from_string($_buffer, $oid, $object_offset, $offset_end);

        if ($object === false) return false;

        $_stream_pending = false;

        // The distinction is required, because we need to get the proper start for the stream, and if using CRLF instead of LF
        //   - according to https://www.adobe.com/content/dam/acom/en/devnet/pdf/PDF32000_2008.pdf, stream is followed by CRLF 
        //     or LF, but not single CR.
        if (substr($_buffer, $offset_end - 7, 7) === "stream\n") {
            $_stream_pending = $offset_end;
        }

        if (substr($_buffer, $offset_end - 7, 8) === "stream\r\n") {
            $_stream_pending = $offset_end + 1;
        }

        // If it expects a stream, get it
        if ($_stream_pending !== false) {
            $length = $object['Length']->get_int();
            if ($length === false) {
                $length_object_id = $object['Length']->get_object_referenced();
                if ($length_object_id === false) {
                    return p_error("could not get stream for object $obj_id");
                }
                $length_object = self::find_object($_buffer, $xref_table, $length_object_id);
                if ($length_object === false)
                    return p_error("could not get object $obj_id");

                $length = $length_object->get_value()->get_int();
            }

            if ($length === false) {
                return p_error("could not get stream length for object $obj_id");
            }

            $object->set_stream(substr($_buffer, $_stream_pending, $length), true);
        }

        return $object;
    }

    /**
     * Function that finds the objects in the xref table 
     * 
     * NOTE: Because of how the objects are parsed and because of the length of streams can be a reference to an object. e.g. object 3 0 R is an
     *   integer that contains the length of object 2 0 R; as 2 0 R is parsed before, we cannot know the length until 3 0 R has been parsed. So
     *   we took note from those streams pending and now we are adding them to the objects
     */
    protected static function find_objects(&$_buffer, $xref_table) {
        $_objects = [];
        $_stream_pending = [];

        // Having a lot of objects is memory exhausting, and the 128M default memory size for php scripts is too low for big PDF files, so
        //   we are controlling memory, to avoid the script to be aborted
        $max_memory_setting = get_memory_limit();
        $max_memory_for_object = 0;
        $total_memory = 0;
        foreach ($xref_table as $obj_id => &$obj_offset) {
            // Skip object 0
            if ($obj_id === 0) continue;

            // Get the current memory and estimate whether the creation of a new object may pass the memory limit or not
            gc_collect_cycles();
            $start_memory = memory_get_usage();

            if ($start_memory + $max_memory_for_object > $max_memory_setting) {
                return p_error("refusing to continue parsing objects, because of memory limits for the script; please considering increasing the memory limit in setting memory_limit from php.ini; the current value is $max_memory_setting bytes (" . (($max_memory_setting/1024)/1024) . "M)");
            }

            // Parse the object and collect memory information
            $_objects[$obj_id] = self::object_from_string($_buffer, $obj_id, $obj_offset, $offset_end);

            $memory_for_this_object = memory_get_usage() - $start_memory;
            if ($max_memory_for_object < $memory_for_this_object) {
                $max_memory_for_object = $memory_for_this_object;
            }

            // The distinction is required, because we need to get the proper start for the stream, and if using CRLF instead of LF
            //   - according to https://www.adobe.com/content/dam/acom/en/devnet/pdf/PDF32000_2008.pdf, stream is followed by CRLF 
            //     or LF, but not single CR.
            if (substr($_buffer, $offset_end - 7, 7) === "stream\n") {
                $_stream_pending[$obj_id] = $offset_end;
            }

            if (substr($_buffer, $offset_end - 7, 8) === "stream\r\n") {
                $_stream_pending[$obj_id] = $offset_end + 1;
            }
        }

        // Second pass, to add the streams to the pending objects
        foreach ($_stream_pending as $obj_id => &$stream_offset) {
            $object = $_objects[$obj_id];
            $length_field = $object->get_value()['Length'];

            $length = $length_field->get_int();
            if ($length === false) {
                $length_object_id = $length_field->get_object_referenced();
                if ($length_object_id === false) {
                    p_error("could not get stream for object $obj_id");
                    continue;
                }
                $length_object = $_objects[$length_object_id];
                $length = $length_object->get_value()->get_int();
            }

            if ($length === false) {
                p_error("could not get stream length for object $obj_id");
                continue;
            }

            $object->set_stream(substr($_buffer, $stream_offset, $length));

            // TODO: if being strict, now we should check that there is an "endstream" and an "endobj" clauses after the streams; in that place
            //   we could include a payload
        }

        return $_objects;
    }    

    /**
     * Function that parses an object 
     */
    protected static function object_from_string(&$buffer, $expected_obj_id, $offset = 0, &$offset_end = 0) {
        if (preg_match('/^([0-9]+)\s+([0-9+])\s+obj(\s)+/ms', $buffer, $matches, 0, $offset) !== 1) {
            return p_error("object is not valid: $expected_obj_id");
        }

        $found_obj_header = $matches[0];
        $found_obj_id = intval($matches[1]);
        $found_obj_generation = intval($matches[2]);

        if ($expected_obj_id === null)
            $expected_obj_id = $found_obj_id;

        if ($found_obj_id !== $expected_obj_id) {
            return p_error("pdf structure is corrupt: found obj $found_obj_id while searching for obj $expected_obj_id (at $offset)");
        }

        // The object starts after the header
        $offset = $offset + strlen($found_obj_header);

        // Parse the object
        $parser = new PDFObjectParser();

        $stream = new StreamReader($buffer, $offset);

        $obj_parsed = $parser->parse($stream);
        if ($obj_parsed === false)
            return p_error("object $expected_obj_id could not be parsed");

        switch ($parser->current_token()) {
            case PDFObjectParser::T_OBJECT_END:
                // The object has ended correctly
                break;
            case PDFObjectParser::T_STREAM_BEGIN:
                // There is an stream
                break;
            default:
                return p_error("malformed object");
        }

        $offset_end = $stream->getpos();
        return new PDFObject($found_obj_id, $obj_parsed, $found_obj_generation);
    }


    /**
     * Builds the xref for the document, using the list of objects
     * @param offsets an array indexed by the oid of the objects, with the offset of each
     *  object in the document.
     * @return xref_string a string that contains the xref table, ready to be inserted in the document
     */
    protected static function build_xref($offsets) {
        $k = array_keys($offsets);
        sort($k);

        $i_k = 0;
        $c_k = 0;
        $count = 1;
        $result = "";
        $references = "0000000000 65535 f \n";
        for ($i = 0; $i < count($k); $i++) {
            if ($k[$i] === 0) continue;
            if ($k[$i] === $c_k + 1) {
                $count++;
            } else {
                $result = $result . "$i_k ${count}\n$references";
                $count = 1;
                $i_k = $k[$i];
                $references = "";
            }
            $references .= sprintf("%010d 00000 n \n", $offsets[$k[$i]]);
            $c_k = $k[$i];
        }
        $result = $result . "$i_k ${count}\n$references";

        return "xref\n$result";            
    }    
}