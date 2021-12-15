<?php

namespace DSpaceClient\Exceptions;

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
