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

use ddn\sapp\pdfvalue\PDFValueSimple;
use ddn\sapp\pdfvalue\PDFValueString;
use function ddn\sapp\helpers\timestamp_to_pdfdatestring;

// This is an special object that has a set of fields
class PDFSignatureObject extends PDFObject
{
    // The maximum signature length, needed to create a placeholder to calculate the range of bytes
    // that will cover the signature.
    public static $__SIGNATURE_MAX_LENGTH = 27742;

    // The maximum expected length of the byte range, used to create a placeholder while the size
    // is not known. 68 digits enable 20 digits for the size of the document
    public static $__BYTERANGE_SIZE = 68;

    protected int $_prev_content_size;

    protected $_post_content_size = null;

    // A placeholder for the certificate to use to sign the document
    protected $_certificate = null;

    protected $_signature_ltv_data = null;

    protected $_signature_tsa = null;

    /**
     * Constructs the object and sets the default values needed to sign
     *
     * @param oid the oid for the object
     */
    public function __construct(int $oid)
    {
        $this->_prev_content_size = 0;
        $this->_post_content_size = null;
        parent::__construct($oid, [
            'Filter' => '/Adobe.PPKLite',
            'Type' => '/Sig',
            'SubFilter' => '/adbe.pkcs7.detached',
            'ByteRange' => new PDFValueSimple(str_repeat(' ', self::$__BYTERANGE_SIZE)),
            'Contents' => '<' . str_repeat('0', self::$__SIGNATURE_MAX_LENGTH) . '>',
            'M' => new PDFValueString(timestamp_to_pdfdatestring()),
        ]);
    }

    /**
     * Sets the certificate to use to sign
     *
     * @param cert the pem-formatted certificate and private to use to sign as
     *             [ 'cert' => ..., 'pkey' => ... ]
     */
    public function set_certificate($certificate): void
    {
        $this->_certificate = $certificate;
    }

    public function set_signature_ltv($signature_ltv_data): void
    {
        $this->_signature_ltv_data = $signature_ltv_data;
    }

    public function set_signature_tsa($signature_tsa): void
    {
        $this->_signature_tsa = $signature_tsa;
    }

    /**
     * Obtains the certificate set with function set_certificate
     *
     * @return cert the certificate
     */
    public function get_certificate()
    {
        return $this->_certificate;
    }

    public function get_tsa()
    {
        return $this->_signature_tsa;
    }

    public function get_ltv()
    {
        return $this->_signature_ltv_data;
    }

    /**
     * Function used to add some metadata fields to the signature: name, reason of signature, etc.
     *
     * @param name the name of the signer
     * @param reason the reason for the signature
     * @param location the location of signature
     * @param contact the contact info
     */
    public function set_metadata($name = null, $reason = null, $location = null, $contact = null): void
    {
        if ($name !== null) {
            $this->_value['Name'] = new PDFValueString($name);
        }
        if ($reason !== null) {
            $this->_value['Reason'] = new PDFValueString($reason);
        }
        if ($location !== null) {
            $this->_value['Location'] = new PDFValueString($location);
        }
        if ($contact !== null) {
            $this->_value['ContactInfo'] = new PDFValueString($contact);
        }
    }

    /**
     * Function that sets the size of the content that will appear in the file, previous to this object,
     *   and the content that will be included after. This is needed to get the range of bytes of the
     *   signature.
     */
    public function set_sizes(int $prev_content_size, $post_content_size = null): void
    {
        $this->_prev_content_size = $prev_content_size;
        $this->_post_content_size = $post_content_size;
    }

    /**
     * This function gets the offset of the marker, relative to this object. To make correct, the offset of the object
     *   shall have properly been set. It makes use of the parent "to_pdf_entry" function to avoid recursivity.
     *
     * @return position the position of the <0000 marker
     */
    public function get_signature_marker_offset(): int
    {
        $tmp_output = parent::to_pdf_entry();
        $marker = '/Contents';
        $position = strpos($tmp_output, $marker);

        return $position + strlen($marker);
    }

    /**
     * Overrides the parent function to calculate the proper range of bytes, according to the sizes provided and the
     *   string representation of this object
     *
     * @return str the string representation of this object
     */
    public function to_pdf_entry(): string
    {
        $signature_size = strlen(parent::to_pdf_entry());
        $offset = $this->get_signature_marker_offset();
        $starting_second_part = $this->_prev_content_size + $offset + self::$__SIGNATURE_MAX_LENGTH + 2;

        $contents_size = strlen('' . $this->_value['Contents']);

        $byterange_str = '[ 0 ' .
            ($this->_prev_content_size + $offset) . ' ' .
            ($starting_second_part) . ' ' .
            ($this->_post_content_size !== null ? $this->_post_content_size + ($signature_size - $contents_size - $offset) : 0) . ' ]';

        $this->_value['ByteRange'] =
            new PDFValueSimple(
                $byterange_str . str_repeat(' ', self::$__BYTERANGE_SIZE - strlen($byterange_str) + 1)
            );

        return parent::to_pdf_entry();
    }
}
