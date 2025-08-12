# Multi-Database SQL Adapter Implementation Plan

## Overview
Replace the current MySQL-specific SqlAdapter with a comprehensive multi-database system supporting MySQL, PostgreSQL, SQLite, and SQL Server.

## Current State Analysis
- **Current SqlAdapter**: MySQL-specific with backtick escaping
- **Security Features**: Parameter binding with `:search_parser_` prefix, collision detection
- **Functionality**: Complete AST visitor pattern, field validation, type casting
- **Location**: `src/Adapter/SqlAdapter.php`

## Implementation Strategy

### 1. Abstract Base Class
**File**: `src/Adapter/Sql/AbstractSqlAdapter.php`

```php
abstract class AbstractSqlAdapter implements QueryAdapterInterface, VisitorInterface
{
    // Common functionality
    protected array $bindings = [];
    protected int $bindingCounter = 0;
    protected string $bindingPrefix = ':search_parser_';
    protected SearchConfiguration $configuration;
    protected string $tableAlias;
    
    // Abstract methods for database-specific behavior
    abstract protected function escapeIdentifier(string $identifier): string;
    abstract protected function formatTableAlias(string $table, string $alias): string;
    abstract public function getName(): string;
    
    // Shared visitor methods with calls to abstract escaping
    public function visitComparison(ComparisonNode $node): string;
    public function visitBinaryOperator(BinaryOperatorNode $node): string;
    // ... etc
}
```

### 2. Database-Specific Adapters

#### MySQL Adapter
**File**: `src/Adapter/Sql/MySqlAdapter.php`
- Escaping: `` `identifier` ``
- Table aliases: `` `table` AS `alias` ``
- Features: Full MySQL compatibility, JSON field support

#### PostgreSQL Adapter  
**File**: `src/Adapter/Sql/PostgreSqlAdapter.php`
- Escaping: `"identifier"`
- Case sensitivity: Preserve case with quotes
- Features: Array field support, custom operators

#### SQLite Adapter
**File**: `src/Adapter/Sql/SqliteAdapter.php`
- Escaping: `"identifier"` or `[identifier]`
- Limitations: No schema support
- Features: Simplified type system

#### SQL Server Adapter
**File**: `src/Adapter/Sql/SqlServerAdapter.php`
- Escaping: `[identifier]`
- Schema support: `[schema].[table].[column]`
- Features: T-SQL specific functions

### 3. Factory Pattern
**File**: `src/Adapter/Sql/SqlAdapterFactory.php`

```php
class SqlAdapterFactory
{
    public static function create(
        string $database, 
        SearchConfiguration $config, 
        string $tableAlias = ''
    ): AbstractSqlAdapter {
        return match($database) {
            'mysql' => new MySqlAdapter($config, $tableAlias),
            'postgresql', 'pgsql' => new PostgreSqlAdapter($config, $tableAlias),
            'sqlite' => new SqliteAdapter($config, $tableAlias),
            'sqlsrv', 'sqlserver' => new SqlServerAdapter($config, $tableAlias),
            default => throw new InvalidArgumentException("Unsupported database: $database")
        };
    }
}
```

### 4. Configuration Enhancement
**File**: `src/Configuration/DatabaseConfiguration.php`

```php
class DatabaseConfiguration
{
    private string $driver;
    private array $driverOptions = [];
    
    public function __construct(string $driver = 'mysql') {
        $this->driver = $driver;
    }
    
    // Database-specific configuration
    public function setJsonFieldSupport(bool $enabled): self;
    public function setSchemaName(string $schema): self;
    public function setCaseSensitive(bool $sensitive): self;
}
```

## Database-Specific Features

### MySQL
- **Escaping**: Backticks `` `field` ``
- **JSON**: `JSON_EXTRACT(field, '$.path')`
- **Full-text**: `MATCH() AGAINST()`
- **Collation**: `COLLATE utf8mb4_unicode_ci`

### PostgreSQL
- **Escaping**: Double quotes `"field"`
- **Arrays**: `field[1]`, `ANY(field)`
- **JSONB**: `field->>'key'`, `field @> '{"key":"value"}'`
- **Full-text**: `to_tsvector()`, `@@`
- **Case**: Preserves case with quotes

### SQLite
- **Escaping**: `[field]` or `"field"`
- **Types**: Dynamic typing
- **JSON**: `json_extract(field, '$.path')` (v3.38+)
- **Limitations**: No schema separation

### SQL Server
- **Escaping**: Square brackets `[field]`
- **Schema**: `[schema].[table].[field]`
- **Full-text**: `CONTAINS()`, `FREETEXT()`
- **JSON**: `JSON_VALUE()`, `OPENJSON()`

## Security Considerations

### Field Validation
Each adapter implements database-specific validation:
- **MySQL**: `/^[a-zA-Z_][a-zA-Z0-9_]*$/`
- **PostgreSQL**: Unicode support, case preservation
- **SQLite**: Relaxed validation
- **SQL Server**: Schema-aware validation

### Injection Prevention
- Maintain parameter binding approach
- Database-specific escape sequence handling
- Validate identifiers per database rules
- Schema/catalog validation where applicable

## Testing Strategy

### 1. Abstract Test Base
**File**: `tests/Unit/Adapter/Sql/AbstractSqlAdapterTest.php`
- Common functionality tests
- Security tests (binding collisions, injection attempts)

### 2. Database-Specific Tests
**Files**: 
- `tests/Unit/Adapter/Sql/MySqlAdapterTest.php`
- `tests/Unit/Adapter/Sql/PostgreSqlAdapterTest.php`  
- `tests/Unit/Adapter/Sql/SqliteAdapterTest.php`
- `tests/Unit/Adapter/Sql/SqlServerAdapterTest.php`

### 3. Integration Tests
**File**: `tests/Integration/MultiDatabaseTest.php`
- Cross-database compatibility
- Same query, different SQL output
- Feature parity validation

### 4. Security Test Suite
**File**: `tests/Security/SqlInjectionTest.php`
- Database-specific injection attempts
- Identifier validation bypass attempts
- Parameter binding security

## Migration Plan

### Phase 1: Infrastructure
1. Create abstract base class with common functionality
2. Move current SqlAdapter logic to AbstractSqlAdapter
3. Implement MySqlAdapter (maintain backward compatibility)

### Phase 2: Multi-Database Support
1. Implement PostgreSQL, SQLite, SQL Server adapters
2. Create factory pattern
3. Add database configuration system

### Phase 3: Testing & Validation
1. Comprehensive test suite for each database
2. Security testing across all adapters
3. Integration test validation

### Phase 4: Documentation & Examples
1. Update README with database-specific examples
2. Migration guide from current SqlAdapter
3. Performance benchmarking

## Backward Compatibility

### Current Code Compatibility
```php
// Old way (still works)
$adapter = new SqlAdapter($config);

// New way
$adapter = SqlAdapterFactory::create('mysql', $config);

// Or directly
$adapter = new MySqlAdapter($config);
```

### Migration Strategy
1. Keep current `SqlAdapter` as alias to `MySqlAdapter`
2. Add deprecation warning
3. Remove in next major version

## File Structure After Implementation

```
src/Adapter/
├── Sql/
│   ├── AbstractSqlAdapter.php
│   ├── MySqlAdapter.php
│   ├── PostgreSqlAdapter.php
│   ├── SqliteAdapter.php
│   ├── SqlServerAdapter.php
│   └── SqlAdapterFactory.php
├── ArrayAdapter.php
└── QueryAdapterInterface.php

tests/Unit/Adapter/
├── Sql/
│   ├── AbstractSqlAdapterTest.php
│   ├── MySqlAdapterTest.php
│   ├── PostgreSqlAdapterTest.php
│   ├── SqliteAdapterTest.php
│   └── SqlServerAdapterTest.php
└── ArrayAdapterTest.php
```

## Performance Considerations

### Query Generation
- Minimize string concatenation
- Cache compiled patterns
- Optimize for common query patterns

### Memory Usage
- Reuse adapter instances
- Efficient binding storage
- Minimize AST traversal overhead

## Advanced Features (Future)

### Database-Specific Extensions
- **MySQL**: JSON field queries, full-text search
- **PostgreSQL**: Array operations, advanced JSON
- **SQL Server**: Spatial data, XML queries
- **SQLite**: FTS5 full-text search

### Query Optimization
- Index hints per database
- Query plan analysis
- Performance monitoring hooks

## Implementation Checklist

- [ ] Create AbstractSqlAdapter base class
- [ ] Implement MySqlAdapter (backward compatible)
- [ ] Implement PostgreSqlAdapter
- [ ] Implement SqliteAdapter  
- [ ] Implement SqlServerAdapter
- [ ] Create SqlAdapterFactory
- [ ] Add DatabaseConfiguration class
- [ ] Create comprehensive test suite
- [ ] Add security tests for all databases
- [ ] Update documentation
- [ ] Add migration guide
- [ ] Performance benchmarking
- [ ] Remove old SqlAdapter

## Success Criteria

1. **Functionality**: All current SqlAdapter features work across all databases
2. **Security**: No regression in security features, comprehensive injection testing
3. **Performance**: No significant performance degradation
4. **Compatibility**: Backward compatible migration path
5. **Testing**: >95% test coverage across all adapters
6. **Documentation**: Complete usage examples for each database

This plan provides a comprehensive roadmap for implementing robust multi-database SQL adapter support while maintaining security and backward compatibility.