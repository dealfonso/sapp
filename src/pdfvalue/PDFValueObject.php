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

class PDFValueObject extends PDFValue {
    public function __construct($value = []) {
        $result = [];
        foreach ($value as $k => $v) {
            $result[$k] = self::_convert($v);
        }
        parent::__construct($result);
    }

    public static function fromarray($parts) {
        $k = array_keys($parts);
        $intkeys = false;
        $result = [];
        foreach ($k as $ck)
            if (is_int($ck)) {
                $intkeys = true;
                break;
            }
        if ($intkeys) return false;
        foreach ($parts as $k => $v) {
            $result[$k] = self::_convert($v);
        }
        return new PDFValueObject($result);
    }

    public static function fromstring($str) {
        $result = [];
        $field = null;
        $value = null;
        $parts = explode(' ', $str);
        for ($i = 0; $i < count($parts); $i++) {
            if ($field === null) {
                $field = $parts[$i];
                if ($field === '') return false;
                if ($field[0] !== '/') return false;
                $field = substr($field, 1);
                if ($field === '') return false;
                continue;
            }
            $value = $parts[$i];
            $result[$field] = $value;
            $field = null;
        }
        // If there is no pair of values, there is no valid
        if ($field !== null) return false;
        return new PDFValueObject($result);
    }
    /**
     * Function used to enable using [x] to set values to the fields of the object (from ArrayAccess interface)
     *  i.e. object[offset]=value
     * @param offset the index used inside the braces
     * @param value the value to set to that index (it will be converted to a PDFValue* object)
     * @return value the value set to the field
     */
    public function offsetSet($offset , $value) {
        if ($value === null) {
            if (isset($this->value[$offset]))
                unset($this->value[$offset]);
            return null;
        }
        $this->value[$offset] = self::_convert($value);
        return $this->value[$offset];
    }
    public function offsetExists ( $offset ) {
        return isset($this->value[$offset]);
    }

    /**
     * Function to output the object using the PDF format, and trying to make it compact (by reducing spaces, depending on the values)
     * @return pdfentry the PDF entry for the object
     */
    public function __toString() {
        $result = [];
        foreach ($this->value as $k => $v) {
            $v = "" . $v;
            if ($v === "") {
                array_push($result, "/$k");
                continue;
            }
            switch ($v[0]) {
                case '/':
                case '[':
                case '(':
                case '<':
                    array_push($result, "/$k$v");
                    break;
                default:
                    array_push($result, "/$k $v");
            }
        }
        return "<<" . implode('', $result) . ">>";
    }
}