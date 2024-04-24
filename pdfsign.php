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
echo "<pre>";
use ddn\sapp\PDFDoc;

require_once('vendor/autoload.php');
$pdfs = array();
if($handle = opendir('./test')) {
  while(false !== ($entry = readdir($handle))) {
    if(substr($entry, -4)=='.pdf') {
      $pdfs[] = $entry;
      //echo substr($entry, -4)."\n";
    }
  }
  closedir($handle);
}
$filenum = count($pdfs);
echo "File NUM=$filenum\n";

$file_in = './test/signed_'.$filenum.'.pdf';
$file_content = file_get_contents($file_in);
//$file_content = file_get_contents('examples/testdoc.pdf');
$pfx = "examples/PDF User.chain.pfx";
//$pfx = "examples/PDF User.nochain.pfx";
$issuer = "examples/Root CA Test.crt";
$crl = "examples/RootCATest.der.crl";

echo "Signing file \"$file_in\" ...\n\n";
$obj = PDFDoc::from_string($file_content);
if($obj === false) {
  echo "failed to parse file $file_in\n";
} else {
  //$obj->set_ltv(); // ocsp host, crl addr, issuer
  $obj->set_ltv(false, $crl, $issuer); // ocsp host, crl addr, issuer
  //$obj->set_tsa('http://localhost/phptsa/'); //tsa uri
  $obj->set_tsa('http://timestamp.apple.com/ts01');

  $password = '';
  if(!$obj->set_signature_certificate($pfx, $password)) {
    echo "the certificate is not valid\n";
  } else {
    $docsigned = $obj->to_pdf_file_s();
    if($docsigned === false) {
      echo "could not sign the document\n";
    } else {
      $file_out = './test/signed_'.($filenum+1).'.pdf';
      echo "OK. file \"$file_in\" signed to \"$file_out\"\n";
      $h = fopen($file_out,'w');
      fwrite($h, $docsigned);
      fclose($h);
    }
  }
}
?>