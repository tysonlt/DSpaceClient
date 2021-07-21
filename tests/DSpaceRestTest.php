<?php declare(strict_types=1);

use DSpaceClient\DSpaceRest;
use DSpaceClient\DSpaceSearch;
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

    public function testSearch() {

        $dspace = new DSpaceRest('http://localhost:8080/server', 'dspacedemo+admin@gmail.com', 'dspace');
        
        $search = new DSpaceSearch();
        $search->scope = '598f007e-6318-44f0-adc6-954570f87a68';
        $search->addFilter('f.entityType', 'Person');

        $result = $dspace->search($search);

        $this->assertIsBool(count($result) > 1);
        $item = reset($result);

        $this->assertSame('Person', $item['metadata']['dspace.entity.type'][0]['value']);



    }
    
}