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

require_once('helpers.php');

/**
 * This class abstracts the reading from a stream of data (i.e. a string). The objective of
 *   using this class is to enable the creation of other classes (e.g. FileStreamReader) to
 *   read from other char streams (e.g. a file)
 * 
 * The class gets a string that will be used as the buffer to read, and then it is possible
 *   to sequentially get the characters from the string using funcion *nextchar*, that will
 *   return "false" when the stream is finished.
 * 
 * Other functions to change the position are also available (e.g. goto)
 */
class StreamReader {
    protected $_buffer = "";
    protected $_bufferlen = 0;
    protected $_pos = 0;

    public function __construct($string = null, $offset = 0) {
        if ($string === null)
            $string = "";

        $this->_buffer = $string;
        $this->_bufferlen = strlen($string);
        $this->goto($offset);
    }

    /**
     * Advances the buffer to the next char and returns it
     * @return char the next char in the buffer
     * 
     */
    public function nextchar() {
        $this->_pos = min($this->_pos + 1, $this->_bufferlen);
        return $this->currentchar();
    }

    /**
     * Advances the buffer to the next n chars and returns them
     * @param n the number of chars to read
     * @return str the substring obtained (with at most, n chars)
     */
    public function nextchars($n) {
        $n = min($n, $this->_bufferlen - $this->_pos);
        $retval = substr($this->_buffer, $this->_pos, $n);
        $this->_pos += $n;
        return $retval;
    }

    /**
     * Returns the current char
     * @return char the current char
     */
    public function currentchar() {
        if ($this->_pos >= $this->_bufferlen)
            return false;

        return $this->_buffer[$this->_pos];
    }

    /**
     * Returns whether the stream has finished or not
     * @return finished true if there are no more chars to read from the stream; false otherwise
     */
    public function eos() {
        return $this->_pos >= $this->_bufferlen;
    }

    /**
     * Sets the position of the buffer to the position in the parameter
     * @param pos the position to which the buffer must be set
     */
    public function goto($pos = 0) {
        $this->_pos = min(max(0, $pos), $this->_bufferlen);
    }

    /**
     * Obtains a substring that begins at current position.
     * @param length length of the substring to obtain (0 or <0 will obtain the whole buffer from the current position)
     * @return substr the substring
     */
    public function substratpos($length = 0) {
        if ($length > 0)
            return substr($this->_buffer, $this->_pos, $length);
        else
            return substr($this->_buffer, $this->_pos);
    }

    /**
     * Gets the current position of the buffer
     * @return position the position of the buffer
     */
    public function getpos() {
        return $this->_pos;
    }

    /**
     * Obtains the size of the buffer
     * @return size the size of the buffer
     */
    public function size() {
        return $this->_bufferlen;
    }
}
