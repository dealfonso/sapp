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

namespace ddn\sapp;

use ddn\sapp\PDFBaseDoc;
use ddn\sapp\PDFBaseObject;
use ddn\sapp\pdfvalue\PDFValueObject;
use ddn\sapp\pdfvalue\PDFValueList;
use ddn\sapp\pdfvalue\PDFValueReference;
use ddn\sapp\pdfvalue\PDFValueType;
use ddn\sapp\pdfvalue\PDFValueSimple;
use ddn\sapp\pdfvalue\PDFValueHexString;
use ddn\sapp\pdfvalue\PDFValueString;
use \Buffer;

require_once(__DIR__ . "/inc/buffer.php");
require_once(__DIR__ . "/inc/mime.php");
require_once(__DIR__ . "/inc/fpdfhelpers.php");

if (!defined('__TMP_FOLDER'))
    define('__TMP_FOLDER', '/tmp');

// The signature mechanism is taken from tcpdf (https://github.com/tecnickcom/TCPDF)

class PDFDoc extends PDFBaseDoc {

    // The PDF version of the parsed file
    protected $_pdf_objects = [];
    protected $_pdf_version_string = null;
    protected $_pdf_trailer_object = null;
    protected $_xref_position = 0;
    protected $_xref_table = [];
    protected $_max_oid = 0;
    protected $_buffer = "";    
    protected $_signature = null;

    // Array of pages ordered by appearance in the final doc (i.e. index 0 is the first page rendered; index 1 is the second page rendered, etc.)
    // Each entry is an array with the following fields:
    //  - id: the id in the document (oid); can be translated into <id> 0 R for references
    //  - info: an array with information about the page
    //      - size: the size of the page
    protected $_pages_info = [];

    /**
     * The function parses a document from a string: analyzes the structure and obtains and object
     *   of type PDFDoc (if possible), or false, if an error happens.
     * @param buffer a string that contains the file to analyze
     */
    public static function from_string($buffer) {
        $structure = self::acquire_structure($buffer);
        if ($structure === false)
            return false;    

        $trailer = $structure["trailer"];
        $version = $structure["version"];
        $xref_table = $structure["xref"];
        $xref_position = $structure["xrefposition"];

        $pdfdoc = new PDFDoc();
        $pdfdoc->_pdf_version_string = $version;
        $pdfdoc->_pdf_trailer_object = $trailer;
        $pdfdoc->_xref_position = $xref_position;
        $pdfdoc->_xref_table = $xref_table;
        $pdfdoc->_buffer = $buffer;

        if ($trailer['Encrypt'] !== false)
            p_error("encrypted documents are not fully supported; maybe you cannot get the expected results");

        $oids = array_keys($xref_table);
        sort($oids);
        $pdfdoc->_max_oid = array_pop($oids);

        $pdfdoc->_acquire_pages_info();

        return $pdfdoc;
    }

    /**
     * This function checks whether the passed object is a reference or not, and in case that
     *   it is a reference, it returns the referenced object; otherwise it return the object itself
     * @param reference the reference value to obtain
     * @return obj it reference can be interpreted as a reference, the referenced object; otherwise, the object itself. 
     *   If the passed value is an array of references, it will return false
     */
    public function get_indirect_object( $reference ) {
        $object_id = $reference->get_object_referenced();
        if ($object_id !== false) {
            if (is_array($object_id))
                return false;
            return $this->get_object($object_id);
        }
        return $reference;
    }

    /**
     * Obtains an object from the document, usign the oid in the PDF document.
     * @param oid the oid of the object that is being retrieved
     * @param original if true and the object has been overwritten in this document, the object
     *                 retrieved will be the original one. Setting to false will retrieve the
     *                 more recent object
     * @return obj the object retrieved (or false if not found)
     */
    public function get_object($oid, $original_version = false) {
        if ($original_version === true) {
            // Prioritizing the original version
            $object = self::find_object($this->_buffer, $this->_xref_table, $oid);
            if ($object === false) 
                $object = $this->_pdf_objects[$oid]??false;

        } else {
            // Prioritizing the new versions
            $object = $this->_pdf_objects[$oid]??false;
            if ($object === false)
                $object = self::find_object($this->_buffer, $this->_xref_table, $oid);
        }

        return $object;
    }
    /**
     * Creates an image object in the document, using the content of "info"
     *   NOTE: the image inclusion is taken from http://www.fpdf.org/; this is a translation
     *         of function _putimage
     */
    protected function _create_image_objects($info) {
        $objects = [];

        $image = new PDFObject($this->get_new_oid(),
            [
                'Type' => '/XObject',
                'Subtype' => '/Image',
                'Width' => $info['w'],
                'Height' => $info['h'],
                'ColorSpace' => [ ],
                'BitsPerComponent' => $info['bpc'],
                'Length' => strlen($info['data'])
            ]            
        );

        switch ($info['cs']) {
            case 'Indexed':
                $image['ColorSpace']->push([
                    '/Indexed', '/DeviceRGB', (strlen($info['pal']) / 3) - 1, new PDFValueReference($mask->get_oid())
                ]);
                $data = gzcompress($info['pal']);
                $streamobject = new PDFObject($this->get_new_oid(), [
                    'Filter' => '/FlatDecode',
                    'Length' => strlen($data),
                ]);
                $streamobject->set_stream($data);
                array_push($objects, $streamobject);
                break;
            case 'DeviceCMYK':
                $image["Decode"] = new PDFValueList([1, 0, 1, 0, 1, 0, 1, 0]);
            default:
                $image['ColorSpace'] = new PDFValueType( $info['cs'] );
                break;
        }

        if (isset($info['f']))
            $image['Filter'] = "/" . $info['f'];

        if(isset($info['dp']))
            $image['DecodeParms'] = PDFValueObject::fromstring($info['dp']);

        if (isset($info['trns']) && is_array($info['trns']))
            $image['Mask'] = new PDFValueList($info['trns']);

        if (isset($info['smask'])) {
            $smaskinfo = [
                'w' => $info['w'], 
                'h' => $info['h'], 
                'cs' => 'DeviceGray', 
                'bpc' => 8, 
                'f' => $info['f'], 
                'dp' => '/Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns '.$info['w'],
                'data' => $info['smask']
            ];

            // In principle, it may return multiple objects
            $smasks = $this->_create_image_objects($smaskinfo);
            foreach ($smasks as $smask)
                array_push($objects, $smask);
            $image['SMask'] = new PDFValueReference($smask->get_oid());
        }

        $image->set_stream($info['data']);
        array_unshift($objects, $image);
        return $objects;
    }

    /**
     * Adds an image to the document, in the specific page
     *   NOTE: the image inclusion is taken from http://www.fpdf.org/; this is an adaptation
     *         and simplification of function Image(); it does not take care about units nor 
     *         page breaks
     * @param pageobj the page object in which the image will appear
     * @param filename the name of the file that contains the image (or the content of the file, with the character '@' prepended)
     * @param x the x position (in pixels) where the image will appear
     * @param y the y position (in pixels) where the image will appear
     * @param w the width of the image
     * @param w the height of the image
     */
    public function add_image($page_obj, $filename, $x=0, $y=0, $w=0, $h=0) {

        if (empty($filename))
            return p_error('invalid image name or stream');

        if ($filename[0] === '@') {
            $filecontent = substr($filename, 1);
        } else {
            $filecontent = @file_get_contents($filename);

            if ($filecontent === false)
                return p_error("failed to get the image");
        }

        $finfo = new \finfo();
        $content_type = $finfo->buffer($filecontent, FILEINFO_MIME_TYPE);

        // $content_type = mime_content_type($filename);
        // if ($content_type === false)
        //    return p_error("failed to read file $filename");

        $ext = mime_to_ext($content_type);

        // TODO: support more image types than jpg
        $add_alpha = false;
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $info = _parsejpg($filecontent);
                break;
            case 'png':
                $add_alpha = true;
                $info = _parsepng($filecontent);
                break;
            default:
                return p_error("unsupported mime type");
        }

        $info['i'] = "Im" . get_random_string(4);

        if ($w === null)
            $w = -96;
        if ($h === null)
            $h = -96;

        if($w<0)
            $w = -$info['w']*72/$w;
        if($h<0)
            $h = -$info['h']*72/$h;
        if($w==0)
            $w = $h*$info['w']/$info['h'];
        if($h==0)
            $h = $w*$info['h']/$info['w'];

        $images_objects = $this->_create_image_objects($info);

        $resources = new PDFObject($this->get_new_oid(),
            [
                'ProcSet' => [ '/PDF', '/Text', '/ImageB', '/ImageC', '/ImageI' ],
                'XObject' => new PDFValueObject ([
                    $info['i'] => $images_objects[0]->get_oid()
                ])
            ]
        );

        if (is_int($page_obj))
            $page_obj = $this->get_page($page_obj);

        if ($page_obj === false)
            return p_error("invalid page");
            
        // Get the resources for the page
        $resources_obj = $this->get_indirect_object($page_obj['Resources']);
        if (!isset($resources_obj['ProcSet']))
            $resources_obj['ProcSet'] = new PDFValueList(['/PDF']);
        $resources_obj['ProcSet']->push(['/ImageB', '/ImageC', '/ImageI']);
        if (!isset($resources_obj['XObject']))
            $resources_obj['XObject'] = new PDFValueObject();
        $resources_obj['XObject'][$info['i']] = new PDFValueReference($images_objects[0]->get_oid());

        // Get the contents for the page
        $contents_obj = $this->get_indirect_object($page_obj['Contents']);

        $data = $contents_obj->get_stream(false);
        if ($data === false)
            return p_error("could not interpret the contents of the page");

        $data .= sprintf("\nq %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",$w,$h,$x,$y,$info['i']);
        $contents_obj->set_stream($data, false);
        $contents_obj["Length"] = strlen($data);

        if ($add_alpha === true) {
            $page_obj['Group'] = new PDFValueObject([
                'Type' => '/Group',
                'S' => '/Transparency',
                'CS' => '/DeviceRGB'
            ]);

            $this->add_object($page_obj);
        }

        foreach ([...$images_objects, $resources_obj, $contents_obj] as $o )
            $this->add_object($o);
    }

    /**
     * This function prepares the document to be signed, using the certificate and password. It creates the annotations
     *   and adds them to the document; then prepares the signature object, ready to be used once the document is dumped
     * 
     *   LIMITATIONS: one document can be signed once at a time; if wanted more signatures, then chain the documents:
     *      $o1->sign_document(...);
     *      $o2 = PDFDoc::fromstring($o1->to_pdf_file_s);
     *      $o2->sign_document(...);
     *      $o2->to_pdf_file_s();
     */
    public function sign_document($certfile, $certpass, $page_to_appear = 0) {
        // Do not allow more than one signature for a specific document; if needed, signatures must be chained
        if ($this->_signature !== null)
            return p_error("the document has already been prepared to be signed");


        // First we read the certificate
        $certfilecontent = file_get_contents($certfile);
        if ($certfilecontent === false)
            return p_error("could not read file $certfile");
        if (openssl_pkcs12_read($certfilecontent, $certificate, $certpass) === false)
            return p_error("could not get the certificates from file $certfile");
        if ((!isset($certificate['cert'])) || (!isset($certificate['pkey'])))
            return p_error("could not get the certificate or the private key from file $certfile");

        // First of all, we are searching for the root object (which should be in the trailer)
        $root = $this->_pdf_trailer_object["Root"];

        if (($root === false) || (($root = $root->get_object_referenced()) === false))
            return p_error("could not find the root object from the trailer");

        $page_obj = $this->get_page($page_to_appear);
        if ($page_obj === false)
            return p_error("invalid page");

        $root_obj = $this->get_object($root);
        if ($root_obj === false)
            return p_error("invalid root object");

        // Prepare the signature object (we need references to it)
        $signature = new PDFSignatureObject($this->get_new_oid());
        $signature->set_certificate($certificate);
        
        // Create the annotation object, annotate the offset and append the object
        $annotation_object = new PDFObject($this->get_new_oid(),
            [
                "Type" => "/Annot",
                "Subtype" => "/Widget",
                "FT" => "/Sig",
                "V" => new PDFValueReference($signature->get_oid()),
                "T" => new PDFValueString('Signature' . get_random_string()),
                "P" => new PDFValueReference($page_obj->get_oid()),
                "Rect" => [ 0, 0, 0, 0 ],
                "F" => 4  // TODO: check this value
            ]
        );      
        
        // Add the annotation to the page
        if (!isset($page_obj["Annots"]))
            $page_obj["Annots"] = new PDFValueList();

        if (!$page_obj["Annots"]->push(new PDFValueReference($annotation_object->get_oid())))
            return p_error("Could not update the page where the signature has to appear");
        
        if (!isset($root_obj["AcroForm"]))
            $root_obj["AcroForm"] = new PDFValueObject();

        // Add the annotation to the interactive form
        $root_obj["AcroForm"]["SigFlags"] = 3;
        if (!isset($root_obj["AcroForm"]['Fields']))
            $root_obj["AcroForm"]['Fields'] = new PDFValueList();

        if (!$root_obj["AcroForm"]['Fields']->push(new PDFValueReference($annotation_object->get_oid()))) {
            return p_error("could not create the signature field");
        }            

        // Store the objects
        $this->add_object($annotation_object);
        $this->add_object($root_obj);
        $this->add_object($page_obj);

        // And store the signature
        $this->_signature = $signature;
        return true;
    }

    /**
     * Function that gets the objects that have been read from the document
     * @return objects an array of objects, indexed by the oid of each object
     */
    public function get_objects() {
        return $this->_pdf_objects;
    }

    /**
     * Function that gets the version of the document. It will have the form
     *   PDF-1.x
     * @return version the PDF version
     */
    public function get_version() {
        return $this->_pdf_version_string;
    }

    /**
     * Function that sets the version for the document. 
     * @param version the version of the PDF document (it shall have the form PDF-1.x)
     * @return correct true if the version had the proper form; false otherwise
     */
    public function set_version($version) {
        if (preg_match("/^PDF-1.\[0-9\]$/", $version) !== 1) {
            return false;
        }
        $this->_pdf_version_string = $version;
        return true;
    }

    /**
     * Adds a pdf object to the document (overwrites the one with the same oid, if existed)
     * @param pdf_object the object to add to the document
     */
    public function add_object(PDFObject $pdf_object) {
        $oid = $pdf_object->get_oid();
        $this->_pdf_objects[$oid] = $pdf_object;

        // Update the maximum oid
        if ($oid > $this->_max_oid)
            $this->_max_oid = $oid;
    }

    /**
     * This function generates all the contents of the file up to the xref entry. This works both in
     *   in-mem docs as incremental docs, because in the former case the buffer will be zeroed.
     * 
     */
    protected function _generate_content_to_xref($rebuild = false) {
        if ($rebuild === true) {
            $result  = new Buffer("%$this->_pdf_version_string\n");
        }  else {
            $result = new Buffer($this->_buffer);
        }

        // Need to calculate the objects offset
        $offsets = [];
        $offsets[0] = 0;

        // The objects
        $offset = $result->size();

        if ($rebuild === true) {
            for ($i = 0; $i <= $this->_max_oid; $i++) {
                if (($object = $this->get_object($i)) ===  false) continue;

                $result->data($object->to_pdf_entry());    
                $offsets[$i] = $offset;
                $offset = $result->size();
            }
        } else {
            foreach ($this->_pdf_objects as $obj_id => $object) {
                $result->data($object->to_pdf_entry());
                $offsets[$obj_id] = $offset;
                $offset = $result->size();
            }
        }

        return [ $result, $offsets ];
    }

    /**
     * This functions outputs the document to a buffer object, ready to be dumped to a file.
     * @return buffer a buffer that contains a pdf dumpable document
     */
    public function to_pdf_file_b($rebuild = false) : Buffer {
        // We made no updates, so return the original doc
        if (($rebuild === false) && (count($this->_pdf_objects) === 0))
            return new Buffer($this->_buffer);

        // Generate the first part of the document
        [ $_doc_to_xref, $_obj_offsets ] = $this->_generate_content_to_xref($rebuild);
        $xref_offset = $_doc_to_xref->size();

        if ($this->_signature !== null) {
            $_obj_offsets[$this->_signature->get_oid()] = $_doc_to_xref->size();
            $xref_offset +=  strlen($this->_signature->to_pdf_entry());
        }

        $xref_content = self::build_xref($_obj_offsets);

        // Update the trailer
        $this->_pdf_trailer_object['Size'] = $this->_max_oid + 1;

        if ($rebuild === false)
            $this->_pdf_trailer_object['Prev'] = $this->_xref_position;

        // Generate new IDs
        $ID1 = md5("" . (new \DateTime())->getTimestamp() . "-" . $this->_xref_position . $xref_content);
        $ID2 = md5("" . (new \DateTime())->getTimestamp() . "-" . $this->_xref_position . $this->_pdf_trailer_object);
        $this->_pdf_trailer_object['ID'] = new PDFValueList(
            [ new PDFValueHexString($ID1), new PDFValueHexString($ID2) ]
        );

        $_doc_from_xref = new Buffer($xref_content);
        $_doc_from_xref->data("trailer\n$this->_pdf_trailer_object");
        $_doc_from_xref->data("\nstartxref\n$xref_offset\n%%EOF\n");

        if ($this->_signature !== null) {
            // In case that the document is signed, calculate the signature

            $this->_signature->set_sizes($_doc_to_xref->size(), $_doc_from_xref->size());
            $this->_signature["Contents"] = new PDFValueSimple("");
            $_signable_document = new Buffer($_doc_to_xref->get_raw() . $this->_signature->to_pdf_entry() . $_doc_from_xref->get_raw());

            // We need to write the content to a temporary folder to use the pkcs7 signature mechanism
            $temp_filename = tempnam(__TMP_FOLDER, 'pdfsign');
            $temp_file = fopen($temp_filename, 'wb');
            fwrite($temp_file, $_signable_document->get_raw());
            fclose($temp_file);

            // Calculate the signature and remove the temporary file
            $certificate = $this->_signature->get_certificate();
            $signature_contents = self::calculate_pkcs7_signature($temp_filename, $certificate['cert'], $certificate['pkey']);
            unlink($temp_filename);

            // Then restore the contents field
            $this->_signature["Contents"] = new PDFValueHexString($signature_contents);

            // Add this object to the content previous to this document xref
            $_doc_to_xref->data($this->_signature->to_pdf_entry());
        }

        return new Buffer($_doc_to_xref->get_raw() . $_doc_from_xref->get_raw());
    }

    /**
     * This functions outputs the document to a string, ready to be written
     * @return buffer a buffer that contains a pdf document
     */
    public function to_pdf_file_s($rebuild = false) {
        $pdf_content = $this->to_pdf_file_b($rebuild);
        return $pdf_content->get_raw();
    }

    /**
     * This function writes the document to a file
     * @param filename the name of the file to be written (it will be overwritten, if exists)
     * @return written true if the file has been correcly written to the file; false otherwise
     */
    public function to_pdf_file($filename, $rebuild = false) {
        $pdf_content = $this->to_pdf_file_b($rebuild);

        $file = fopen($filename, "wb");
        if ($file === false) {
            return p_error("failed to create the file");
        }
        if (fwrite($file, $pdf_content->get_raw()) !== $pdf_content->size()) {
            fclose($file);
            return p_error("failed to write to file");
        }
        fclose($file);
        return true;
    }

    /**
     * Gets the page object which is rendered in position i
     * @param i the number of page (according to the rendering order)
     * @return page the page object
     */
    public function get_page($i) {
        if ($i < 0) return false;
        if ($i >= count($this->_pages_info)) return false;
        return $this->get_object($this->_pages_info[$i]['id']);
    }

    /**
     * Gets the size of the page in the form of a rectangle [ x0 y0 x1 y1 ]
     * @param i the number of page (according to the rendering order)
     * @return box the bounding box of the page
     */
    public function get_page_size($i) {
        if ($i < 0) return false;
        if ($i > count($this->_pages_info)) return false;

        $pageinfo = $this->_pages_info[$i]['info'];
        if (!isset($pageinfo['size'])) 
            return false;

        return $pageinfo['size'];
    }

    /**
     * This function builds the page IDs for object with id oid. If it is a page, it returns the oid; if it is not and it has 
     *   kids and every kid is a page (or a set of pages), it finds the pages.
     * @param oid the object id to inspect
     * @return pages the ordered list of page ids corresponding to object oid, or false if any of the kid objects
     *               is not of type page or pages.
     */
    protected function _get_page_info($oid, $info = []) {
        $object = $this->get_object($oid);
        $page_ids = [];

        switch ($object["Type"]->val()) {
            case "Pages":
                $kids = $object["Kids"];
                $kids = $kids->get_object_referenced();
                if ($kids !== false) {
                    if (isset($object['MediaBox'])) {
                        $info['size'] = $object['MediaBox']->val();
                    }
                    foreach ($kids as $kid) {
                        $ids = $this->_get_page_info($kid, $info);
                        if ($ids === false)
                            return false;
                        array_push($page_ids, ...$ids);
                    }
                } else {
                    return p_error("could not get the pages");
                }
                break;
            case "Page":
                if (isset($object['MediaBox']))
                    $info['size'] = $object['MediaBox']->val();
                return [ [ 'id' => $oid, 'info' => $info ]  ];
            default:
                return false;
        }
        return $page_ids;
    }

    /**
     * Obtains an ordered list of objects that contain the ids of the page objects of the document.
     *   The order is made according to the catalog and the document structure.
     * @return list an ordered list of the id of the page objects, or false if could not be found
     */
    protected function _acquire_pages_info() {
        $root = $this->_pdf_trailer_object["Root"];

        if (($root === false) || (($root = $root->get_object_referenced()) === false))
            return p_error("could not find the root object from the trailer");

        $root = $this->get_object($root);
        $pages = $root["Pages"];
        if (($pages === false) || (($pages = $pages->get_object_referenced()) === false))
            return p_error("could not find the pages for the document");
        
        $this->_pages_info = $this->_get_page_info($pages);
    }    
}