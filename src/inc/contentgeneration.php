<?php

use ddn\sapp\PDFBaseDoc;
use ddn\sapp\PDFBaseObject;
use ddn\sapp\pdfvalue\PDFValueObject;
use ddn\sapp\pdfvalue\PDFValueList;
use ddn\sapp\pdfvalue\PDFValueReference;
use ddn\sapp\pdfvalue\PDFValueType;
use ddn\sapp\pdfvalue\PDFValueSimple;
use ddn\sapp\pdfvalue\PDFValueHexString;
use ddn\sapp\pdfvalue\PDFValueString;

/**
 * Creates an image object in the document, using the content of "info"
 *   NOTE: the image inclusion is taken from http://www.fpdf.org/; this is a translation
 *         of function _putimage
 */
function _create_image_objects($info, $object_factory) { 
    $objects = [];

    $image = call_user_func($object_factory,
        [
            'Type' => '/XObject',
            'Subtype' => '/Image',
            'Width' => $info['w'],
            'Height' => $info['h'],
            'ColorSpace' => [ ],
            'BitsPerComponent' => $info['bpc'],
            'Length' => strlen($info['data']),
        ]            
    );

    switch ($info['cs']) {
        case 'Indexed':
            $data = gzcompress($info['pal']);
            $streamobject = call_user_func($object_factory, [
                'Filter' => '/FlateDecode',
                'Length' => strlen($data),
            ]);
            $streamobject->set_stream($data);

            $image['ColorSpace']->push([
                '/Indexed', '/DeviceRGB', (strlen($info['pal']) / 3) - 1, new PDFValueReference($streamobject->get_oid())
            ]);
            array_push($objects, $streamobject);
            break;
        case 'DeviceCMYK':
            $image["Decode"] = new PDFValueList([1, 0, 1, 0, 1, 0, 1, 0]);
        default:
            $image['ColorSpace'] = new PDFValueType( $info['cs'] );
            break;
    }

    if (isset($info['f']))
        $image['Filter'] = new PDFValueType($info['f']);

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
        $smasks = _create_image_objects($smaskinfo, $object_factory);
        foreach ($smasks as $smask)
            array_push($objects, $smask);
        $image['SMask'] = new PDFValueReference($smask->get_oid());
    }

    $image->set_stream($info['data']);
    array_unshift($objects, $image);

    return $objects;
}

/**
 * This function creates the objects needed to add an image to the document, at a specific position and size.
 *   The function is agnostic from the place in which the image is to be created, and just creates the objects
 *   with its contents and prepares the PDF command to place the image
 * @param filename the file name that contains the image, or a string that contains the image (with character '@'
 *                 prepended)
 * @param x points from left in which to appear the image (the units are "content-defined" (i.e. depending on the size of the page))
 * @param y points from bottom in which to appear the image (the units are "content-defined" (i.e. depending on the size of the page))
 * @param w width of the rectangle in which to appear the image (image will be scaled, and the units are "content-defined" (i.e. depending on the size of the page))
 * @param h height of the rectangle in which to appear the image (image will be scaled, and the units are "content-defined" (i.e. depending on the size of the page))
 * @return result an array with the next fields:
 *                  "images": objects of the corresponding images (i.e. position [0] is the image, the rest elements are masks, if needed)
 *                  "resources": PDFValueObject with keys that needs to be incorporated to the resources of the object in which the images will appear
 *                  "alpha": true if the image has alpha
 *                  "command": pdf command to draw the image
 * 
 * TODO: this function could be static, if function "get_new_oid" is public; maybe we could create a public function "new_object" in the document
 */
function _add_image($object_factory, $filename, $x=0, $y=0, $w=0, $h=0) {

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

    // Generate a new identifier for the image
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

    $images_objects = _create_image_objects($info, $object_factory);

    // Generate the command to translate and scale the image
    $data = sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q", $w, $h, $x, $y, $info['i']);

    $resources = new PDFValueObject( [
        'ProcSet' => [ '/PDF', '/Text', '/ImageB', '/ImageC', '/ImageI' ],
        'XObject' => new PDFValueObject ([
            $info['i'] => new PDFValueReference($images_objects[0]->get_oid()),                        
        ])
    ]);

    return [ "image" => $images_objects[0], 'command' => $data, 'resources' => $resources, 'alpha' => $add_alpha ];
}
