<?php namespace Neomerx\Tests\JsonApi\Extensions\Issue91;

/**
 * Copyright 2015-2017 info@neomerx.com
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * @package Neomerx\Tests\JsonApi
 */
class Category
{
    /** @var  int */
    public $index = null;

    /** @var  string */
    public $description = null;

    /** @var  Category|null */
    public $parent = null;

    /**
     * Category constructor.
     *
     * @param int           $index
     * @param string        $description
     * @param Category|null $parent
     */
    public function __construct($index, $description, $parent = null)
    {
        $this->index       = $index;
        $this->description = $description;
        $this->parent      = $parent;
    }
}
