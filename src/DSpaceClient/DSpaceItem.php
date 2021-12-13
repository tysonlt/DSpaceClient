<?php

namespace DSpaceClient;

use Exception;

class DSpaceItem {

    public $id = null;
    public $handle = null;
    public $collection_id = null;
    public $relationship_type_id = null;
    public $name = null;
    public $props = [];

    public $bitstreams_uri = null;
    public $primary_bitstream_uri = null;

    protected $meta = [];
    protected $files = [];
    protected $entities = [];

    public function __construct(string $name = null, $id = null, $collection_id = null) {
        $this->name = $name;
        $this->id = $id;
        $this->collection_id = $collection_id;
    }

    public function getId() {
        return $this->id;
    }

    public function getUuid() {
        return $this->getId();
    }

    public function setId(string $id) : DSpaceItem {
        $this->id = $id;
        return $this;
    }

    public function setUuid(string $id) : DSpaceItem {
        $this->setId($id);
        return $this;
    }

    public function meta(): array {
        return $this->meta;
    }

    public function getName() {
        return $this->name;
    }

    public function setName(string $name) : DSpaceItem {
        $this->name = $name;
        return $this;
    }

    public function setItemProps(array $props) : DSpaceItem {
        $this->props = $props;
        return $this;
    }

    public function setDefaultProps(?string $type = 'item') : DSpaceItem {
        $this->setItemProps([
            "inArchive" => true,
            "discoverable" => true,
            "withdrawn" => false,
            "type" => $type
        ]);
        return $this;
    }

    public function getEntityType(): ?string {
        return $this->getMeta('dspace.entity.type', true);
    }

    public function setOwningCollection($collection_id) : DSpaceItem {
        $this->collection_id = $collection_id;
        return $this;
    }

    public function getRelationshipTypeId() : ?int {
        return $this->relationship_type_id;
    }

    public function setRelationshipTypeId($relationship_type_id) : DSpaceItem {
        $this->relationship_type_id = $relationship_type_id;
        return $this;
    }

    public function getOwningCollection() : ?string {
        return $this->collection_id;
    }

    public function asJSON() : string {
        return json_encode($this->asArray());
    }

    public function asArray() : array {
        return $this->buildOutput($this->name, $this->meta);
    }

    public function addFile(File $file) : DSpaceItem {
        $this->files[] = $file;
        return $this;
    }

    public function addEntity(DSpaceItem $entity) : DSpaceItem {
        if (empty($entity->getRelationshipTypeId())) {
            throw new Exception("Linked entities must have a relationship type ID.");
        }
        $this->entities[] = $entity;
        return $this;
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

    public function getMeta($key = null, bool $first = true) {

        if (empty($key)) {
            return $this->meta;
        }

        $result = [];

        if (!array_key_exists($key, $this->meta)) {
            return null;
        }

        foreach ($this->meta[$key] as $meta) {
            $value = $meta['value'];
            if ($first) {
                return $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;

    }

    public function addMeta(string $key, $value, string $language="en", $authority=null, $confidence=-1) : DSpaceItem {
        
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
        $output = [
            "name" => $name,
            "metadata" => $meta,
        ];

        if (!empty($this->props)) {
            $output = array_merge($output, $this->props);
        }

        if ($this->id) {
            $output["id"] = $this->id;
            $output["uuid"] = $this->id;
        }

        if ($this->handle) {
            $output["handle"] = $this->handle;
        }

        return $output;
    }

}