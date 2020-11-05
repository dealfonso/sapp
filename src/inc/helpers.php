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

/** 
 * Outputs a var to a string, using the PHP var_dump function
 * @param var the variable to output
 * @return output the result of the var_dump of the variable
*/
function var_dump_to_string($var) {
    ob_start();
    var_dump($var);
    $result = ob_get_clean();
    return $result;
}
/**
 * Outputs a set of vars to a string, that is returned
 * @param vars the vars to dump
 * @return str the var_dump output of the variables
 */
function debug_var(...$vars) {
    $result = [];
    foreach ($vars as $var) {
        array_push($result, var_dump_to_string($var));
    }
    return implode("\n", $result);
}
/**
 * Function that writes the representation of some vars to 
 * @param vars comma separated list of variables to output
 */
function p_debug_var(...$vars) {
    foreach ($vars as $var) {
        $e = var_dump_to_string($var);
        p_stderr($e, "Debug");
    }
}
/**
 * Function that converts an array into a string, but also recursively converts its values
 *   just in case that they are also arrays. In case that it is not an array, it returns its
 *   string representation
 * @param e the variable to convert
 * @return str the string representation of the array
 */
function varval($e) {
    $retval = $e;
    if (is_array($e)) {
        $a = [];
        foreach ($e as $k => $v) {
            $v = varval($v);
            array_push($a, "$k => $v");
        }
        $retval = "[ " . implode(", ", $a) . " ]";
    }
    return $retval;
}
/**
 * Function that writes a string to stderr, including some information about the call stack
 * @param e the string to write to stderr
 * @param tag the tag to prepend to the string and the debug information
 * @param level the depth level to output (0 will refer to the function that called p_stderr 
 *              call itself, 1 to the function that called to the function that called p_stderr)
 */
function p_stderr(&$e, $tag = "Error", $level = 1) {
    $dinfo = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT); 
    $dinfo = $dinfo[$level];
    $e = sprintf("$tag info at %s:%d: %s", $dinfo['file'], $dinfo['line'], varval($e));
    fwrite(STDERR, "$e\n");
}
/**
 * Function that writes a string to stderr and returns a value (to ease coding like return p_debug(...))
 * @param e the debug message
 * @param retval the value to return (default: false)
 * @return retval
 */
function p_debug($e, $retval = false) {
    p_stderr($e, "Debug");
    return $retval;
}
/**
 * Function that writes a string to stderr and returns a value (to ease coding like return p_error(...))
 * @param e the error message
 * @param retval the value to return (default: false)
 * @return retval
 */
function p_error($e, $retval = false) {
    p_stderr($e, "Error");
    return $retval;
}
/**
 * Obtains a random string from a printable character set: alphanumeric, extended with
 *   common symbols, an extended with less common symbols.
 * Note: does not consider space (0x20) nor delete (0x7f) for the alphabet. All the
 *   other printable ascii chars are considered
 * @param length length of the resulting random string (default: 8)
 * @param extended true if the alphabet should consider also the common symbols (e.g. :,(...))
 * @param hard true if the alphabet should consider also the hard symbols: ^`|~ (which use to 
 *      need more than one key to be written)
 * @return random_string a random string considering the alphabet
 */
function get_random_string($length = 8, $extended = false, $hard = false){
    $token = "";
    $codeAlphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $codeAlphabet.= "abcdefghijklmnopqrstuvwxyz";
    $codeAlphabet.= "0123456789";
    if ($extended === true) {
        $codeAlphabet .= "!\"#$%&'()*+,-./:;<=>?@[\\]_{}";
    }
    if ($hard === true) {
        $codeAlphabet .= "^`|~";
    }
    $max = strlen($codeAlphabet);
    for ($i=0; $i < $length; $i++) {
        $token .= $codeAlphabet[random_int(0, $max-1)];
    }
   return $token;
}   

function get_memory_limit() {
    $memory_limit = ini_get('memory_limit');
    if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches) === 1) {
        $memory_limit = intval($matches[1]);
        switch ($matches[2]) {
            case 'G':
                $memory_limit = $memory_limit * 1024;
            case 'M':
                $memory_limit = $memory_limit * 1024;
            case 'K':
                $memory_limit = $memory_limit * 1024;
                break;
            default:
                $memory_limit = 0;
        }
    } else {
        $memory_limit = 0;
    }
    return $memory_limit;
}