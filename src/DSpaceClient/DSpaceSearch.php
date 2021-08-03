<?php

namespace DSpaceClient;

use Illuminate\Support\Arr;

/**
 * 
 */
class DSpaceSearch {

    public $scope = null;
    public $sort = 'dc.title';
    public $pluck_fields = [];
    public $field_aliases = [];
    public $filters = [];

    public function addFilter($key, $value, $operator='equals') {
        $this->filters[] = [
            'key' => $key,
            'value' => $value,
            'operator' => $operator
        ];
        return $this;
    }

    public function setScope($scope) {
        $this->scope = $scope;
        return $this;
    }

    public function setSort($sort) {
        $this->sort = $sort;
        return $this;
    }

    public function pluck($field, $key_name = null) {
        $this->pluck_fields[] = $field;
        if ($key_name) {
            $this->field_aliases[$field] = $key_name;
        }
        return $this;
    }

    public function pluckMeta($field, $key_name = null) {
        $this->pluck_fields[] = 'meta:'. $field;
        if ($key_name) {
            $this->field_aliases[$field] = $key_name;
        }
        return $this;
    }

    public function getFieldAlias($field) {
        return Arr::get($this->field_aliases, $field, $field);
    }

    public function buildEndpoint($page = false) {

        $endpoint = '/api/discover/search/objects';
        $query = [];
        if ($this->scope) {
            $query['scope'] = $this->scope;
        }

        if ($this->sort) {
            $query['sort'] = $this->sort;
        }

        foreach ($this->filters as $filter) {
            $query[$filter['key']] = $filter['value'] .','. $filter['operator'];
        }

        if (false !== $page) {
            $query['page'] = $page;
        }

        return $endpoint .'?'. http_build_query($query);
    }

}