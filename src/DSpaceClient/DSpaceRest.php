<?php

namespace DSpaceClient;

use DSpaceClient\Exceptions\DSpaceException;
use DSpaceClient\Exceptions\DSpaceHttpStatusException;
use DSpaceClient\Exceptions\DSpaceInvalidArgumentException;
use DSpaceClient\Exceptions\DSpaceRequestFailureException;
use DSpaceClient\Exceptions\DSpaceAuthorisationException;
use DSpaceClient\Exceptions\DSpaceInvalidRequestException;
use DSpaceClient\Store\DefaultTokenStore;
use DSpaceClient\Store\TokenStore;
use Exception;
use Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * 
 */
class DSpaceRest {

    public const STRATEGY_ADD          = 'add';
    public const STRATEGY_REPLACE      = 'replace';
    public const STRATEGY_SYNC         = 'sync';
    public const STRATEGY_NO_CHANGE    = 'none';

    public const DEFAULT_BUNDLE_NAME   = 'ORIGINAL';

    protected const XSRF_HEADER = 'DSPACE-XSRF-TOKEN';
    protected const AUTH_HEADER = 'Authorization: Bearer';

    public $verbose = false;

    protected $api_root;
    protected $username;
    protected $password;
    protected $reset_http_transport = false;
    protected $found_json = true;
    protected $token_store;

    /**
     * 
     */
    public function __construct(string $api_root, string $username, string $password) {
        $this->api_root = rtrim($api_root. '/');
        $this->username = $username;
        $this->password = $password;
        $this->setTokenStore(new DefaultTokenStore());
    }

    public function setTokenStore(TokenStore $store) {
        $this->token_store = $store;
    }

    /**
     * Tell the underlying HTTP transport to clear any session data.
     * 
     * Useful for when credentials have changed.
     */
    public function resetTransport() {
        $this->reset_http_transport = true;
    }

    /**
     * Like getAllItems(), but using a generator function.
     *
     * @return Generator<DSpaceItem>
     */
    public function listItems(int $pageSize = 100, $cache_callback = null) {

        $page = -1;
        $total_pages = 0;

        while ($page < $total_pages) {
            
            $page++;
            $url = "/api/core/items?page=$page&size=$pageSize";

            if (is_callable($cache_callback)) {
                $response = $cache_callback($url, [$this, 'request']);
            } else {
                $response = $this->request($url);
            }
            
            $total_pages = Arr::get($response, 'page.totalPages');

            foreach (Arr::get($response, '_embedded.items', []) as $item) {
                yield DSpaceItem::fromRestResponse($item);
            }

        }

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
     * Return a DSpaceItem object
     */
    public function getItem($uuid) : DSpaceItem {
        return DSpaceItem::fromRestResponse(
            $this->request('/api/core/items/'. $uuid)
        );
    }

    /**
     * Returns the item array, or possibly a string if single pluck field requested.
     */
    public function pluckItemField($uuid, $pluck) {
        $response = $this->request('/api/core/items/'. $uuid);
        return $this->maybePluck($response, $pluck);
    }

    /**
     * 
     */
    public function getItemsByPage(int $page, array &$result, string $key_by = 'id', int $size = 100) : int {
        $count = 0;
        $response = $this->request("/api/core/items?page=$page&size=$size");
        foreach (Arr::get($response, '_embedded.items', []) as $item) {
            $key = $item[$key_by];
            if (array_key_exists($key, $result)) {
                throw new DSpaceException("DSpaceRest::getItemsByPage(): DUPLICATE KEY $key_by:$key\n");
            }
            $result[$key] = $item;
            $count++;
        }
        return $count;
    }

    /**
     * 
     */
    public function search(DSpaceSearch $search, $key_by = null) {

        $result = [];

        $endpoint = $search->buildEndpoint();
        $response = $this->request($endpoint);

        $hits = Arr::get($response, '_embedded.searchResult._embedded.objects', []);
        $search->serverPageData = Arr::get($response, '_embedded.searchResult.page', []);

        foreach ($hits as $hit) {
            $item = Arr::get($hit, '_embedded.indexableObject');

            if ($search->hasPipe()) {
                call_user_func($search->pipe, $item);
            }

            if (empty($search->pluck_fields)) {
                if ($key_by) {
                    $result[$item[$key_by]] = $item;
                } else {
                    $result[] = $item;
                }
            } else {
                $data = [];
                foreach ($search->pluck_fields as $field) {
                    $value = $this->pluck($item, $field);
                    $key_name = $search->getFieldAlias($field);
                    $data[$key_name] = $value;

                }
                if (count($data) == 1) {
                    $data = Arr::first($data);
                }

                if ($key_by) {
                    $result[$item[$key_by]] = $data;
                } else {
                    if (count($search->pluck_fields) == 1) {
                        $result = $data;
                    } else {
                        $result[] = $data;
                    }
                }
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
    protected function maybePluck($item, $pluck = [], $default = null) {
        $value = $item;
        if ($pluck) {
            $pluck = Arr::wrap($pluck);
            $value = [];
            foreach ($pluck as $field) {
                $value[$field] = $this->pluck($item, $field, $default);
            }
            $value = array_filter($value);
            if (count($value) == 1) {
                $value = Arr::first($value);
            }
        }
        return $value;
    }

    /**
     * 
     */
    protected function pluck(array $item, string $field, $default = null, $glue = '; ') {
        
        $value = null;

        if (Str::startsWith($field, 'meta:')) {
            $field = Str::after($field, 'meta:');
            if (array_key_exists($field, $item['metadata'])) {
                $metadata = $item['metadata'][$field];
                if (count($metadata) > 0) {
                    $join = [];
                    foreach ($metadata as $meta) {
                        $join[] = $meta['value'] ?? $default;
                    }
                    $value = join($glue, array_filter($join));
                }
            }
        } else {
            $value = data_get($item, $field, $default);
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
    public function delete($uuid) : bool {
        if ($uuid instanceof DSpaceItem) {
            $uuid = $uuid->id;
        }
        $this->request("/api/core/items/$uuid", 'DELETE');
        return true;
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
        string $relationship_strategy = self::STRATEGY_NO_CHANGE
    ) {

        $this->ensureRemoteId($item);
        $uri = '/api/core/items/'. $item->id;
        $response = $this->request($uri, 'PUT', $item->asArray());

        $this->validateStrategy($file_strategy);
        if ($file_strategy != self::STRATEGY_NO_CHANGE) {
            if ($file_strategy == self::STRATEGY_REPLACE) {
                $this->deleteFiles($item->id);
                $this->uploadItemFiles($item);
            } else if ($file_strategy == self::STRATEGY_ADD) {
                $this->uploadItemFiles($item);
            } else {
                throw new DSpaceInvalidRequestException("SYNC strategy for files is not possible.");
            }
        }

        $this->validateStrategy($relationship_strategy);
        if ($relationship_strategy != self::STRATEGY_NO_CHANGE) {
            if ($relationship_strategy == self::STRATEGY_REPLACE) {
                $this->deleteRelationships($item->id);
                $this->createItemRelationships($item);
            } else if ($relationship_strategy == self::STRATEGY_ADD) {
                $this->createItemRelationships($item);
            } else {
                $this->syncRelationships($item);
            }
            
        }

        return $response;


    }

    /**
     * 
     */
    public function deleteFiles($item_uuid) {
        foreach (array_keys($this->getItemFiles($item_uuid, null)) as $bitstream_uuid) {
            $this->deleteBitstream($bitstream_uuid);
        }
    }

    /**
     * 
     */
    public function deleteBitstream($bitstream_uuid) {
        return $this->request("/api/core/bitstreams/". $bitstream_uuid, 'DELETE');
    }

    /**
     * 
     */
    public function getBitstreamContent($bitstream_uuid) {
        return $this->request("/api/core/bitstreams/$bitstream_uuid/content");
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
            $strategy != self::STRATEGY_SYNC && 
            $strategy != self::STRATEGY_REPLACE && 
            $strategy != self::STRATEGY_NO_CHANGE) {
                throw new DSpaceInvalidArgumentException("Invalid strategy flag: $strategy");
        }
    }

    /**
     * 
     */
    public function patchItemMeta($item_uuid, array $metadata, $operation = "add") {
        $payload = [];
        foreach ($metadata as $key => $values) {
            foreach (Arr::wrap($values) as $value) {
                $payload[] = [
                    "op" => $operation,
                    "path" => "/metadata/$key",
                    "value" => ["value" => $value]
                ];
            }
        }
        return $this->request('/api/core/items/'. $item_uuid, 'PATCH', $payload);
    }

    public function removeItemMeta($item_uuid, array $meta_keys) {
        $payload = [];
        foreach (Arr::wrap($meta_keys) as $meta_key) {
            $payload[] = [
                "op" => "remove",
                "path" => "/metadata/$meta_key"
            ];
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
        $this->ensureRemoteId($item);
        if ($item->hasFiles()) {
            foreach ($item->getFiles() as $file) {
                $status = false === $this->uploadFile($item, $file) ? "FAILED!" : "OK";
                $result[$file->filename] = $status;
            }
        } 
        return $result;
    }

    /**
     * Only supports item as the left type.
     */
    public function syncRelationships(DSpaceItem $item) {

        //fetch current relationships
        $this->ensureRemoteId($item);

        //if item has no entities, just delete all of the relationships and return
        if (! $item->hasEntities()) {
            $this->deleteRelationships($item->id);
            return;
        } 

        $relationships = $this->getItemRelationships($item->id);

        //look for remote relationships no longer in the edited item
        foreach ($relationships as $rel) {
            $right_id = Str::afterLast(Arr::get($rel, '_links.rightItem.href'), '/');
            $relationship_type_id = Str::afterLast(Arr::get($rel, '_links.relationshipType.href'), '/');
            if (! $item->hasEntityById($right_id, $relationship_type_id)) {
                $this->request(Arr::get($rel, '_links.self.href'), 'DELETE');
            }
        }

        //find local entities that have no matching remote relationship
        $remote_keys = array_map(function($rel) {
            $right_id = Str::afterLast(Arr::get($rel, '_links.rightItem.href'), '/');
            $relationship_type_id = Str::afterLast(Arr::get($rel, '_links.relationshipType.href'), '/');
            return $relationship_type_id .'_'. $right_id;
        }, $relationships);

        foreach ($item->getEntities() as $entity) {
            $local_key = $entity->getRelationshipTypeId() .'_'. $entity->getUuid();
            if (! in_array($local_key, $remote_keys)) {
                $this->createRelationship($entity->relationship_type_id, $item->id, $entity->id);
            }
        }

    }

    public function getItemRelationships(string $item_uuid) {
        $response = $this->request("/api/core/items/{$item_uuid}/relationships");
        return Arr::get($response, '_embedded.relationships', []);
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
                    throw new DSpaceInvalidArgumentException("Linked entities must have a relationship type id.");
                }
            }
        }

    }

    /**
     * 
     */
    public function getItemBundles(string $item_uuid) {
        $response = $this->request("/api/core/items/{$item_uuid}/bundles");
        return Arr::get($response, '_embedded.bundles', []);  
    }

    /**
     * 
     */
    public function createItemBundle(string $item_uuid, string $bundle_name = self::DEFAULT_BUNDLE_NAME) {
        $data = ['name' => $bundle_name, 'metadata' => new \stdClass];
        return $this->request("/api/core/items/{$item_uuid}/bundles", 'POST', $data);
    }

    /**
     * 
     */
    public function getItemFiles($item_uuid, $bundle_name = self::DEFAULT_BUNDLE_NAME, bool $raw = false) {
        foreach ($this->getItemBundles($item_uuid) as $bundle) {
            if (empty($bundle_name) || $bundle['name'] == $bundle_name) {
                $href = Arr::get($bundle, '_links.bitstreams.href');
                $bitstreams = $this->request($href);
                $files = [];
                foreach (Arr::get($bitstreams, '_embedded.bitstreams', []) as $bitstream) {
                    $files[$bitstream['id']] = $raw ? $bitstream : $bitstream['name'];
                }
                return $files;
            }
        }
        return [];
    }

    /**
     * 
     */
    protected function findOrCreateBundle(DSpaceItem $item, string $bundleName = self::DEFAULT_BUNDLE_NAME) {
        
        $this->ensureRemoteId($item);

        if (empty($item->bitstreams_uri)) {

            $bundles = $this->getItemBundles($item->id);
            if (empty($bundles)) {
                $bundle = $this->createItemBundle($item->id, $bundleName);
            } else {
                //TODO: check it's the right bundle!!!
                $bundle = $bundles[0];
            }

            $item->bitstreams_uri = Arr::get($bundle, '_links.bitstreams.href');
            $item->primary_bitstream_uri = Arr::get($bundle, '_links.primaryBitstream.href');

        }

    }

    /**
     * 
     */
    public function uploadFile(DSpaceItem $item, File $file, $bundleName = self::DEFAULT_BUNDLE_NAME) : array {
        $response = false;
        $this->findOrCreateBundle($item, $bundleName);
        if ($cfile = $file->getCURLFile(true)) {
            $response = $this->request($item->bitstreams_uri, 'POST', [], $cfile);
            if (is_array($response) && array_key_exists('id', $response)) {
                $file->id = $response['id'];    
            }
            $file->deleteTempFile();
        }
        return $response;
    }

    /**
     * 
     */
    protected function ensureRemoteId(DSpaceItem $item) {
        if (empty($item->id)) {
            throw new DSpaceInvalidArgumentException("DSpaceItem has no ID set: has it been uploaded yet?");
        }
    }

    /**
     * 
     */
    protected function login() {
        $auth_request = sprintf('/api/authn/login?user=%s&password=%s', rawurlencode($this->username), rawurlencode($this->password));
        return $this->_request($auth_request, 'POST', [
            'user' => $this->username,
            'password' => $this->password
        ]); //added body to prevent 411 - no content length (a curl bug?)
    }

    public function isAuthenticated($throwExceptions = false) : bool {
        
        try {
        
            $response = $this->_request('/api/authn/status');
            if (Arr::get($response, 'okay')) {
                return Arr::get($response, 'authenticated', false);
            }

            return false;
        
        } catch (Exception $e) {
            if ($throwExceptions) {
                throw $e;
            }
            return false;
        }

    }

    /**
     * 
     */
    public function request(string $uri, string $method='GET', array $data=[], $file=null, array $uri_list=[]) {

        $response = null;

        try {

            $response = $this->_request($uri, $method, $data, $file, $uri_list);

        } catch (DSpaceAuthorisationException $e) {

            $this->login();
            $response = $this->_request($uri, $method, $data, $file, $uri_list);

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
        if ($bearer_token = $this->token_store->fetchBearerToken()) {
            $headers[] = sprintf('%s %s', self::AUTH_HEADER, $bearer_token);
        }
        
        if ($csrf_token = $this->token_store->fetchCsrfToken()) {
            $headers[] = "X-XSRF-TOKEN: ". $csrf_token;
            curl_setopt($ch, CURLOPT_COOKIE, "DSPACE-XSRF-COOKIE=". $csrf_token);
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

        if ($this->reset_http_transport) {
            curl_setopt($ch, CURLOPT_COOKIESESSION, true);
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
            $this->reset_http_transport = false;
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
            $exceptionClass = $status == 401 || $status == 403 ? DSpaceAuthorisationException::class : DSpaceHttpStatusException::class;
            throw new $exceptionClass($status, $response);
        }

        if ($response) {
            if ($this->found_json) {
                return json_decode($response, true);
            } else {
                return $response;
            }
        }

        return null;

    }

    /**
     * 
     */
    protected function process_curl_header($ch, $header) {

        if (false !== stripos($header, 'content-type')) {
            $this->found_json = false !== stripos($header, 'json');
        } else if (false !== strpos($header, self::XSRF_HEADER)) {
            $arr = explode(':', $header);
            $this->token_store->storeCsrfToken(trim($arr[1]));
        } else if (self::AUTH_HEADER == substr($header, 0, strlen(self::AUTH_HEADER))) {
            $this->token_store->storeBearerToken(trim(substr($header, strlen(self::AUTH_HEADER))));
        }

        return strlen($header);
    }

}
