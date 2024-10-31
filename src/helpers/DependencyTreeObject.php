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

use ddn\sapp\PDFObject;
use ddn\sapp\pdfvalue\PDFValueObject;
use Generator;
use Stringable;

/**
 * A class for the PDFObjects in the dependency tree
 */
class DependencyTreeObject implements Stringable
{
    public int $is_child = 0;

    private array $children = [];

    public function __construct(
        private int $oid,
        public mixed $info = null
    ) {
    }

    public function __toString(): string
    {
        return $this->_getstr(null, isset($this->children) ? count($this->children) : 0);
    }

    /**
     * Function that links one object to its parent (i.e. adds the object to the list of children of this object)
     *  - the function increases the amount of times that one object has been added to a parent object, to detect problems in building the tree
     */
    public function addchild(int $oid, self $o): void
    {
        $this->children[$oid] = $o;
        if ($o->is_child !== 0) {
            p_warning(sprintf('object %d is already a child of other object', $o->oid));
        }

        ++$o->is_child;
    }

    /**
     * This is an iterator for the children of this object
     */
    public function children(): Generator
    {
        if (isset($this->children)) {
            foreach (array_keys($this->children) as $oid) {
                yield $oid;
            }
        }
    }

    /**
     * Gets a string that represents the object, prepending a number of spaces, proportional to the depth in the tree
     */
    protected function _getstr(?string $spaces = '', int $mychcount = 0): string
    {
        // $info = $this->oid . ($this->info?" ($this->info)":"") . (($this->is_child > 1)?" $this->is_child":"");
        $info = $this->oid . ($this->info !== null ? sprintf(' (%s)', $this->info) : '');
        if ($spaces === null) {
            $lines = [$spaces . '  ' . json_decode('"\u2501"', false, 512, JSON_THROW_ON_ERROR) . ' ' . $info];
        } elseif ($mychcount === 0) {
            $lines = [$spaces . '  ' . json_decode('"\u2514\u2500"', false, 512, JSON_THROW_ON_ERROR) . ' ' . $info];
        } else {
            $lines = [$spaces . '  ' . json_decode('"\u251c\u2500"', false, 512, JSON_THROW_ON_ERROR) . ' ' . $info];
        }

        if (isset($this->children)) {
            $chcount = count($this->children);
            foreach ($this->children as $child) {
                $chcount--;
                if ($spaces === null || $mychcount === 0) {
                    $lines[] = $child->_getstr($spaces . '   ', $chcount);
                } else {
                    $lines[] = $child->_getstr($spaces . '  ' . json_decode('"\u2502"', false, 512, JSON_THROW_ON_ERROR), $chcount);
                }
            }
        }

        return implode("\n", $lines);
    }
}

/**
 *  Fields that are blacklisted for referencing the fields;
 *      i.e. a if a reference to a object appears in a fields in the blacklist, it won't be considered as a reference to other object to build the tree
 *  The blacklist is indexed by the type of the node; * means "any type" (including the others in the blacklist)
 */
const BLACKLIST = [
    // Field "Parent" for any type of object
    '*' => ['Parent'],
    // Field "P" for nodes of type "Annot"
    'Annot' => ['P'],
];

/**
 * @return array
 */
function references_in_object(PDFObject $object): array
{
    $type = $object['Type'];
    $type = $type !== false ? $type->val() : '';

    $references = [];

    foreach ($object->get_keys() as $key) {
        // We'll skip those blacklisted fields
        if (in_array($key, BLACKLIST['*'], true)) {
            continue;
        }

        if (array_key_exists($type, BLACKLIST) && in_array($key, BLACKLIST[$type], true)) {
            continue;
        }

        if ($object[$key] instanceof PDFValueObject) {
            $r_objects = references_in_object($object[$key]);
        } else {
            // Function get_object_referenced checks whether the value (or values in a list) have the form of object references, and if they have the form
            //   it returns the object to which it references
            $r_objects = $object[$key]->get_object_referenced();

            // If the value does not have the form of a reference, it returns false
            if ($r_objects === false) {
                continue;
            }

            if (! is_array($r_objects)) {
                $r_objects = [$r_objects];
            }
        }

        // p_debug($key . "=>" . implode(",",$r_objects));

        array_push($references, ...$r_objects);
    }

    // Return the list of references in the fields of the object
    return $references;
}
