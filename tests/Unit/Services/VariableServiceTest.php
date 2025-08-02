<?php

use App\Models\Variable;
use App\Services\VariableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new VariableService;
    Cache::flush(); // Clear cache before each test
});

// Get all variables tests
test('getAll returns cached variables', function () {
    // Create test variables
    Variable::create([
        'key' => 'test_var_1',
        'value' => 'value1',
        'type' => 'text',
        'category' => 'test',
    ]);
    Variable::create([
        'key' => 'test_var_2',
        'value' => '123',
        'type' => 'number',
        'category' => 'test',
    ]);

    // First call should query database
    $result = $this->service->getAll();

    expect($result)->toHaveCount(2);
    expect($result->first()->key)->toBe('test_var_1');

    // Delete one variable directly (bypassing service to test cache)
    Variable::where('key', 'test_var_1')->delete();

    // Should still return cached result
    $cachedResult = $this->service->getAll();
    expect($cachedResult)->toHaveCount(2);
});

test('getAll orders by category and key', function () {
    Variable::create(['key' => 'z_var', 'value' => 'val', 'type' => 'text', 'category' => 'beta']);
    Variable::create(['key' => 'a_var', 'value' => 'val', 'type' => 'text', 'category' => 'beta']);
    Variable::create(['key' => 'b_var', 'value' => 'val', 'type' => 'text', 'category' => 'alpha']);

    $result = $this->service->getAll();

    expect($result[0]->category)->toBe('alpha');
    expect($result[1]->key)->toBe('a_var');
    expect($result[2]->key)->toBe('z_var');
});

// Get by category tests
test('getByCategory returns variables for specific category', function () {
    Variable::create(['key' => 'prod_1', 'value' => 'val', 'type' => 'text', 'category' => 'product']);
    Variable::create(['key' => 'prod_2', 'value' => 'val', 'type' => 'text', 'category' => 'product']);
    Variable::create(['key' => 'price_1', 'value' => '100', 'type' => 'number', 'category' => 'pricing']);

    $result = $this->service->getByCategory('product');

    expect($result)->toHaveCount(2);
    expect($result->pluck('key')->toArray())->toBe(['prod_1', 'prod_2']);
});

test('getByCategory returns empty collection for non-existent category', function () {
    $result = $this->service->getByCategory('non_existent');

    expect($result)->toBeEmpty();
});

// Get by key tests
test('getByKey returns specific variable', function () {
    $variable = Variable::create([
        'key' => 'test_key',
        'value' => 'test_value',
        'type' => 'text',
        'category' => 'test',
    ]);

    $result = $this->service->getByKey('test_key');

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($variable->id);
    expect($result->value)->toBe('test_value');
});

test('getByKey returns null for non-existent key', function () {
    $result = $this->service->getByKey('non_existent');

    expect($result)->toBeNull();
});

// Upsert tests
test('upsert creates new variable', function () {
    $data = [
        'key' => 'new_var',
        'value' => 'new_value',
        'type' => 'text',
        'description' => 'Test variable',
        'category' => 'test',
        'is_system' => false,
    ];

    $result = $this->service->upsert($data);

    expect($result)->toBeInstanceOf(Variable::class);
    expect($result->key)->toBe('new_var');
    expect($result->value)->toBe('new_value');

    $this->assertDatabaseHas('variables', [
        'key' => 'new_var',
        'value' => 'new_value',
    ]);
});

test('upsert updates existing variable', function () {
    $variable = Variable::create([
        'key' => 'existing_var',
        'value' => 'old_value',
        'type' => 'text',
        'category' => 'test',
    ]);

    $data = [
        'key' => 'existing_var',
        'value' => 'new_value',
        'type' => 'text',
        'category' => 'updated',
    ];

    $result = $this->service->upsert($data);

    expect($result->id)->toBe($variable->id);
    expect($result->value)->toBe('new_value');
    expect($result->category)->toBe('updated');
});

test('upsert validates required fields', function () {
    $data = [
        'key' => 'test_var',
        // Missing required fields
    ];

    expect(fn () => $this->service->upsert($data))
        ->toThrow(ValidationException::class);
});

test('upsert validates type field', function () {
    $data = [
        'key' => 'test_var',
        'value' => 'test',
        'type' => 'invalid_type',
        'category' => 'test',
    ];

    expect(fn () => $this->service->upsert($data))
        ->toThrow(ValidationException::class);
});

test('upsert clears cache after update', function () {
    $variable = Variable::create([
        'key' => 'cached_var',
        'value' => 'old',
        'type' => 'text',
        'category' => 'test',
    ]);

    // Populate cache
    $this->service->getByKey('cached_var');
    $this->service->getByCategory('test');

    // Update variable
    $this->service->upsert([
        'key' => 'cached_var',
        'value' => 'new',
        'type' => 'text',
        'category' => 'test',
    ]);

    // Verify cache was cleared by checking new value is returned
    $result = $this->service->getByKey('cached_var');
    expect($result->value)->toBe('new');
});

// Delete tests
test('delete removes variable', function () {
    $variable = Variable::create([
        'key' => 'to_delete',
        'value' => 'value',
        'type' => 'text',
        'category' => 'test',
        'is_system' => false,
    ]);

    $result = $this->service->delete('to_delete');

    expect($result)->toBeTrue();
    $this->assertDatabaseMissing('variables', ['key' => 'to_delete']);
});

test('delete returns false for non-existent variable', function () {
    $result = $this->service->delete('non_existent');

    expect($result)->toBeFalse();
});

test('delete throws exception for system variables', function () {
    Variable::create([
        'key' => 'system_var',
        'value' => 'value',
        'type' => 'text',
        'category' => 'test',
        'is_system' => true,
    ]);

    expect(fn () => $this->service->delete('system_var'))
        ->toThrow(\Exception::class, 'Cannot delete system variables');
});

test('delete clears cache', function () {
    $variable = Variable::create([
        'key' => 'cached_delete',
        'value' => 'value',
        'type' => 'text',
        'category' => 'test',
        'is_system' => false,
    ]);

    // Populate cache
    $this->service->getByKey('cached_delete');

    $this->service->delete('cached_delete');

    // Verify variable is gone
    $result = $this->service->getByKey('cached_delete');
    expect($result)->toBeNull();
});

// Replace in text tests
test('replaceInText replaces variable placeholders', function () {
    Variable::create(['key' => 'product_name', 'value' => 'Awesome App', 'type' => 'text', 'category' => 'product']);
    Variable::create(['key' => 'price', 'value' => '99', 'type' => 'number', 'category' => 'pricing']);

    $text = 'Welcome to {product_name}! It costs ${price} per month.';
    $result = $this->service->replaceInText($text);

    expect($result)->toBe('Welcome to Awesome App! It costs $99 per month.');
});

test('replaceInText handles boolean values', function () {
    Variable::create(['key' => 'is_active', 'value' => 'true', 'type' => 'boolean', 'category' => 'settings']);
    Variable::create(['key' => 'is_beta', 'value' => 'false', 'type' => 'boolean', 'category' => 'settings']);

    $text = 'Active: {is_active}, Beta: {is_beta}';
    $result = $this->service->replaceInText($text);

    expect($result)->toBe('Active: true, Beta: false');
});

test('replaceInText handles json values', function () {
    Variable::create([
        'key' => 'features',
        'value' => '["feature1","feature2"]',
        'type' => 'json',
        'category' => 'product',
    ]);

    $text = 'Features: {features}';
    $result = $this->service->replaceInText($text);

    expect($result)->toBe('Features: ["feature1","feature2"]');
});

test('replaceInText uses overrides', function () {
    Variable::create(['key' => 'name', 'value' => 'Default Name', 'type' => 'text', 'category' => 'test']);

    $text = 'Hello {name}!';
    $result = $this->service->replaceInText($text, ['name' => 'Override Name']);

    expect($result)->toBe('Hello Override Name!');
});

test('replaceInText leaves unmatched placeholders', function () {
    $text = 'This {non_existent} variable does not exist';
    $result = $this->service->replaceInText($text);

    expect($result)->toBe('This {non_existent} variable does not exist');
});

// Validate tests
test('validate returns true for valid value', function () {
    Variable::create([
        'key' => 'email_var',
        'value' => 'test@example.com',
        'type' => 'text',
        'category' => 'test',
        'validation_rules' => ['email'],
    ]);

    $result = $this->service->validate('email_var', 'valid@email.com');

    expect($result)->toBeTrue();
});

test('validate returns false for invalid value', function () {
    Variable::create([
        'key' => 'email_var',
        'value' => 'test@example.com',
        'type' => 'text',
        'category' => 'test',
        'validation_rules' => ['email'],
    ]);

    $result = $this->service->validate('email_var', 'invalid-email');

    expect($result)->toBeFalse();
});

test('validate returns false for non-existent variable', function () {
    $result = $this->service->validate('non_existent', 'any_value');

    expect($result)->toBeFalse();
});

test('validate returns true when no validation rules', function () {
    Variable::create([
        'key' => 'no_rules',
        'value' => 'value',
        'type' => 'text',
        'category' => 'test',
        'validation_rules' => null,
    ]);

    $result = $this->service->validate('no_rules', 'any_value');

    expect($result)->toBeTrue();
});

// Export tests
test('export returns all variables in correct format', function () {
    Variable::create([
        'key' => 'var1',
        'value' => 'value1',
        'type' => 'text',
        'category' => 'test',
        'is_system' => true,
        'description' => 'Test variable',
    ]);
    Variable::create([
        'key' => 'var2',
        'value' => '123',
        'type' => 'number',
        'category' => 'test',
        'is_system' => false,
    ]);

    $result = $this->service->export();

    expect($result)->toHaveKeys(['version', 'exported_at', 'variables']);
    expect($result['version'])->toBe('1.0');
    expect($result['variables'])->toHaveCount(2);
    expect($result['variables'][0])->toHaveKeys([
        'key', 'value', 'type', 'description', 'category', 'is_system', 'validation_rules',
    ]);
});

// Import tests
test('import creates new variables', function () {
    $data = [
        'version' => '1.0',
        'variables' => [
            [
                'key' => 'imported1',
                'value' => 'value1',
                'type' => 'text',
                'category' => 'imported',
            ],
            [
                'key' => 'imported2',
                'value' => '456',
                'type' => 'number',
                'category' => 'imported',
            ],
        ],
    ];

    $this->service->import($data);

    $this->assertDatabaseHas('variables', ['key' => 'imported1']);
    $this->assertDatabaseHas('variables', ['key' => 'imported2']);
});

test('import updates existing variables', function () {
    Variable::create([
        'key' => 'existing',
        'value' => 'old',
        'type' => 'text',
        'category' => 'test',
    ]);

    $data = [
        'version' => '1.0',
        'variables' => [
            [
                'key' => 'existing',
                'value' => 'new',
                'type' => 'text',
                'category' => 'updated',
            ],
        ],
    ];

    $this->service->import($data);

    $variable = Variable::where('key', 'existing')->first();
    expect($variable->value)->toBe('new');
    expect($variable->category)->toBe('updated');
});

test('import skips system variables by default', function () {
    $data = [
        'version' => '1.0',
        'variables' => [
            [
                'key' => 'system_import',
                'value' => 'value',
                'type' => 'text',
                'category' => 'test',
                'is_system' => true,
            ],
        ],
    ];

    $this->service->import($data);

    $this->assertDatabaseMissing('variables', ['key' => 'system_import']);
});

test('import allows system variables when configured', function () {
    Config::set('app.allow_system_variable_import', true);

    $data = [
        'version' => '1.0',
        'variables' => [
            [
                'key' => 'system_import',
                'value' => 'value',
                'type' => 'text',
                'category' => 'test',
                'is_system' => true,
            ],
        ],
    ];

    $this->service->import($data);

    $this->assertDatabaseHas('variables', ['key' => 'system_import']);
});

test('import validates data structure', function () {
    $data = [
        // Missing required fields
        'variables' => [
            ['key' => 'test'],
        ],
    ];

    expect(fn () => $this->service->import($data))
        ->toThrow(ValidationException::class);
});

test('import uses transaction', function () {
    $data = [
        'version' => '1.0',
        'variables' => [
            [
                'key' => 'valid',
                'value' => 'value',
                'type' => 'text',
                'category' => 'test',
            ],
            [
                'key' => 'invalid',
                'value' => 'value',
                'type' => 'invalid_type', // This will cause validation to fail
                'category' => 'test',
            ],
        ],
    ];

    try {
        $this->service->import($data);
    } catch (\Exception $e) {
        // Expected to fail
    }

    // Neither variable should be created due to transaction rollback
    $this->assertDatabaseMissing('variables', ['key' => 'valid']);
    $this->assertDatabaseMissing('variables', ['key' => 'invalid']);
});

// Get variables as array tests
test('getVariablesAsArray returns key-value pairs with typed values', function () {
    Variable::create(['key' => 'text_var', 'value' => 'text', 'type' => 'text', 'category' => 'test']);
    Variable::create(['key' => 'num_var', 'value' => '42', 'type' => 'number', 'category' => 'test']);
    Variable::create(['key' => 'bool_var', 'value' => 'true', 'type' => 'boolean', 'category' => 'test']);
    Variable::create(['key' => 'json_var', 'value' => '{"a":1}', 'type' => 'json', 'category' => 'test']);

    $result = $this->service->getVariablesAsArray();

    expect($result)->toBe([
        'text_var' => 'text',
        'num_var' => 42.0,
        'bool_var' => true,
        'json_var' => ['a' => 1],
    ]);
});

// Get categories tests
test('getCategories returns unique categories sorted', function () {
    Variable::create(['key' => 'v1', 'value' => 'val', 'type' => 'text', 'category' => 'zebra']);
    Variable::create(['key' => 'v2', 'value' => 'val', 'type' => 'text', 'category' => 'alpha']);
    Variable::create(['key' => 'v3', 'value' => 'val', 'type' => 'text', 'category' => 'alpha']); // Duplicate
    Variable::create(['key' => 'v4', 'value' => 'val', 'type' => 'text', 'category' => 'beta']);

    $result = $this->service->getCategories();

    expect($result->toArray())->toBe(['alpha', 'beta', 'zebra']);
});

// Seed defaults tests
test('seedDefaults creates default variables', function () {
    $this->service->seedDefaults();

    $this->assertDatabaseHas('variables', ['key' => 'product_name']);
    $this->assertDatabaseHas('variables', ['key' => 'company_name']);
    $this->assertDatabaseHas('variables', ['key' => 'pricing_starter']);
    $this->assertDatabaseHas('variables', ['key' => 'support_email']);

    $supportEmail = Variable::where('key', 'support_email')->first();
    expect($supportEmail->validation_rules)->toBe(['email']);
    expect($supportEmail->is_system)->toBeTrue();
});

test('seedDefaults does not overwrite existing variables', function () {
    Variable::create([
        'key' => 'product_name',
        'value' => 'Existing Product',
        'type' => 'text',
        'category' => 'product',
    ]);

    $this->service->seedDefaults();

    $product = Variable::where('key', 'product_name')->first();
    expect($product->value)->toBe('Existing Product');
});
