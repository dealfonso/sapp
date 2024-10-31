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

namespace ddn\sapp\helpers;

use Exception;
use Stringable;

if (! defined('__CONVENIENT_MAX_BUFFER_DUMP')) {
    define('__CONVENIENT_MAX_BUFFER_DUMP', 80);
}

/**
 * This class is used to manage a buffer of characters. The main features are that
 *   it is possible to add data (by usign *data* function), and getting the current
 *   size. Then it is possible to get the whole buffer using function *get_raw*
 */
class Buffer implements Stringable
{
    protected ?string $_buffer;

    protected int $_bufferlen;

    public function __construct(?string $string = null)
    {
        if ($string === null) {
            $string = '';
        }

        $this->_buffer = $string;
        $this->_bufferlen = strlen($string);
    }

    /**
     * Provides a easy to read string representation of the buffer, using the "var_dump" output
     *   of the variable, but providing a reduced otput of the buffer
     *
     * @return str a string with the representation of the buffer
     */
    public function __toString(): string
    {
        if (strlen((string) $this->_buffer) < __CONVENIENT_MAX_BUFFER_DUMP * 2) {
            return (string) debug_var($this);
        }

        $buffer = $this->_buffer;
        $this->_buffer = substr((string) $buffer, 0, __CONVENIENT_MAX_BUFFER_DUMP);
        $this->_buffer .= "\n...\n" . substr((string) $buffer, -__CONVENIENT_MAX_BUFFER_DUMP);
        $result = debug_var($this);
        $this->_buffer = $buffer;

        return (string) $result;
    }

    /**
     * Adds raw data to the buffer
     *
     * @param data the data to add
     */
    public function data(...$datas): void
    {
        foreach ($datas as $data) {
            $this->_bufferlen += strlen((string) $data);
            $this->_buffer .= $data;
        }
    }

    /**
     * Obtains the size of the buffer
     *
     * @return size the size of the buffer
     */
    public function size(): int
    {
        return $this->_bufferlen;
    }

    /**
     * Gets the raw data from the buffer
     *
     * @return buffer the raw data
     */
    public function get_raw(): ?string
    {
        return $this->_buffer;
    }

    /**
     * Appends buffer $b to this buffer
     *
     * @param b the buffer to be added to this one
     *
     * @return buffer this object
     */
    public function append($b): static
    {
        if ($b::class !== static::class) {
            throw new Exception('invalid buffer to add to this one');
        }

        $this->_buffer .= $b->get_raw();
        $this->_bufferlen = strlen($this->_buffer);

        return $this;
    }

    /**
     * Obtains a new buffer that is the result from the concatenation of this buffer and the parameter
     *
     * @param b the buffer to be added to this one
     *
     * @return buffer the resulting buffer (different from this one)
     */
    public function add(...$bs): self
    {
        foreach ($bs as $b) {
            if ($b::class !== static::class) {
                throw new Exception('invalid buffer to add to this one');
            }
        }

        $r = new self($this->_buffer);
        foreach ($bs as $b) {
            $r->append($b);
        }

        return $r;
    }

    /**
     * Returns a new buffer that contains the same data than this one
     *
     * @return buffer the cloned buffer
     */
    public function clone(): self
    {
        return new self($this->_buffer);
    }

    public function show_bytes($columns, $offset = 0, $length = null): string
    {
        if ($length === null) {
            $length = $this->_bufferlen;
        }

        $result = '';
        $length = min($length, $this->_bufferlen);
        for ($i = $offset; $i < $length;) {
            for ($j = 0; $j < $columns && $i < $length; $i++, $j++) {
                $result .= sprintf('%02x ', ord($this->_buffer[$i]));
            }

            $result .= "\n";
        }

        return $result;
    }
}
