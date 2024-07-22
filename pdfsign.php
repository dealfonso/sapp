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

require_once('vendor/autoload.php');

if ($argc !== 3)
    fwrite(STDERR, sprintf("usage: %s <filename> <certfile>", $argv[0]));
else {
    if (!file_exists($argv[1]))
        fwrite(STDERR, "failed to open file " . $argv[1]);
    else {
        // Silently prompt for the password
        fwrite(STDERR, "Password: ");
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        fwrite(STDERR, "\n");

        $file_content = file_get_contents($argv[1]);
        $obj = PDFDoc::from_string($file_content);

        if ($obj === false)
            fwrite(STDERR, "failed to parse file " . $argv[1]);
        else {
            if (!$obj->set_signature_certificate($argv[2], $password)) {
                fwrite(STDERR, "the certificate is not valid");
            } else {
                $docsigned = $obj->to_pdf_file_s();
                if ($docsigned === false)
                    fwrite(STDERR, "could not sign the document");
                else
                    echo $docsigned;
            }
        }
    }
}
