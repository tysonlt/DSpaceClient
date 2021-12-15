<?php

namespace DSpaceClient\Exceptions;

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