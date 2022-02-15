<?php

namespace DSpaceClient;

use DSpaceClient\Exceptions\DSpaceInvalidArgumentException;
use Exception;
use Illuminate\Support\Str;

class DSpaceItem {

    public $id = null;
    public $name = null;
    public $handle = null;
    public $collection_id = null;
    public $relationship_type_id = null;
    public $props = [];

    public $bitstreams_uri = null;
    public $primary_bitstream_uri = null;

    protected $meta = [];
    protected $files = [];
    protected $entities = [];

    public static function fromRestResponse(array $response) : DSpaceItem {
        
        $item = new static();

        $item->id = $response['id'] ?? null;
        $item->name = $response['name'] ?? null;
        $item->handle = $response['handle'] ?? null;

        $item->setEntityType($response['entityType'] ?? null);

        foreach ($item->props as $k => $v) {
            $item->props[$k] = $response[$k] ?? $v;
        }

        $item->meta = $response['metadata'] ?? [];

        return $item;

    }

    public function __construct(string $name = null, string $id = null, string $collection_id = null) {
        $this->name = $name;
        $this->id = $id;
        $this->collection_id = $collection_id;
        $this->setDefaultProps();
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

    public function getHandle() {
        return $this->handle;
    }

    public function setHandle(string $handle) : DSpaceItem {
        $this->handle = $handle;
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

    public function setEntityType(string $entityType) : DSpaceItem {
        $this->addMeta('dspace.entity.type', $entityType);
        return $this;
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

    public function hasEntity(string $entityType, string $uuid, ?int $relationshipTypeId = null) : bool {
        foreach ($this->getEntities() as $entity) {
            if ($entity->getEntityType() == $entityType && $entity->getUuid() == $uuid) {
                if ($relationshipTypeId) {
                    if ($entity->getRelationshipTypeId() == $relationshipTypeId) {
                        return true;
                    }
                } else {
                    return true;
                }
            }
        }
        return false;
    }

    public function hasEntityById(string $uuid, ?int $relationshipTypeId = null) : bool {
        foreach ($this->getEntities() as $entity) {
            if ($entity->getUuid() == $uuid) {
                if ($relationshipTypeId) {
                    if ($entity->getRelationshipTypeId() == $relationshipTypeId) {
                        return true;
                    }
                } else {
                    return true;
                }
            }
        }
        return false;
    }

    public function addEntity(DSpaceItem $entity) : DSpaceItem {
        if (empty($entity->getRelationshipTypeId())) {
            throw new DSpaceInvalidArgumentException("Linked entities must have a relationship type ID.");
        }
        if (empty($entity->getEntityType())) {
            throw new DSpaceInvalidArgumentException("Entities must have an entity type.");
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

    public function hasMeta(string $key) : bool {
        return array_key_exists($key, $this->meta); 
    }

    public function getMeta(string $key, bool $first = true) {
        $result = $this->getMetaArray($key);
        if ($first) {
            $result = ! empty($result) ? $result[0] : null;
        }
        return $result;
    }

    public function getMetaArray($key) : array {
        return 
            array_key_exists($key, $this->meta) ?
            array_map('trim', array_column($this->meta[$key], 'value')) :
            [];
    }

    public function getMetaArrayWildcard($meta_key_search, bool $first = true) : array {
        $found = [];
        $context = Str::before($meta_key_search, '*');
        foreach ($this->meta as $key => $meta) {
            if (Str::startsWith($key, $context)) {
                if (!array_key_exists($key, $found)) {
                    $found[$key] = [];
                }
                if ($first) {
                    $found[$key] = $meta[0]['value'];
                } else {
                    foreach ($meta as $entry) {
                        $found[$key][] = $entry['value'];
                    }
                }
            }
        }
        return $found;
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