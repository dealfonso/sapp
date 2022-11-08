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

trait PDFTestCaseUtils {
    private function rebuildPdf(string $content){
        $real_content = \ctype_print($content) && @\file_exists($content)
            ? \file_get_contents(realpath($content)) : $content;

        $pdf = PDFDoc::from_string($real_content);

        if ($pdf === false) {
            return null;
        }

        return PDFDoc::from_string($pdf->to_pdf_file_s(true));
    }

    public function assertPdfAreEquals (string $expected, string $actual) : void {
        $expected_doc = $this->rebuildPdf($expected);
        self::assertIsObject($expected_doc, 'The expected file can\'t be parsed as PDF.');

        $actual_doc = $this->rebuildPdf($actual);
        self::assertIsObject($actual_doc, 'The actual file can\'t be parsed as PDF.');

        $differences = $expected_doc->compare($actual_doc);

        $keys = [];
        foreach ($differences as $oid => $obj) {
            $keys = array_merge($keys, \array_diff($obj->get_keys() ?: ['OID_' . $obj->get_oid()], ['CreationDate']));
        }

        self::assertEquals([], $keys, 'The PDFs contents have differences.');
    }
}
