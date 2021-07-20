<?php

namespace Divinity\DSpace;

interface DSpaceItem {

    public function name(): string;

    public function addMeta(string $key, string|array|null $value, string $language="en", $authority=null, $confidence=-1) : DSpaceItem;

    public function meta(): array;

    public function getItemType() : string;

    public function getEntityType(): string;

    public function getOwningCollection() : string;

    public function asJSON() : string;

    public function asArray() : array;

    public function hasFiles() : bool;

    public function getFiles() : array;

    public function isValid() : bool;
    
    public function hasEntities(): bool;

    public function getEntities(): array;

}