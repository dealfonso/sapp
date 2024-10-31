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

class PDFValueList extends PDFValue
{
    public function __construct($value = [])
    {
        parent::__construct($value);
    }

    public function __toString(): string
    {
        return '[' . implode(' ', $this->value) . ']';
    }

    public function diff(object $other): mixed
    {
        $different = parent::diff($other);
        if (($different === false) || ($different === null)) {
            return $different;
        }

        $s1 = $this->__toString();
        $s2 = $other->__toString();

        if ($s1 === $s2) {
            return null;
        }

        return $this;
    }

    /**
     * This function
     */
    public function val($list = false)
    {
        if ($list === true) {
            $result = [];
            foreach ($this->value as $v) {
                if (is_a($v, PDFValueSimple::class)) {
                    $v = explode(' ', (string) $v->val());
                } else {
                    $v = [$v->val()];
                }
                array_push($result, ...$v);
            }

            return $result;
        }
        return parent::val();

    }

    /**
     * This function returns a list of objects that are referenced in the list, only if all of them are references to objects
     */
    public function get_object_referenced(): false|array
    {
        $ids = [];
        $plain_text_val = implode(' ', $this->value);
        if (trim($plain_text_val) !== '') {
            if (preg_match_all('/(([0-9]+)\s+[0-9]+\s+R)[^0-9]*/ms', $plain_text_val, $matches) > 0) {
                $rebuilt = implode(' ', $matches[0]);
                $rebuilt = preg_replace('/\s+/ms', ' ', $rebuilt);
                $plain_text_val = preg_replace('/\s+/ms', ' ', $plain_text_val);
                if ($plain_text_val === $rebuilt) {
                    // Any content is a reference
                    foreach ($matches[2] as $id) {
                        $ids[] = (int) $id;
                    }
                }
            } else {
                return false;
            }
        }

        return $ids;
    }

    /**
     * This method pushes the parameter to the list;
     *  - if it is an array, the list is merged;
     *  - if it is a list object, the lists are merged;
     *  - otherwise the object is converted to a PDFValue* object and it is appended to the list
     */
    public function push(mixed $v): bool
    {
        if (is_object($v) && ($v::class === static::class)) {
            // If a list is pushed to another list, the elements are merged
            $v = $v->val();
        }
        if (! is_array($v)) {
            $v = [$v];
        }
        foreach ($v as $e) {
            $e = self::_convert($e);
            $this->value[] = $e;
        }

        return true;
    }
}
