<?php declare(strict_types=1);

use DSpaceClient\DSpaceRest;
use PHPUnit\Framework\TestCase;

final class DSpaceRestTest extends TestCase {

    public function testPaging() {

        $dspace = new DSpaceRest('http://localhost:8080/server', 'dspacedemo+admin@gmail.com', 'dspace');
        $result = [];

        try {
            $dspace->getItemsByPage(0, $result, 'id', 10);
        } catch (Exception $e) {
            $this->fail($e->getMessage() .': '. print_r($e->response, true));
        }

        $this->assertSame(10, count($result));

    }
    
}