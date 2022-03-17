<?php

namespace DSpaceClient;

use CURLFile;
use DSpaceClient\Exceptions\DSpaceException;
use Exception;

class File {

    public $id;
    public $filename;
    public $mimetype;
    public $uri;
    public $username;
    public $password;
    public $content;
    public $temp_path;
    public $policies = [];

    public function addPolicy(Policy $policy) {
        $this->policies[] = $policy;
    }

    public function getPolicies() : array {
        return $this->policies;
    }

    public function getCURLFile($download = true) {
        $cfile = null;
        
        if ($download && !$this->isDownloaded()) {
            $this->download();
        }

        if ($this->isDownloaded()) {
            $cfile = new CURLFile($this->getTempDownloadPath(), $this->mimetype, $this->filename);
        }

        return $cfile;
    }

    public function getTempDownloadPath() : string {
        if (empty($this->temp_path)) {
            $this->temp_path = tempnam(sys_get_temp_dir(), "file_". md5($this->filename));
        }
        return $this->temp_path;
    }

    public function isDownloaded() : bool {
        return file_exists($this->getTempDownloadPath());
    }

    public function download() {
        if ($content = $this->getRemoteContents()) {
            file_put_contents($this->getTempDownloadPath(), $content);
        }
    }

    public function deleteTempFile() {
        if ($this->isDownloaded()) {
            unlink($this->getTempDownloadPath());
        }
    }

    public function getRemoteContents() {
        
        if (!$this->uri) {
            return null;
        }

        $client = new \GuzzleHttp\Client();
        
        $data = [];
        if ($this->username && $this->password) {
            $data['auth'] = [$this->username, $this->password];
        }
        $response = $client->request('GET', $this->uri, $data);

        if ($response->getStatusCode() != 200) {
            throw new DSpaceException("Failed to download '{$this->uri}': HTTP ". $response->getStatusCode());
        }

        return $response->getBody();

    }

}