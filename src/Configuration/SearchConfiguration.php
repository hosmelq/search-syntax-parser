<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Configuration;

class SearchConfiguration
{
    /**
     * @var array<int, string>
     */
    private array $allowedFields = [];

    /**
     * @var array<string, int>
     */
    private array $limits = [
        'max_conditions' => 20,
        'max_nesting_depth' => 5,
        'max_query_length' => 1000,
    ];

    /**
     * @var array<int, string>
     */
    private array $searchableFields = [];

    /**
     * @var array<string, array<int, callable>>
     */
    private array $validators = [];

    /**
     * Add a validation function for a specific field.
     */
    public function addFieldValidator(string $field, callable $validator): self
    {
        if (! isset($this->validators[$field])) {
            $this->validators[$field] = [];
        }

        $this->validators[$field][] = $validator;

        return $this;
    }

    /**
     * Get allowed fields.
     *
     * @return array<int, string>
     */
    public function getAllowedFields(): array
    {
        return $this->allowedFields;
    }

    /**
     * Get a parser limit.
     */
    public function getLimit(string $key): null|int
    {
        $limit = $this->limits[$key] ?? null;

        return is_int($limit) ? $limit : null;
    }

    /**
     * Get fields to search when no field is specified.
     *
     * @return array<int, string>
     */
    public function getSearchableFields(): array
    {
        return $this->searchableFields !== [] ? $this->searchableFields : $this->allowedFields;
    }

    /**
     * Check if a field is allowed for searching.
     */
    public function isFieldAllowed(string $field): bool
    {
        return $this->allowedFields === [] || in_array($field, $this->allowedFields, true);
    }

    /**
     * Set fields that can be used in field:value searches.
     *
     * @param array<int, string> $fields
     */
    public function setAllowedFields(array $fields): self
    {
        $this->allowedFields = array_values($fields);

        return $this;
    }

    /**
     * Set a parser limit.
     */
    public function setLimit(string $key, int $value): self
    {
        $this->limits[$key] = $value;

        return $this;
    }

    /**
     * Set fields that are searched when no field is specified.
     *
     * @param array<int, string> $fields
     */
    public function setSearchableFields(array $fields): self
    {
        $this->searchableFields = array_values($fields);

        return $this;
    }

    /**
     * Validate a field value.
     */
    public function validateField(string $field, mixed $value): bool
    {
        if (! isset($this->validators[$field])) {
            return true;
        }

        foreach ($this->validators[$field] as $validator) {
            if (! $validator($value)) {
                return false;
            }
        }

        return true;
    }
}
