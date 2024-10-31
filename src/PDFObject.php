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

use ArrayAccess;
use ddn\sapp\helpers\Buffer;
use ddn\sapp\pdfvalue\PDFValue;
use ddn\sapp\pdfvalue\PDFValueObject;
use ddn\sapp\pdfvalue\PDFValueSimple;
use ReturnTypeWillChange;
use Stringable;

// Loading the functions

// The character used to end lines
if (! defined('__EOL')) {
    define('__EOL', "\n");
}

/**
 * Class to gather the information of a PDF object: the OID, the definition and the stream. The purpose is to
 *   ease the generation of the PDF entries for an individual object.
 */
class PDFObject implements ArrayAccess, Stringable
{
    protected static $_revisions;

    protected static $_xref_table_version;

    protected $_stream;

    protected $_value;

    public function __construct(
        protected int $_oid,
        PDFValue|array|null $value = null,
        protected int $_generation = 0,
    ) {
        // If the value is null, we suppose that we are creating an empty object
        if ($value === null) {
            $value = new PDFValueObject();
        }

        // Ease the creation of the object
        if (is_array($value)) {
            $obj = new PDFValueObject();
            foreach ($value as $field => $v) {
                $obj[$field] = $v;
            }

            $value = $obj;
        }

        $this->_value = $value;
    }

    public function __toString(): string
    {
        return $this->_oid . ' 0 obj
' .
            $this->_value . PHP_EOL .
            (
                $this->_stream === null ? '' :
                "stream\n" .
                '...' .
                "\nendstream\n"
            ) .
            "endobj\n";
    }

    public function get_keys()
    {
        return $this->_value->get_keys();
    }

    public function set_oid(int $oid): void
    {
        $this->_oid = $oid;
    }

    public function get_generation(): int
    {
        return $this->_generation;
    }

    /**
     * Converts the object to a well-formed PDF entry with a form like
     *  1 0 obj
     *  ...
     *  stream
     *  ...
     *  endstream
     *  endobj
     *
     * @return pdfentry a string that contains the PDF entry
     */
    public function to_pdf_entry(): string
    {
        return $this->_oid . ' 0 obj' . __EOL .
            $this->_value . __EOL .
            (
                $this->_stream === null ? '' :
                "stream\r\n" .
                $this->_stream .
                __EOL . 'endstream' . __EOL
            ) .
            'endobj' . __EOL;
    }

    /**
     * Gets the object ID
     *
     * @return oid the object id
     */
    public function get_oid(): int
    {
        return $this->_oid;
    }

    /**
     * Gets the definition of the object (a PDFValue object)
     *
     * @return value the definition of the object
     */
    public function get_value()
    {
        return $this->_value;
    }

    /**
     * Gets the stream of the object
     *
     * @return stream a string that contains the stream of the object
     */
    public function get_stream(bool $raw = true): Buffer|false
    {
        if ($raw) {
            return $this->_stream;
        }

        if (isset($this->_value['Filter'])) {
            switch ($this->_value['Filter']) {
                case '/FlateDecode':
                    $DecodeParams = $this->_value['DecodeParms'] ?? [];
                    $params = [
                        'Columns' => $DecodeParams['Columns'] ?? new PDFValueSimple(0),
                        'Predictor' => $DecodeParams['Predictor'] ?? new PDFValueSimple(1),
                        'BitsPerComponent' => $DecodeParams['BitsPerComponent'] ?? new PDFValueSimple(8),
                        'Colors' => $DecodeParams['Colors'] ?? new PDFValueSimple(1),
                    ];

                    return self::FlateDecode(gzuncompress($this->_stream), $params);
                default:
                    throw new PDFException('unknown compression method ' . $this->_value['Filter']);
            }
        }

        return $this->_stream;
    }

    /**
     * Sets the stream for the object (overwrites a previous existing stream)
     *
     * @param stream the stream for the object
     */
    public function set_stream($stream, bool $raw = true): void
    {
        if ($raw) {
            $this->_stream = $stream;

            return;
        }

        if (isset($this->_value['Filter'])) {
            $stream = match ($this->_value['Filter']) {
                '/FlateDecode' => gzcompress((string) $stream),
                default => throw new PDFException('unknown compression method ' . $this->_value['Filter']),
            };
        }

        $this->_value['Length'] = strlen((string) $stream);
        $this->_stream = $stream;
    }

    /**
     * The next functions enble to make use of this object in an array-like manner,
     *  using the name of the fields as positions in the array. It is useful is the
     *  value is of type PDFValueObject or PDFValueList, using indexes
     */
    /**
     * Sets the value of the field offset, using notation $obj['field'] = $value
     *
     * @param field the field to set the value
     * @param value the value to set
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->_value[$offset] = $value;
    }

    /**
     * Checks whether the field exists in the object or not (or if the index exists
     *   in the list)
     *
     * @param field the field to check wether exists or not
     *
     * @return exists true if the field exists; false otherwise
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->_value->offsetExists($offset);
    }

    /**
     * Gets the value of the field (or the value at position)
     *
     * @param field the field to get the value
     *
     * @return value the value of the field
     */
    #[ReturnTypeWillChange]
    public function offsetGet(mixed $offset)
    {
        return $this->_value[$offset];
    }

    /**
     * Unsets the value of the field (or the value at position)
     *
     * @param field the field to unset the value
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->_value->offsetUnset($offset);
    }

    public function push(mixed $v): bool
    {
        return $this->_value->push($v);
    }

    protected static function FlateDecode($_stream, array $params): Buffer|string|null
    {
        switch ($params['Predictor']->get_int()) {
            case 1:
                return $_stream;
            case 10:
            case 11:
            case 12:
            case 13:
            case 14:
            case 15:
                break;
            default:
                throw new PDFException('other predictor than PNG is not supported in this version');
        }

        switch ($params['Colors']->get_int()) {
            case 1:
                break;
            default:
                throw new PDFException('other color count than 1 is not supported in this version');
        }

        switch ($params['BitsPerComponent']->get_int()) {
            case 8:
                break;
            default:
                throw new PDFException('other bit count than 8 is not supported in this version');
        }

        $decoded = new Buffer();
        $columns = $params['Columns']->get_int();

        $stream_len = strlen((string) $_stream);

        // The previous row is zero
        $data_prev = str_pad('', $columns, chr(0));
        $pos_i = 0;
        while ($pos_i < $stream_len) {
            $filter_byte = ord($_stream[$pos_i++]);

            // Get the current row
            $data = substr((string) $_stream, $pos_i, $columns);
            $pos_i += strlen($data);

            // Zero pad, in case that the content is not paired
            $data = str_pad($data, $columns, chr(0));

            // Depending on the filter byte of the row, we should unpack on one way or another
            switch ($filter_byte) {
                case 0:
                    break;
                case 1:
                    for ($i = 1; $i < $columns; $i++) {
                        $data[$i] = ($data[$i] + $data[$i - 1]) % 256;
                    }

                    break;
                case 2:
                    for ($i = 0; $i < $columns; $i++) {
                        $data[$i] = chr((ord($data[$i]) + ord($data_prev[$i])) % 256);
                    }

                    break;
                default:
                    throw new PDFException('Unsupported stream');
            }

            // Store and prepare the previous row
            $decoded->data($data);
            $data_prev = $data;
        }

        // p_debug_var($decoded->show_bytes($columns));
        return $decoded->get_raw();
    }
}
