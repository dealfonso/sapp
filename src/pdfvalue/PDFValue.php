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

namespace ddn\sapp\pdfvalue;

use ArrayAccess;
use ReturnTypeWillChange;
use Stringable;

class PDFValue implements ArrayAccess, Stringable
{
    public function __construct(
        protected $value,
    ) {
    }

    public function __toString(): string
    {
        return '' . $this->value;
    }

    public function val()
    {
        return $this->value;
    }

    public function offsetExists($offset): bool
    {
        if (! is_array($this->value)) {
            return false;
        }

        return isset($this->value[$offset]);
    }

    #[ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        if (! is_array($this->value)) {
            return false;
        }
        if (! isset($this->value[$offset])) {
            return false;
        }

        return $this->value[$offset];
    }

    public function offsetSet($offset, $value): void
    {
        if (! is_array($this->value)) {
            return;
        }
        $this->value[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        if ((! is_array($this->value)) || (! isset($this->value[$offset]))) {
            throw new Exception('invalid offset');
        }
        unset($this->value[$offset]);
    }

    public function push($v): bool
    {
        /*if (get_class($v) !== get_class($this))
            throw new Exception('invalid object to concat to this one');*/
        return false;
    }

    public function get_int(): false|int
    {
        return false;
    }

    public function get_object_referenced(): false|array|int
    {
        return false;
    }

    public function get_keys(): false|array
    {
        return false;
    }

    /**
     * Returns the difference between this and other object (false means "cannot compare", null means "equal" and any value means "different": things in this object that are different from the other)
     */
    public function diff($other)
    {
        if (! is_a($other, static::class)) {
            return false;
        }
        if ($this->value === $other->value) {
            return null;
        }

        return $this->value;
    }

    /**
     * Function that converts standard types into PDFValue* types
     *  - integer, double are translated into PDFValueSimple
     *  - string beginning with /, is translated into PDFValueType
     *  - string without separator (e.g. "\t\n ") are translated into PDFValueSimple
     *  - other strings are translated into PDFValueString
     *  - array is translated into PDFValueList, and its inner elements are also converted.
     *
     * @param value a standard php object (e.g. string, integer, double, array, etc.)
     *
     * @return pdfvalue an object of type PDFValue*, depending on the
     */
    protected static function _convert($value)
    {
        switch (gettype($value)) {
            case 'integer':
            case 'double':
                $value = new PDFValueSimple($value);
                break;
            case 'string':
                if ($value[0] === '/') {
                    $value = new PDFValueType(substr($value, 1));
                } else {
                    if (preg_match("/\s/ms", $value) === 1) {
                        $value = new PDFValueString($value);
                    } else {
                        $value = new PDFValueSimple($value);
                    }
                }
                break;
            case 'array':
                if (count($value) === 0) {
                    // An empty list is assumed to be a list
                    $value = new PDFValueList();
                } else {
                    // Try to parse it as an object (i.e. [ 'Field' => 'Value', ...])
                    $obj = PDFValueObject::fromarray($value);
                    if ($obj !== false) {
                        $value = $obj;
                    } else {
                        // If not an object, it is a list
                        $list = [];
                        foreach ($value as $v) {
                            array_push($list, self::_convert($v));
                        }
                        $value = new PDFValueList($list);
                    }
                }
                break;
        }

        return $value;
    }
}
