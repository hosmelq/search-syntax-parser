<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Configuration\SearchConfiguration;

describe('allowed fields behavior', function (): void {
    it('allows any field when allowedFields is empty', function (): void {
        $config = new SearchConfiguration();

        expect($config->isFieldAllowed('anything'))->toBeTrue()
            ->and($config->isFieldAllowed('random_field'))->toBeTrue();
    });

    it('restricts fields when allowedFields is set', function (): void {
        $config = new SearchConfiguration();
        $config->setAllowedFields(['title', 'price']);

        expect($config->isFieldAllowed('title'))->toBeTrue()
            ->and($config->isFieldAllowed('price'))->toBeTrue()
            ->and($config->isFieldAllowed('description'))->toBeFalse();
    });

    it('returns allowed fields list', function (): void {
        $config = new SearchConfiguration();
        $fields = ['title', 'description', 'price'];
        $config->setAllowedFields($fields);

        expect($config->getAllowedFields())->toBe($fields);
    });
});

describe('searchable fields fallback', function (): void {
    it('returns allowedFields when searchableFields is empty', function (): void {
        $config = new SearchConfiguration();
        $config->setAllowedFields(['title', 'description']);

        expect($config->getSearchableFields())->toBe(['title', 'description']);
    });

    it('returns searchableFields when explicitly set', function (): void {
        $config = new SearchConfiguration();
        $config->setAllowedFields(['title', 'description', 'price'])
            ->setSearchableFields(['title']);

        expect($config->getSearchableFields())->toBe(['title']);
    });

    it('returns empty array when neither allowed nor searchable fields are set', function (): void {
        $config = new SearchConfiguration();

        expect($config->getSearchableFields())->toBe([]);
    });
});

describe('field validators', function (): void {
    it('returns true when no validators are set', function (): void {
        $config = new SearchConfiguration();

        expect($config->validateField('status', 'active'))->toBeTrue();
    });

    it('applies single validator', function (): void {
        $config = new SearchConfiguration();
        $config->addFieldValidator('status', function ($value): bool {
            return in_array($value, ['active', 'inactive'], true);
        });

        expect($config->validateField('status', 'active'))->toBeTrue()
            ->and($config->validateField('status', 'invalid'))->toBeFalse();
    });

    it('applies multiple validators with AND logic', function (): void {
        $config = new SearchConfiguration();
        $config->addFieldValidator('price', function ($value): bool {
            return is_numeric($value);
        })->addFieldValidator('price', function ($value): bool {
            return (float) $value > 0;
        });

        expect($config->validateField('price', '50.0'))->toBeTrue()
            ->and($config->validateField('price', 'invalid'))->toBeFalse()
            ->and($config->validateField('price', '-10'))->toBeFalse();
    });

    it('returns true for fields without validators', function (): void {
        $config = new SearchConfiguration();
        $config->addFieldValidator('status', function (): false {
            return false;
        });

        expect($config->validateField('other_field', 'any_value'))->toBeTrue();
    });
});

describe('limits', function (): void {
    it('has default limits', function (): void {
        $config = new SearchConfiguration();

        expect($config->getLimit('max_query_length'))->toBe(1000)
            ->and($config->getLimit('max_nesting_depth'))->toBe(5)
            ->and($config->getLimit('max_conditions'))->toBe(20);
    });

    it('returns null for non-existent limits', function (): void {
        $config = new SearchConfiguration();

        expect($config->getLimit('nonexistent_limit'))->toBeNull();
    });

    it('allows setting custom limits', function (): void {
        $config = new SearchConfiguration();
        $config->setLimit('max_conditions', 50)
            ->setLimit('custom_limit', 100);

        expect($config->getLimit('max_conditions'))->toBe(50)
            ->and($config->getLimit('custom_limit'))->toBe(100);
    });

    it('stores multiple limits correctly', function (): void {
        $config = new SearchConfiguration();
        $config->setLimit('custom_limit', 25)
            ->setLimit('another_limit', 75);

        expect($config->getLimit('custom_limit'))->toBe(25)
            ->and($config->getLimit('another_limit'))->toBe(75);
    });
});

describe('fluent interface', function (): void {
    it('returns self for chainable methods', function (): void {
        $config = new SearchConfiguration();
        $result = $config
            ->addFieldValidator('title', fn ($v): bool => is_string($v))
            ->setAllowedFields(['title'])
            ->setLimit('max_conditions', 10)
            ->setSearchableFields(['title']);

        expect($result)->toBe($config);
    });
});
