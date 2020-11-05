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

use ddn\sapp\PDFDoc;
use \Buffer;

require_once(__DIR__ . "/inc/buffer.php");

/**
 * This function sets the whole document into memory; it is quicker, but uses a lot of memory to store the objects;
 *   class PDFDoc does not store the objects, and obtains them dynamically, as needed (but it needs to parse the)
 *   document each time an object is needed
 */
class PDFMemDoc extends PDFDoc {
    public static function from_string(&$buffer) {
        parent::from_string($buffer);

        // We do not need to store the buffer anymore
        $this->_buffer = "";

        $pdf_objects = self::find_objects($buffer, $xref_table);
        if ($pdf_objects === false)
            return false;

        $this->_pdf_objects = $pdf_objects;
    }

    public function get_object($oid) {
        if (!isset($this->_pdf_objects[$oid]))
            return false;
        return $this->_pdf_objects[$oid];
    }

    /**
     * This functions outputs the document to a buffer object, ready to be dumped
     *  to a file.
     * @return buffer a buffer that contains a pdf dumpable document
     */
    public function to_pdf_file_b() : Buffer {
        // The document starts with the header
        $result = new Buffer("%$this->_pdf_version_string\n");

        $offsets = [];
        $offsets[0] = 0;

        // The objects
        $offset = $result->size();
        foreach ($this->_pdf_objects as $obj_id => $object) {
            $result->data($object->to_pdf_entry());
            $offsets[$obj_id] = $offset;
            $offset = $result->size();
        }

        // xref table
        $xref_offset = $offset;
        $result->data(self::build_xref($offsets));

        // Trailer object
        $this->_pdf_trailer_object['Size'] = $this->_max_oid + 1;
        $result->data("trailer\n$this->_pdf_trailer_object");

        // The end
        $result->data("\nstartxref\n$xref_offset\n%%EOF\n");

        // Finished
        return $result;
    }
}
