<?php

namespace DSpaceClient;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * 
 */
class DSpaceSearch {

    public $scope = null;
    public $pluck_fields = [];
    public $field_aliases = [];
    public $filters = [];
    public $size = 100;
    public $page = 0;
    public $sort = 'dc.title'; //TODO: allow multiple
    public $sortDir = 'asc';
    public $serverPageData = [];
    public $query = null;
    public $pipe = null;

    public function pipe($callback) : DSpaceSearch {
        $this->pipe = $callback;
        return $this;
    }

    public function hasPipe() : bool {
        return ! is_callable($this->pipe);
    }

    public function addFilter($key, $value, $operator='equals') : DSpaceSearch { 
        $this->filters[] = [
            'key' => $key,
            'value' => $value,
            'operator' => $operator
        ];
        return $this;
    }

    public function setScope($scope) : DSpaceSearch {
        $this->scope = $scope;
        return $this;
    }

    public function setQuery($query, ...$fields) : DSpaceSearch {
        if (! empty($fields)) {
            $parts = [];
            foreach ($fields as $field) {
                $parts[] = sprintf('%s:%s', $field, $query);
            }
            $this->query = join(' OR ', $parts);
        } else {
            $this->query = $query;
        }
        return $this;
    }

    public function setSort($sort, $desc = true) : DSpaceSearch {
        $this->sort = $sort;
        $this->sortDir = $desc;
        return $this;
    }

    public function sortBy($sort, $desc = true) {
        return $this->setSort($sort, $desc);
    }

    public function setPageSize(int $size) : DSpaceSearch {
        $this->size = $size;
        return $this;
    }

    public function setPage(int $page) : DSpaceSearch {
        $this->page = $page;
        return $this;
    }

    public function pluck($field, $key_name = null) : DSpaceSearch {
        $this->pluck_fields[] = $field;
        if ($key_name) {
            $this->field_aliases[$field] = $key_name;
        }
        return $this;
    }

    public function pluckMeta($field, $key_name = null) : DSpaceSearch {
        $this->pluck_fields[] = 'meta:'. $field;
        if ($key_name) {
            $this->field_aliases[$field] = $key_name;
        }
        return $this;
    }

    public function getFieldAlias($field) {
        if (Str::startsWith($field, 'meta:')) {
            $field = Str::after($field, 'meta:');
        }
        return Arr::get($this->field_aliases, $field, $field);
    }

    public function nextPage() {
        $this->page++;
    }

    public function hasMorePages() {
        $page = Arr::get($this->serverPageData, 'number', null);
        $totalPages = Arr::get($this->serverPageData, 'totalPages', null);
        if (is_null($page) || is_null($totalPages)) {
            return false;
        }
        return $page < ($totalPages - 1);
    }

    public function buildEndpoint() {

        $endpoint = '/api/discover/search/objects';
        $query = [];
        if ($this->scope) {
            $query['scope'] = $this->scope;
        }

        if ($this->sort) {
            $query['sort'] = sprintf('%s,%s', $this->sort, $this->sortDir);
        }

        foreach ($this->filters as $filter) {
            $query[$filter['key']] = $filter['value'] .','. $filter['operator'];
        }

        if ($this->size) {
            $query['size'] = $this->size;
        }

        $this->query = trim($this->query);
        if (! empty($this->query)) {
            $query['query'] = $this->query;
        }

        if (! is_null($this->page)) {
            $query['page'] = $this->page;
        }

        return $endpoint .'?'. http_build_query($query);
    }

}