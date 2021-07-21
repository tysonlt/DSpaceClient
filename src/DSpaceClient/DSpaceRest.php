<?php

namespace DSpaceClient;

use CURLFile;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * 
 */
class DSpaceRest {

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

        $this->say("Fetching all DSpace items ");
        while ($has_more) {
            $found = $this->getItemsByPage($page++, $result, $key_by, $page_size);
            $total += $found;
            $this->say(".");
            if ($found == 0) {
                $has_more = false;
            }
        }
        $this->say(" done, loaded $total/". count($result) ." items.\n");
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
                throw new Exception("*** DUPLICATE KEY $key_by:$key\n");
            }
            $result[$key] = $item;
            $count++;
        }
        return $count;
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
    public function delete(string|DSpaceItem $uuid) : array {
        if ($uuid instanceof DSpaceItem) {
            $uuid = $uuid->id;
        }
        return $this->request("/api/core/items/$uuid", 'DELETE');
    }

    /**
     * 
     */
    public function submit(DSpaceItem $item, bool $upload_files = true) {
        
        $uri = '/api/core/items?owningCollection='. $item->getOwningCollection();
        $response = $this->request($uri, 'POST', $item->asArray());
        $item->id = $response['id'];
        $item->handle = $response['handle'];

        if ($upload_files) {
            $this->uploadItemFiles($item);
        }

    }

    /**
     * 
     */
    public function update(string|DSpaceItem $item, array $metadata) {
        $uuid = $item instanceof DSpaceItem ? $item->id : $item;
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
        return $this->request('/api/core/items/'. $uuid, 'PATCH', $payload);
    }

    /**
     * 
     */
    public function getRelationshipId(string $dc_key, string $left_entity_type, string $right_entity_type) : int|null {
        throw new Exception("NOT IMPL");
        return null;
    }

    /**
     * 
     */
    public function createRelationship(int $relationship_id, string $left_uuid, string $right_uuid) {
        $prefix = Str::finish($this->api_root, '/') . 'api/core/items/';
        $uri_list = [
            $prefix . $left_uuid, 
            $prefix . $right_uuid
        ];
        $uri = "/api/core/relationships?relationshipType=$relationship_id";
        return $this->request($uri, 'POST', [], null, $uri_list);
    }

    /**
     * 
     */
    public function uploadItemFiles(DSpaceItem $item) : array {
        $result = [];
        if ($item->hasFiles()) {
            if ($this->findOrCreateBundle($item)) {
                foreach ($item->getFiles() as $file) {
                    $this->say("\t\t - uploading ". $file->filename ."... ");
                    $status = false === $this->uploadFile($item, $file) ? "FAILED!" : "OK";
                    $result[$file->filename] = $status;
                    $this->say("$status\n");
                }
            }
        } 
        return $result;
    }

    /**
     * 
     */
    protected function findOrCreateBundle(DSpaceItem $item, string $bundle_name="ORIGINAL") : bool {
        
        if ($this->ensureRemoteId($item)) {

            $item->bundles_uri = "/api/core/items/{$item->id}/bundles";

            $response = $this->request($item->bundles_uri);
            $bundles = Arr::get($response, '_embedded.bundles');  
            if (empty($bundles)) {
                $data = ['name' => $bundle_name, 'metadata' => new \stdClass];
                $bundle = $this->request($item->bundles_uri, 'POST', $data);
            } else {
                $bundle = $bundles[0];
            }

            $item->bitstreams_uri = Arr::get($bundle, '_links.bitstreams.href');
            $item->primary_bitstream_uri = Arr::get($bundle, '_links.primaryBitstream.href');

            return true;

        }

        return false;

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
        $auth_request = sprintf('/api/authn/login?user=%s&password=%s', rawurlencode($this->username), rawurlencode($this->password));
        return $this->_request($auth_request, 'POST');
    }

    /**
     * 
     */
    public function request(string $uri, string $method='GET', array $data=[], CURLFile|null $file=null, array $uri_list=[]) : array {

        $response = null;

        try {
            
            $response = $this->_request($uri, $method, $data, $file, $uri_list);

        } catch (DSpaceHttpStatusException $e) {

            try {
                $this->login();
                $response = $this->_request($uri, $method, $data, $file, $uri_list);
            } catch (DSpaceHttpStatusException $e) {
                if ($data = json_decode($e->response)) {
                    throw new Exception("DSpace said: ". $data->message);
                }
                throw new Exception("Couldn't connect to DSpace, perhaps your credentials are wrong.");
            }

        }

        return $response;

    }

    public function _request(string $uri, string $method='GET', array $data=[], CURLFile|null $file=null, array $uri_list=[]) : array|null {

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

    /**
     * 
     */
    protected function say($text) {
        if ($this->verbose) echo $text;
    }

}

/**
 * 
 */
class DSpaceHttpStatusException extends Exception {
    public $response;
    public function __construct($status, $response) {
        parent::__construct("HTTP STATUS: $status", $status);
        $this->response = $response;
    }
}

/**
 * 
 */
class DSpaceRequestFailureException extends Exception {
    public $response;
    public function __construct($response) {
        parent::__construct("DSpace returned an empty result");
        $this->response = $response;
    }
}