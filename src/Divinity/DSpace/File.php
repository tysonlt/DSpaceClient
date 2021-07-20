<?php

namespace Divinity\DSpace;

use CURLFile;
use Exception;

class File {

    public $filename;
    public $mimetype;
    public $uri;
    public $username;
    public $password;
    public $content;
    public $temp_path;

    public function getCURLFile($download = true) : CURLFile|null {
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
        
        $client = new \GuzzleHttp\Client();
        
        $data = [];
        if ($this->username && $this->password) {
            $data['auth'] = [$this->username, $this->password];
        }
        $response = $client->request('GET', $this->uri, $data);

        if ($response->getStatusCode() != 200) {
            throw new Exception("Failed to download '{$this->uri}': HTTP ". $response->getStatusCode());
        }

        return $response->getBody();

    }

}