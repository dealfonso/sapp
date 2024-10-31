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

use ddn\sapp\AlmostOriginalLogger;
use ddn\sapp\PDFDoc;
use Psr\Log\AbstractLogger;

require_once 'vendor/autoload.php';

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, sprintf("usage: %s <filename> [<output>]", $argv[0]));
} else {
    if (!file_exists($argv[1])) {
        fwrite(STDERR, "failed to open file " . $argv[1]);
    } else {
        $obj = PDFDoc::from_string(file_get_contents($argv[1]));
        $obj->setLogger(new AlmostOriginalLogger());

        if ($argc == 3) {
            file_put_contents($argv[2], $obj->to_pdf_file_s(true));
        } else {
            echo $obj->to_pdf_file_s(true);
        }
    }
}
