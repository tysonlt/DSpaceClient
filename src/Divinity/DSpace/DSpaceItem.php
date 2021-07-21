<?php

namespace Divinity\DSpace;

class DSpaceItem {

    public $collection_id = null;
    public $name = null;

    protected $meta = [];
    protected $files = [];
    protected $entities = [];

    public function __construct(string $name = null) {
        $this->name = $name;
    }

    public function name() {
        return $this->name;
    }

    public function meta(): array {
        return $this->meta;
    }

    public function getEntityType(): string {
        return $this->getMeta('dspace.entity.type', true);
    }

    public function getOwningCollection() : string {
        return $this->collection_id;
    }

    public function asJSON() : string {
        return json_encode($this->asArray());
    }

    public function asArray() : array {
        return $this->buildOutput($this->name, $this->meta);
    }

    public function addFile(File $file) {
        $this->files[] = $file;
    }

    public function addEntity(DSpaceItem $entity) {
        $this->entities[] = $entity;
    }

    public function hasFiles() : bool {
        return !empty($this->files);
    }

    public function getFiles() : array {
        return $this->files;
    }

    public function hasEntities(): bool {
        return !empty($this->entities);
    }

    public function getEntities(): array {
        return $this->entities;
    }

    public function getMeta($key, bool $first = true) : string|array|null {

        $result = [];

        if (!array_key_exists($key, $this->output['metadata'])) {
            return null;
        }

        foreach ($this->output['metadata'] as $meta) {
            $value = $meta['value'];
            if ($first) {
                return $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;

    }

    public function addMeta(string $key, string|array|null $value, string $language="en", $authority=null, $confidence=-1) : DSpaceItem {
        
        if (empty($value)) {
            return $this;
        }

        if (is_array($value)) {

            foreach ($value as $v) {
                $this->addMeta($key, $v, $language, $authority, $confidence);
            }
        
        } else {

            if (!array_key_exists($key, $this->meta)) {
                $this->meta[$key] = [];
            }
            array_push($this->meta[$key], $this->metaPacket($value, $language, $authority, $confidence));

        }

        return $this;
        
    }

    protected function metaPacket(string $value, string $language="en", $authority=null, $confidence=-1) {
        return [
            "value" => trim($value),
            "language" => $language,
            "authority" => $authority,
            "confidence" => $confidence,
        ];
    }

    protected function buildOutput($name, $meta) {
        return [
            "inArchive" => true,
            "discoverable" => true,
            "withdrawn" => false,
            "type" => "item",
            "name" => $name,
            "metadata" => $meta,
        ];
    }

}