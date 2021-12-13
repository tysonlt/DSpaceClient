<?php

namespace DSpaceClient;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * 
 */
class DSpaceRest {

    public const STRATEGY_ADD          = 'add';
    public const STRATEGY_REPLACE      = 'replace';
    public const STRATEGY_NO_CHANGE    = 'none';

    protected const XSRF_HEADER = 'DSPACE-XSRF-TOKEN';
    protected const AUTH_HEADER = 'Authorization: Bearer';

    public $verbose = false;

    protected $api_root;
    protected $username;
    protected $password;
    protected $bearer_token;
    protected $csrf_token;

    /**
     * 
     */
    public function __construct(string $api_root, string $username, string $password) {
        $this->api_root = rtrim($api_root. '/');
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * 
     */
    public function getAllItems(string $key_by = 'id', int $page_size = 100) : array {
        $result = [];
        $page = 0;
        $has_more = true;
        $found = 0;
        $total = 0;

        while ($has_more) {
            $found = $this->getItemsByPage($page++, $result, $key_by, $page_size);
            $total += $found;
            if ($found == 0) {
                $has_more = false;
            }
        }
        return $result;
    }

    /**
     * 
     */
    public function getItemsByPage(int $page, array &$result, string $key_by = 'id', int $size = 100) : int {
        $count = 0;
        $response = $this->request("/api/core/item?page=$page&size=$size");
        foreach (Arr::get($response, '_embedded.items', []) as $item) {
            $key = $item[$key_by];
            if (array_key_exists($key, $result)) {
                throw new Exception("DSpaceRest::getItemsByPage(): DUPLICATE KEY $key_by:$key\n");
            }
            $result[$key] = $item;
            $count++;
        }
        return $count;
    }

    /**
     * 
     */
    public function search(DSpaceSearch $search, $key_by = 'id', $page = 0) : array {

        $result = [];

        $use_page = false !== $page;
        $page = 0;
        $has_more = true;

        while ($has_more) {
            $endpoint = $search->buildEndpoint($page++);
            $response = $this->request($endpoint);
            $hits = Arr::get($response, '_embedded.searchResult._embedded.objects', []);
            if (empty($hits)) {
                $has_more = false;
            } else {
                foreach ($hits as $hit) {
                    $item = Arr::get($hit, '_embedded.indexableObject');
                    if (empty($search->pluck_fields)) {
                        $result[$item[$key_by]] = $item;
                    } else {
                        $data = [];
                        foreach ($search->pluck_fields as $field) {
                            $value = '';
                            if (Str::startsWith($field, 'meta:')) {
                                $field = Str::after($field, 'meta:');
                                if (array_key_exists($field, $item['metadata'])) {
                                    $meta = $item['metadata'][$field];
                                    if (count($meta) > 0) {
                                        $value = $item['metadata'][$field][0]['value'];
                                    }
                                }
                            } else {
                                $value = data_get($item, $field);
                            }

                            $key_name = $search->getFieldAlias($field);
                            $data[$key_name] = $value;

                        }
                        if (count($data) == 1) {
                            $data = reset($data);
                        }
                        $result[$item[$key_by]] = $data;
                    }
                }
            }
            if (!$use_page) {
                $has_more = false;
            }
        }

        return $result;

    }

    /**
     * Fetch '/api/core/$core_endpoint' and key by id with value $pluck (if set).
     * 
     * Pass a list of linked endpoints in $resolve_links to also fetch links, applying
     * $pluck (if set) to the results. If links are requested, returns an array of [$result, $links].
     * Otherwise returns $result.
     * 
     * If $pluck is not set, whole item will be returned.
     */
    public function fetchCoreEndpoint($core_endpoint, $pluck = [], $resolve_links = []) {
        $result = [];
        $links = [];
        $response = $this->request("/api/core/$core_endpoint");
        foreach (Arr::get($response, "_embedded.$core_endpoint", []) as $item) {
            
            $item_id = $item['id'];
            $result[$item_id] = $this->maybePluck($item, $pluck);

            if ($resolve_links) {
                foreach (Arr::wrap($resolve_links) as $link_endpoint) {
                    if ($link_url = Arr::get($item, "_links.$link_endpoint.href")) {
                        if ($linked_items = $this->request($link_url)) {

                            if (array_key_exists('_embedded', $linked_items)) {
                                $linked_items = Arr::get($linked_items, "_embedded.$link_endpoint", []);
                            } else {
                                $linked_items = [$linked_items];
                            }

                            foreach ($linked_items as $linked_item) {
                                $linked_item_id = $linked_item['id'];
                                $linked_value = $this->maybePluck($linked_item, $pluck);
                                $links[$item_id][$link_endpoint][$linked_item_id] = $linked_value;
                            }
                        }
                    }
                }
            }

        }
        if ($resolve_links) {
            return [$result, $links];
        } else {
            return $result;
        }
    }

    /**
     * 
     */
    protected function maybePluck($item, $pluck = []) {
        $value = $item;
        if ($pluck) {
            $pluck = Arr::wrap($pluck);
            if ($pluck) {
                $value = [];
                foreach ($pluck as $field) {
                    $value[$field] = $item[$field];
                }
                $value = array_filter($value);
                if (count($value) == 1) {
                    $value = reset($value);
                }
            }
        }
        return $value;
    }

    /**
     * Call /api/core/<$core_endpoint> and return id:name map.
     */
    public function fetchByName($core_endpoint) {
        return $this->fetchCoreEndpoint($core_endpoint, 'name');
    }

    /**
     * 
     */
    public function getCommunities() : array {
        $result = [];
        $response = $this->request("/api/core/communities");
        foreach (Arr::get($response, '_embedded.communities', []) as $item) {
            $result[$item['uuid']] = $item;
        }
        return $result;
    }

    /**
     * 
     */
    public function getCollections() : array {
        $result = [];
        $response = $this->request("/api/core/collections");
        foreach (Arr::get($response, '_embedded.collections', []) as $item) {
            $result[$item['uuid']] = $item;
        }
        return $result;
    }

    /**
     * 
     */
    public function delete($uuid) : array {
        if ($uuid instanceof DSpaceItem) {
            $uuid = $uuid->id;
        }
        return $this->request("/api/core/items/$uuid", 'DELETE');
    }

    public function submitTo(DSpaceItem $item, string $endpoint) {

        $response = $this->request($endpoint, 'POST', $item->asArray());

        if (empty($response['id'])) {
            throw new DSpaceRequestFailureException("Item upload failed.", $response);
        }

        $item->id = $response['id'];
        $item->handle = $response['handle'];

        return $response;

    }

    /**
     * 
     */
    public function submit(DSpaceItem $item, bool $upload_files = true, $upload_relationships = true) {
        
        $uri = '/api/core/items?owningCollection='. $item->getOwningCollection();
        $response = $this->request($uri, 'POST', $item->asArray());

        if (empty($response['id'])) {
            throw new DSpaceRequestFailureException("Item upload failed.", $response);
        }

        $item->id = $response['id'];
        $item->handle = $response['handle'];

        if ($upload_files) {
            $this->uploadItemFiles($item);
        }

        if ($upload_relationships) {
            $this->createItemRelationships($item);
        }

        return $response;

    }

    /**
     * 
     */
    public function update(DSpaceItem $item, 
        string $file_strategy = self::STRATEGY_NO_CHANGE, 
        string $relationship_strategy = self::STRATEGY_NO_CHANGE) {

        $this->ensureRemoteId($item);
        $uri = '/api/core/items/'. $item->id;
        $response = $this->request($uri, 'PUT', $item->asArray());

        $this->validateStrategy($file_strategy);
        if ($file_strategy != self::STRATEGY_NO_CHANGE) {
            if ($file_strategy == self::STRATEGY_REPLACE) {
                $this->deleteFiles($item->id);
            }
            $this->uploadItemFiles($item);
        }

        $this->validateStrategy($relationship_strategy);
        if ($relationship_strategy != self::STRATEGY_NO_CHANGE) {
            if ($relationship_strategy == self::STRATEGY_REPLACE) {
                $this->deleteRelationships($item->id);
            }
            $this->createItemRelationships($item);
        }

        return $response;


    }

    /**
     * 
     */
    public function deleteFiles($item_uuid) {

    }

    /**
     * 
     */
    public function deleteRelationships($item_uuid) {
        $response = $this->request("/api/core/items/$item_uuid/relationships");
        foreach (Arr::get($response, '_embedded.relationships') as $relationship) {
            $href = Arr::get($relationship, '_links.self.href');
            $this->request($href, 'DELETE');
        }
    }

    /**
     * 
     */
    protected function validateStrategy($strategy) {
        if ($strategy != self::STRATEGY_ADD && 
            $strategy != self::STRATEGY_REPLACE && 
            $strategy != self::STRATEGY_NO_CHANGE) {
                throw new DSpaceException("Invalid strategy flag: $strategy");
        }
    }

    /**
     * 
     */
    public function patchItemMeta($item_uuid, array $metadata) {
        $payload = [];
        foreach ($metadata as $key => $values) {
            foreach (Arr::wrap($values) as $value) {
                $payload[] = [
                    "op" => "add",
                    "path" => "/metadata/$key",
                    "value" => ["value" => $value]
                ];
            }
        }
        return $this->request('/api/core/items/'. $item_uuid, 'PATCH', $payload);
    }

    /**
     * Does at least 3 REST calls, so should be cached by caller.
     * 
     * Only checks the leftwardType.
     */
    public function getRelationshipId(string $dc_key, string $left_entity_type, string $right_entity_type) {
        $dc_key = Str::startsWith($dc_key, 'relation.') ? Str::after($dc_key, 'relation.') : $dc_key;
        foreach ($this->fetchCoreEndpoint('relationshiptypes') as $relationship_type) {
            if ($dc_key == $relationship_type['leftwardType']) {
                $leftType = $this->request(Arr::get($relationship_type, '_links.leftType.href'));
                $rightType = $this->request(Arr::get($relationship_type, '_links.rightType.href'));
                if ($leftType['label'] == $left_entity_type && $rightType['label'] == $right_entity_type) {
                    return $relationship_type['id'];
                }
            }
        }
        return null;
    }

    /**
     * 
     */
    public function createRelationship(int $relationship_type_id, string $left_uuid, string $right_uuid) {
        $prefix = Str::finish($this->api_root, '/') . 'api/core/items/';
        $uri_list = [
            $prefix . $left_uuid, 
            $prefix . $right_uuid
        ];
        $uri = "/api/core/relationships?relationshipType=$relationship_type_id";
        return $this->request($uri, 'POST', [], null, $uri_list);
    }

    /**
     * 
     */
    public function uploadItemFiles(DSpaceItem $item) : array {
        $result = [];
        if ($item->hasFiles()) {
            $this->findOrCreateBundle($item);
            foreach ($item->getFiles() as $file) {
                $status = false === $this->uploadFile($item, $file) ? "FAILED!" : "OK";
                $result[$file->filename] = $status;
            }
        } 
        return $result;
    }

    /**
     * Both the item and all linked entities must already have a UUID.
     */
    public function createItemRelationships(DSpaceItem $item) {

        $this->ensureRemoteId($item);
        if ($item->hasEntities()) {
            foreach ($item->getEntities() as $entity) {
                $this->ensureRemoteId($entity);
                if ($relationship_type_id = $entity->getRelationshipTypeId()) {
                    $this->createRelationship($relationship_type_id, $item->id, $entity->id);
                } else {
                    throw new DSpaceException("Linked entities must have a relationship type id.");
                }
            }
        }

    }

    /**
     * 
     */
    public function getItemBundles($item_uuid) {
        $response = $this->request("/api/core/items/{$item_uuid}/bundles");
        return Arr::get($response, '_embedded.bundles', []);  
    }

    /**
     * 
     */
    public function getItemFiles($item_uuid, $bundle_name = 'ORIGINAL') {
        foreach ($this->getItemBundles($item_uuid) as $bundle) {
            if ($bundle['name'] == $bundle_name) {
                $href = Arr::get($bundle, '_links.bitstreams.href');
                $bitstreams = $this->request($href);
                $files = [];
                foreach (Arr::get($bitstreams, '_embedded.bitstreams', []) as $bitstream) {
                    $files[$bitstream['id']] = $bitstream['name'];
                }
                return $files;
            }
        }
        return [];
    }

    /**
     * 
     */
    protected function findOrCreateBundle(DSpaceItem $item, string $bundle_name="ORIGINAL") {
        
        $this->ensureRemoteId($item);

        $bundles = $this->getItemBundles($item->id);
        if (empty($bundles)) {
            $data = ['name' => $bundle_name, 'metadata' => new \stdClass];
            $bundle = $this->request($item->bundles_uri, 'POST', $data);
        } else {
            $bundle = $bundles[0];
        }

        $item->bitstreams_uri = Arr::get($bundle, '_links.bitstreams.href');
        $item->primary_bitstream_uri = Arr::get($bundle, '_links.primaryBitstream.href');

    }

    /**
     * 
     */
    protected function uploadFile(DSpaceItem $item, File $file) : array {
        $response = false;
        if ($cfile = $file->getCURLFile(true)) {
            $response = $this->request($item->bitstreams_uri, 'POST', [], $cfile);
            $file->deleteTempFile();
        }
        return $response;
    }

    /**
     * 
     */
    protected function ensureRemoteId(DSpaceItem $item) {
        if (empty($item->id)) {
            throw new Exception("DSpaceItem has no ID set: has it been uploaded yet?");
        }
    }

    /**
     * 
     */
    protected function login() {
        $auth_request = '/api/authn/login';
        return $this->_request($auth_request, 'POST', [
            'user' => $this->username,
            'password' => $this->password
        ]);
    }

    /**
     * 
     */
    public function request(string $uri, string $method='GET', array $data=[], $file=null, array $uri_list=[]) {

        $response = null;

        try {
            
            $response = $this->_request($uri, $method, $data, $file, $uri_list);

        } catch (DSpaceHttpStatusException $e) {

            try {
                
                $this->login();
                $response = $this->_request($uri, $method, $data, $file, $uri_list);

            } catch (DSpaceHttpStatusException $e) {
                error_log(sprintf("DSpaceHttpStatusException: %s, code=%s", $e->getMessage(), $e->getCode()));
                if ($data = json_decode($e->response)) {
                    throw new Exception("DSpace said: ". $data->message .': '. $data->error);
                }
                throw new Exception("Couldn't connect to DSpace, perhaps your credentials are wrong.");
            }

        }

        return $response;

    }

    public function _request(string $uri, string $method='GET', array $data=[], $file=null, array $uri_list=[]) {

        if (false === strpos($uri, '://')) {
            $endpoint = rtrim($this->api_root, '/') .'/'. ltrim($uri, '/');
        } else {
            $endpoint = $uri;
        }
        $ch = curl_init($endpoint);

        $headers = [];
        if (!empty($this->bearer_token)) {
            $headers[] = sprintf('%s %s', self::AUTH_HEADER, $this->bearer_token);
        }
        
        if (!empty($this->csrf_token)) {
            $headers[] = "X-XSRF-TOKEN: ". $this->csrf_token;
            curl_setopt($ch, CURLOPT_COOKIE, "DSPACE-XSRF-COOKIE=". $this->csrf_token);
        }

        if ($file) {
            $headers[] = "Content-Type: multipart/form-data";
            $post = ['file' => $file];
            if (!empty($data)) {
                $post['properties'] = json_encode($data);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

        } else if (!empty($uri_list)) {
            $headers[] = "Content-Type: text/uri-list";
            curl_setopt($ch, CURLOPT_POSTFIELDS, join("\n", $uri_list));

        } else if (!empty($data)) {
            $headers[] = "Content-Type: application/json";
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        
        }
        
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, [$this, 'process_curl_header']);

        $response = curl_exec($ch); 
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
       
        curl_close($ch);

        if ($status >= 400) {
            throw new DSpaceHttpStatusException($status, $response);
        }

        if ($response) {
            return json_decode($response, true);
        }

        return null;

    }

    /**
     * 
     */
    protected function process_curl_header($ch, $header) {

        if (false !== strpos($header, self::XSRF_HEADER)) {
            $arr = explode(':', $header);
            $this->csrf_token = trim($arr[1]);
        } else if (self::AUTH_HEADER == substr($header, 0, strlen(self::AUTH_HEADER))) {
            $this->bearer_token = trim(substr($header, strlen(self::AUTH_HEADER)));
        }

        return strlen($header);
    }

}

/**
 * 
 */
class DSpaceHttpStatusException extends DSpaceException {
    public $response;
    public function __construct($status, $response) {
        parent::__construct("HTTP STATUS: $status", $status);
        $this->response = $response;
    }
}

/**
 * 
 */
class DSpaceRequestFailureException extends DSpaceException {
    public $response;
    public function __construct($message, $response) {
        parent::__construct($message);
        $this->response = $response;
    }
}

class DSpaceException extends Exception {}