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

class PDFValueSimple extends PDFValue
{
    public function __construct($v)
    {
        parent::__construct($v);
    }

    public function push($v): bool
    {
        if ($v::class === static::class) {
            // Can push
            $this->value = $this->value . ' ' . $v->val();

            return true;
        }

        return false;
    }

    public function get_object_referenced(): false|int
    {
        if (! preg_match('/^\s*([0-9]+)\s+([0-9]+)\s+R\s*$/ms', (string) $this->value, $matches)) {
            return false;
        }

        return (int) $matches[1];
    }

    public function get_int(): false|int
    {
        if (! is_numeric($this->value)) {
            return false;
        }

        return (int) $this->value;
    }
}
