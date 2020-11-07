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

use ddn\sapp\pdfvalue\PDFValueObject;
use \ArrayAccess;

/**
 * Class to gather the information of a PDF object: the OID, the definition and the stream. The purpose is to 
 *   ease the generation of the PDF entries for an individual object.
 */
class PDFObject implements ArrayAccess {
    protected $_oid = null;
    protected $_stream = null;
    protected $_value = null;
    
    public function __construct($oid, $value = null, $generation = 0) {
        if ($generation !== 0)
            throw new Exception('sorry but non-zero generation objects are not supported');

        // If the value is null, we suppose that we are creating an empty object
        if ($value === null)
            $value = new PDFValueObject();

        // Ease the creation of the object
        if (is_array($value)) {
            $obj = new PDFValueObject();
            foreach ($value as $field => $v) {
                $obj[$field] = $v;
            }
            $value = $obj;
        }

        $this->_oid = $oid;
        $this->_value = $value;
    }

    public function __toString() {
        return  "$this->_oid 0 obj\n" .
            "$this->_value\n" .
            ($this->_stream === null?"":
                "stream\n" .
                '...' . 
                "\nendstream\n"
            ) .
            "endobj\n";
    }
    /**
     * Converts the object to a well-formed PDF entry with a form like
     *  1 0 obj
     *  ...
     *  stream
     *  ...
     *  endstream
     *  endobj
     * @return pdfentry a string that contains the PDF entry
     */
    public function to_pdf_entry() {
        return  "$this->_oid 0 obj\n" .
                "$this->_value\n" .
                ($this->_stream === null?"":
                    "stream\n" .
                    $this->_stream . 
                    "\nendstream\n"
                ) .
                "endobj\n";
    }
    /**
     * Gets the object ID
     * @return oid the object id
     */
    public function get_oid() {
        return $this->_oid;
    }
    /**
     * Gets the definition of the object (a PDFValue object)
     * @return value the definition of the object
     */
    public function get_value() {
        return $this->_value;
    }
    /**
     * Gets the stream of the object
     * @return stream a string that contains the stream of the object
     */
    public function get_stream($raw = true) {
        if ($raw === true)
            return $this->_stream;
        if (isset($this->_value['Filter'])) {
            switch ($this->_value['Filter']) {
                case '/FlateDecode':
                    return gzuncompress($this->_stream);
                    break;
                default:
                    return p_error('unknown compression method ' . $this->_value['Filter']);
            }
        }
        return $this->_stream;
    }
    /**
     * Sets the stream for the object (overwrites a previous existing stream)
     * @param stream the stream for the object
     */
    public function set_stream($stream, $raw = true) {
        if ($raw === true) {
            $this->_stream = $stream;
            return;
        }
        if (isset($this->_value['Filter'])) {
            switch ($this->_value['Filter']) {
                case '/FlateDecode':
                    $this->_stream = gzcompress($stream);
                    return;
                    break;
                default:
                    p_error('unknown compression method ' . $this->_value['Filter']);
            }
        }
        $this->_value['Length'] = strlen($stream);
        $this->_stream = $stream;
    }    
    /**
     * The next functions enble to make use of this object in an array-like manner,
     *  using the name of the fields as positions in the array. It is useful is the
     *  value is of type PDFValueObject or PDFValueList, using indexes
     */

    /** 
     * Sets the value of the field offset, using notation $obj['field'] = $value
     * @param field the field to set the value
     * @param value the value to set
     * @return value the value, to chain operations
     */
    public function offsetSet($field, $value) {
        return $this->_value[$field] = $value;
    }
    /**
     * Checks whether the field exists in the object or not (or if the index exists
     *   in the list)
     * @param field the field to check wether exists or not
     * @return exists true if the field exists; false otherwise
     */
    public function offsetExists ( $field ) {
        return $this->_value->offsetExists($field);
    }
    /**
     * Gets the value of the field (or the value at position)
     * @param field the field to get the value
     * @return value the value of the field
     */
    public function offsetGet ( $field ) {
        return $this->_value[$field];
    }
    /**
     * Unsets the value of the field (or the value at position)
     * @param field the field to unset the value
     */
    public function offsetUnset($field ) {
        $this->_value->offsetUnset($field);
    }    
}