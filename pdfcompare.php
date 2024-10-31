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

use ddn\sapp\PDFDoc;
use function ddn\sapp\helpers\p_debug_var;
use function ddn\sapp\helpers\p_debug;
use ddn\sapp\pdfvalue\PDFValueObject;
use function ddn\sapp\helpers\p_error;

require_once('vendor/autoload.php');

if ($argc !== 3)
    fwrite(STDERR, sprintf("usage: %s <filename> <rev>", $argv[0]));
else {
    if (!file_exists($argv[1])) {
        fwrite(STDERR, "failed to open file " . $argv[1]);
        die();
    }
    if (!file_exists($argv[2])) {
        fwrite(STDERR, "failed to open file " . $argv[2]);
        die();
    }

    $doc1 = PDFDoc::from_string(file_get_contents($argv[1]));
    if ($doc1 === false)
        fwrite(STDERR, "failed to parse file " . $argv[1]);

    $doc2 = PDFDoc::from_string(file_get_contents($argv[2]));
    if ($doc2 === false)
        fwrite(STDERR, "failed to parse file " . $argv[2]);

    $differences = $doc1->compare($doc2);
    foreach ($differences as $oid => $obj) {
        p_error(get_debug_type($oid));
        print($obj->to_pdf_entry());
    }
}
