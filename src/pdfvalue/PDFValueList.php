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

namespace ddn\sapp\pdfvalue;

class PDFValueList extends PDFValue {
    public function __construct($value = []) {
        parent::__construct($value);
    }
    public function __toString() {
        return '[' . implode(' ', $this->value) . ']';
    }
    public function val($recurse = false) {
        if ($recurse === true) {
            $result = [];
            foreach ($this->value as $v) {
                array_push($result, $v->val());
            }
            return $result;
        } else
            return parent::val();
    }
    public function get_object_referenced() {
        $ids = [];
        $plain_text_val = implode(' ', $this->value);
        if (trim($plain_text_val) !== "") {
            if (preg_match_all('/(([0-9]+)\s+[0-9]+\s+R)[^0-9]*/ms', $plain_text_val, $matches) > 0) {
                $rebuilt = implode(" ", $matches[0]);
                $rebuilt = preg_replace('/\s+/ms', ' ', $rebuilt);
                $plain_text_val = preg_replace('/\s+/ms', ' ', $plain_text_val);
                if ($plain_text_val === $rebuilt) {
                    // Any content is a reference
                    foreach ($matches[2] as $id)
                        array_push($ids, intval($id));
                } 
            } else
                return false;
        }
        return $ids;

        $COMMENT_OUT = function() {
            foreach ($this->value as $value) {
                $plain_text_val = "" . $value;
                if (trim($plain_text_val) === "") continue;
                if (preg_match_all('/(([0-9]+)\s+[0-9]+\s+R)[^0-9]*/ms', $plain_text_val, $matches) > 0) {
                    $rebuilt = implode(" ", $matches[0]);
                    $rebuilt = preg_replace('/\s+/ms', ' ', $rebuilt);
                    $plain_text_val = preg_replace('/\s+/ms', ' ', $plain_text_val);
                    if ($plain_text_val === $rebuilt) {
                        // Any content is a reference
                        foreach ($matches[2] as $id)
                            array_push($ids, intval($id));
                    } 
                } else
                    return false;
            }
            return $ids;
        };
    }
    public function push($v) {
        if (is_object($v) && (get_class($v) === get_class($this))) {
            // If a list is pushed to another list, the elements are merged
            $v = $v->val();
        }
        if (!is_array($v)) $v = [ $v ];
        foreach ($v as $e) {
            $e = self::_convert($e);
            array_push($this->value, $e);
        }
        return true;
    }
}
