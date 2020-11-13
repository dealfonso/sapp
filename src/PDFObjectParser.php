<?php
/*
    This file is part of SAPP

    Simply and Agnostic PDF Parser (SAPP) - Parse PDF documents in PHP (and update them)
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
    
    use ddn\sapp\pdfvalue\PDFValue;
    use ddn\sapp\pdfvalue\PDFValueHexString;
    use ddn\sapp\pdfvalue\PDFValueList;
    use ddn\sapp\pdfvalue\PDFValueObject;
    use ddn\sapp\pdfvalue\PDFValueReference;
    use ddn\sapp\pdfvalue\PDFValueSimple;
    use ddn\sapp\pdfvalue\PDFValueString;
    use ddn\sapp\pdfvalue\PDFValueType;
    use \StreamReader;

    require_once( __DIR__ . '/inc/helpers.php');
    require_once( __DIR__ . '/inc/buffer.php');
    require_once( __DIR__ . '/inc/streamreader.php');

    /**
     * Class devoted to parse a single PDF object
     * 
     * A PDF Document is made of objects with the following structure (e.g for object 1 version 0)
     * 
     * 1 0 obj
     * ...content...
     * [stream
     * ...stream...
     * endstream]
     * endobject
     * 
     * This PDF class transforms the definition string within ...content... into a PDFValue class.
     * 
     * - At the end, it is a simple syntax checker
     */
    class PDFObjectParser {

        // Possible tokens in a PDF document
        const T_NOTOKEN = 0;
        const T_LIST_START = 1;
        const T_LIST_END = 2;
        const T_FIELD = 3;
        const T_STRING = 4;
        const T_HEX_STRING = 12;
        const T_SIMPLE = 5;
        const T_DICT_START = 6;
        const T_DICT_END = 7;
        const T_OBJECT_BEGIN = 8;
        const T_OBJECT_END = 9;
        const T_STREAM_BEGIN = 10;
        const T_STREAM_END = 11;

        const T_NAMES = [
            self::T_NOTOKEN => 'no token',
            self::T_LIST_START => 'list start',
            self::T_LIST_END => 'list end',
            self::T_FIELD => 'field',
            self::T_STRING => 'string',
            self::T_HEX_STRING => 'hex string',
            self::T_SIMPLE => 'simple',
            self::T_DICT_START => 'dict start',
            self::T_DICT_END => 'dict end',
            self::T_OBJECT_BEGIN => 'object begin',
            self::T_OBJECT_END => 'object end',
            self::T_STREAM_BEGIN => 'stream begin',
            self::T_STREAM_END => 'stream end'
        ];

        const T_SIMPLE_OBJECTS = [
            self::T_SIMPLE,
            self::T_OBJECT_BEGIN,
            self::T_OBJECT_END,
            self::T_STREAM_BEGIN,
            self::T_STREAM_END
        ];

        protected $_buffer = null;
        protected $_c = false;
        protected $_n = false;
        protected $_t = false;
        protected $_tt = self::T_NOTOKEN;

        /**
         * Retrieves the current token type (one of T_* constants)
         * @return token the current token
         */
        public function current_token() {
            return $this->_tt;
        }

        /**
         * Obtains the next char and prepares the variable $this->_c and $this->_n to contain the current char and the next char
         *  - if EOF, _c will be false
         *  - if the last char before EOF, _n will be false
         * @return char the next char
         */
        protected function nextchar() {
            $this->_c = $this->_n;
            $this->_n = $this->_buffer->nextchar();
            return $this->_c;
        }

        /**
         * Prepares the parser to analythe the text (i.e. prepares the parsing variables)
         */
        protected function start(&$buffer) {
            $this->_buffer = $buffer;
            $this->_c = false;
            $this->_n = false;
            $this->_t = false;
            $this->_tt = self::T_NOTOKEN;

            if ($this->_buffer->size() === 0) return false;
            $this->_n = $this->_buffer->currentchar();
            $this->nextchar();
        }

        /**
         * Parses the document
         */
        public function parse(&$stream) { // $str, $offset = 0) {
            $this->start($stream); //$str, $offset);
            $this->nexttoken();
            $result = $this->_parse_value();
            return $result;
        }

        public function parsestr($str, $offset = 0) {
            $stream = new StreamReader($str);
            $stream->goto($offset);
            return $this->parse($stream);
        }

        /**
         * Simple output of the object
         * @return output the output of the object
         */
        public function __toString() {
            return "pos: " . $this->_buffer->getpos() . ", c: $this->_c, n: $this->_n, t: $this->_t, tt: " .
            self::T_NAMES[$this->_tt] . ', b: ' . $this->_buffer->substratpos(50) .
            "\n";
        }

        /**
         * Obtains the next token and returns it
         */
        public function nexttoken() {
            [ $this->_t, $this->_tt ] = $this->token();
            return $this->_t;
        }

        /**
         * Function that returns wether the current char is a separator or not
         */
        protected function _c_is_separator() {
            $DSEPS =[ "<<", ">>" ];

            return (($this->_c === false) || (strpos("<([]/ \n\r\t", $this->_c) !== false) || ((array_search($this->_c . $this->_n, $DSEPS)) !== false));
        }

        /**
         * Analyzes the buffer from the current char and gets the next token (text and type)
         * @return [token, token type] the token string and the token type
         */
        protected function token() {
            if ($this->_c === false) return [ false, false ];

            // The resulting token
            $token = false;

            while ($this->_c !== false) {
                // Skip the spaces
                while ((strpos("\t\n\r ", $this->_c) !== false) && ($this->nextchar() !== false)) ;

                $token_type = self::T_NOTOKEN;

                // TODO: hexadecimal strings are not parsed properly, according to section 7.3.4; the hexadecimal string are <abcdef12345>; where all the shall be hexadecimal values or separators
                // TODO: literal strings are not parsed properly, according to section 7.3.4.2: the strings may contain "balanced pairs of parentheses" and may "require no special treatment"; i.e. (this is a (correct) string)
                // TODO: also the special characters are not "strictly" considered, according to section 7.3.4.2: \n \r \t \b \f \( \) \\ are valid; the other not; but also \bbb should be considered; all of them are "sufficiently" treated, but other unknown caracters such as \u are also accepted
                switch ($this->_c) {
                    case '<':
                        if ($this->_n === '<') {
                            $this->nextchar();
                            $this->nextchar();
                            $token = '<<';
                            $token_type = self::T_DICT_START;
                        } else {
                            $token = "";
                            $this->nextchar();
                            while (($this->_c !== '>')&&(strpos("0123456789abcdefABCDEF \t\r\n\f", $this->_c) !== false)) {
                                $token .= $this->_c;
                                if ($this->nextchar() === false) {
                                    break;
                                }
                            }
                            if (($this->_c !== false) && (strpos(">0123456789abcdefABCDEF \t\r\n\f", $this->_c) === false))
                                throw new Exception("invalid hex string");
                            $token_type = self::T_HEX_STRING;
                            $this->nextchar();
                        }
                        break;
                    case '>':
                        if ($this->_n === '>') {
                            $this->nextchar();
                            $this->nextchar();
                            $token = '>>';
                            $token_type = self::T_DICT_END;
                        }
                        break;
                    case '(':
                        $token = "";
                        $this->nextchar();
                        while ($this->_c !== ')') {
                            if ($this->_c . $this->_n === "\\)") {
                                $token .= $this->_c;
                                $this->nextchar();
                            }
                            $token .= $this->_c;
                            if ($this->nextchar() === false) {
                                break;
                            }
                        }
                        $token_type = self::T_STRING;
                        $this->nextchar();
                        break;
                    case '[':
                        $token = $this->_c;
                        $this->nextchar();
                        $token_type = self::T_LIST_START;
                        break;
                    case ']':
                        $token = $this->_c;
                        $this->nextchar();
                        $token_type = self::T_LIST_END;
                        break;
                    case '/':
                        // Skip the field idenifyer
                        $this->nextchar();

                        // We are assuming any char (i.e. /MY+difficult_id is valid)
                        while (!$this->_c_is_separator()) {
                            $token .= $this->_c;
                            if ($this->nextchar() === false) break;
                        }
                        $token_type = self::T_FIELD;
                        break;
                }

                if ($token === false) {
                    $token = "";

                    while (!$this->_c_is_separator()) {
                        $token .= $this->_c;
                        if ($this->nextchar() === false) break;
                    }

                    switch ($token) {
                        case 'obj':
                            $token_type = self::T_OBJECT_BEGIN; break;
                        case 'endobj':
                            $token_type = self::T_OBJECT_END; break;
                        case 'stream':
                            $token_type = self::T_STREAM_BEGIN; break;
                        case 'endstream':
                            $token_type = self::T_STREAM_END; break;
                        default:
                            $token_type = self::T_SIMPLE; break;
                    }
                    
                }
                return [ $token, $token_type ];
            }
        }

        protected function _consume_simples() {
            $value = $this->_parse_value();
            while (array_search($this->_tt, self::T_SIMPLE_OBJECTS) !== false) {
                $value->push($this->_parse_value());
            }
            return $value;
        }

        protected function _parse_obj() {
            if ($this->_tt !== self::T_DICT_START) {
                throw new Exception("Invalid object definition");
            }

            $this->nexttoken();
            $object = [];
            while ($this->_t !== false) {
                switch ($this->_tt) {
                    case self::T_FIELD:
                        $field = $this->_t;
                        $this->nexttoken();
                        $object[$field] = $this->_consume_simples();
                        break;
                    case self::T_DICT_END:
                        $this->nexttoken();
                        return new PDFValueObject($object);
                        break;
                    default:
                        throw new Exception("Invalid token: $this");
                }
            }
            return false;
        }

        protected function _parse_list() {
            if ($this->_tt !== self::T_LIST_START) {
                throw new Exception("Invalid list definition");
            }

            $this->nexttoken();
            $list = [];
            while ($this->_t !== false) {
                switch ($this->_tt) {
                    case self::T_LIST_END:
                        $this->nexttoken();
                        return new PDFValueList($list);

                    case self::T_OBJECT_BEGIN:
                    case self::T_OBJECT_END:
                    case self::T_STREAM_BEGIN:
                    case self::T_STREAM_END:
                    case self::T_SIMPLE:
                        // Join simple values into a single one
                        //$value = $this->_consume_simples();
                        $value = new PDFValueSimple($this->_t);
                        $this->nexttoken();
                        array_push($list, $value);
                        break;
                    default:
                        $value = $this->_parse_value();
                        if ($value !== false) {
                            array_push($list, $value);
                        }
                        break;
                }
            }
            return new PDFValueList($list);
        }

        protected function _parse_value() {
            while ($this->_t !== false) {
                switch ($this->_tt) {
                    case self::T_DICT_START:
                        return $this->_parse_obj();
                        break;
                    case self::T_LIST_START:
                        return $this->_parse_list();
                        break;
                    case self::T_STRING:
                        $string = new PDFValueString($this->_t);
                        $this->nexttoken();
                        return $string;
                        break;
                    case self::T_HEX_STRING:
                        $string = new PDFValueHexString($this->_t);
                        $this->nexttoken();
                        return $string;
                        break;
                    case self::T_FIELD:
                        $field = new PDFValueType($this->_t);
                        $this->nexttoken();
                        return $field;
                    case self::T_OBJECT_BEGIN:
                    case self::T_OBJECT_END:
                    case self::T_STREAM_BEGIN:
                    case self::T_STREAM_END:
                        $special = new PDFValueSimple($this->_t);
                        $this->nexttoken();
                        return $special;

                    case self::T_SIMPLE:
                        $simple = new PDFValueSimple($this->_t);
                        $this->nexttoken();
                        return $simple;
                        break;

            
                    default:
                        throw new Exception("Invalid token: $this");
                }
            }
            return false;
        }

        function tokenize() {
            $this->start();
            while ($this->nexttoken() !== false) {
                echo "$this->_t\n";
            }
        }
    }