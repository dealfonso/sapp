# SAPP - Simple and Agnostic PDF Document Parser

SAPP stands for Simple and Agnostic PDF Parser and it makes what is name says: parsing PDF files. It also enables other cool features such as rebuilding documents (to make the content more clear or compact) or digitally signing documents.

SAPP is agnostic because it does not care of composing PDF documents (e.g. adding pages, updating pages, etc.). Instead, its aim is to be somehow a backend to parse an existing PDF document and to manipulate the objects included on it, or to create new ones.

The way of working with SAPP can be seen in the function to sign the document: it is an independent function that adds and manipulates the PDF objects contained in the document.

**Some of the features of SAPP:**
1. Supports 1.4 PDF documents
1. Supports many features of 1.5 PDF and later documents (including cross-reference streams)
1. Works using incremental versions
1. Works for rebuilding documents to flatten versions (older version are dropped)
1. Signature of documents using the Acrobat workflow (and obtain the green checkmark).
1. Others.

## 1. Why SAPP
I created SAPP because I wanted to programmatically sign documents, including **multiple signatures**.

I tried [tcpdf](https://tcpdf.org/) along with [FPDI](https://www.setasign.com/products/fpdi/downloads/), but the results are not those expected. When importing a document using FPDI, the result is a new document with the pages of the original one, not the original document. So if the original document was signed, those signatures were lost.

I read about [SetaPDF-Signer](https://www.setasign.com/products/setapdf-signer/details/), but it cannot be used for free. I also inspected some CLI tools such as [PortableSigner](https://github.com/pflaeging/PortableSigner2), but (1) it was Java based (and I really don't like java) and (2) it depends on iText and other libraries which don't seem to be free at this moment.

At the end I got the [PDF 1.7 definition](https://www.adobe.com/content/dam/acom/en/devnet/pdf/pdfs/PDF32000_2008.pdf), and I learned about incremental PDF documents and its utility to include multiple signature in documents.

## 2. Using SAPP

### 2.1 Using composer and packagist

To use SAPP in your projects, you just need to use [composer](https://getcomposer.org/):

```bash
$ composer require ddn/sapp:dev-main
```

Then, a `vendor` folder will be created, and then you can simply include the main file and use the classes:

```php
use ddn\sapp\PDFDoc;

require_once('vendor/autoload.php');

$obj = PDFDoc::from_string(file_get_contents("/path/to/my/pdf/file.pdf"));
echo $obj->to_pdf_file_s(true);
```

### 2.2 Getting the source code
Altenatively you can clone the repository and use [composer](https://getcomposer.org/):

```bash
$ git clone https://github.com/dealfonso/sapp
$ cd sapp
$ composer dump-autoload
$ php pdfrebuild.php examples/testdoc.pdf > testdoc-rebuilt.pdf
```

Then you will be ready to include the main file and use the classes.

## 3. Examples

In the root folder of the source code you can find two simple examples: 

1. `pdfrebuild.php`: this example gets a PDF file, loads it and rebuilds it to make every PDF object to be in order, and also reducing the amount of text to define the document. 
1. `pdfsign.php`: this example gets a PDF file and digitally signs it using a pkcs12 (pfx) certificate.
1. `pdfsigni.php`: this example gets a PDF file and digitally signs it using a pkcs12 (pfx) certificate, and adds an image that makes visible the signature in the document.

### 3.1. Rebuild PDF files with `pdfrebuild.php`

Once cloned the repository and generated the autoload content, it is possible to run the example:

```bash
$ php pdfrebuild.php examples/testdoc.pdf > testdoc-rebuilt.pdf
```

The result is a more ordered PDF document which is (problably) smaller. (e.g. rebuilding examples/testdoc.pdf provides a 50961 bytes document while the original was 51269 bytes).

```
$ ls -l examples/testdoc.pdf
-rw-r--r--@ 1 calfonso  staff  51269  5 nov 14:01 examples/testdoc.pdf
$ ls -l testdoc-rebuilt.pdf
-rw-r--r--@ 1 calfonso  staff  50961  5 nov 14:22 testdoc-rebuilt.pdf
```

And the `xref` table looks significantly better ordered:
```
$ cat examples/testdoc.pdf
...
xref
0 39
0000000000 65535 f
0000049875 00000 n
0000002955 00000 n
0000005964 00000 n
0000000022 00000 n
0000002935 00000 n
0000003059 00000 n
0000005928 00000 n
...
$ cat testdoc-rebuilt.pdf
...
xref
0 8
0000000000 65535 f
0000000009 00000 n
0000000454 00000 n
0000000550 00000 n
0000000623 00000 n
0000003532 00000 n
0000003552 00000 n
0000003669 00000 n
...
```

**The code:**

```php
use ddn\sapp\PDFDoc;

require_once('vendor/autoload.php');

if ($argc !== 2)
    fwrite(STDERR, sprintf("usage: %s <filename>", $argv[0]));
else {
    if (!file_exists($argv[1]))
        fwrite(STDERR, "failed to open file " . $argv[1]);
    else {
        $obj = PDFDoc::from_string(file_get_contents($argv[1]));

        if ($obj === false)
            fwrite(STDERR, "failed to parse file " . $argv[1]);
        else
            echo $obj->to_pdf_file_s(true);
    }
}
```

### 3.2. Sign PDF files with `pdfsign.php`

To sign a PDF document, it is possible to use the script `pdfsign.php`:

```bash
$ php pdfsign.php examples/testdoc.pdf caralla.p12 > testdoc-signed.pdf
```

And now the document is signed. And if you wanted to add a second signature, it is as easy as signing the resulting document again:

```bash
$ php pdfsign.php testdoc-signed.pdf user.p12 > testdoc-resigned.pdf
```

**The code:**

```php
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
            if ($obj->sign_document($argv[2], $password) === false)
                fwrite(STDERR, "could not sign the document");
            else
                echo $obj->to_pdf_file_s();
        }
    }
}
```

### 3.3. Sign PDF files with an image, using `pdfsigni.php`

To sign a PDF document that contains an image associated to the signature, it is possible to use the script `pdfsigni.php`:

```bash
$ php pdfsigni.php examples/testdoc.pdf "https://www.google.es/images/branding/googlelogo/1x/googlelogo_color_272x92dp.png" caralla.p12 > testdoc-signed.pdf
```

And now the document is signed, and a cool image appears. If you wanted to add a second signature, it is as easy as signing the resulting document again.

The main difference with the previous code is the next:

**The code:**

_* the code related to the position in which the signature and the image appear has been ommited._

```php
...
if ($obj->sign_document($argv[3], $password, 0, [ $p_x, $p_y, $p_x + $i_w, $p_y + $i_h ], $image) === false)
    fwrite(STDERR, "could not sign the document");
else
    echo $obj->to_pdf_file_s();
...
```

## 4. Limitations

At this time, the main limitations are:
- Not dealing with **non-zero generation** pdf objects: they are uncommon, but according to the definition of the PDF structure, they are possible. If you find one non-zero generation object (you get an exception or error in that sense), please send me the document and I'll try to support it.
- Not dealing with **encrypted documents**.
- Other limitations, for sure :)

### 4.1 Known issues

Signatures may be chained using SAPP, and everything is ok with the Acrobat verification (i.e. Acrobat acknowledges that the documents and signatures are valid).

But in a scenario when a document has been signed using Acrobat tools (either Pro or Reader), if signing with SAPP again somehow breaks the integrity and Acrobat does not acknowledge the previous signatures. The next signatures chained with SAPP are ok, even if signed with Acrobat tools.

**Example**
1. Signature 1 with Acrobat => ok
1. Signature 2 with SAPP => the previous signatures are invalid, but this is ok
1. Signature 3 with Acrobat => signature 1 is invalid, but 2 and 3 are ok
1. Signature 4 with SAPP => signature 1 is invalid, but 2, 3 and 4 are ok

I did a lot of debug, but I cannot find the problem. Maybe it is related to the certificate used or the metadata, but I could not found the problem, yet.

## 5. Attributions

1. The mechanism for calculating the signature hash is heavily inspired in tcpdf.
1. Reading jpg and png files has been taken from fpdf.