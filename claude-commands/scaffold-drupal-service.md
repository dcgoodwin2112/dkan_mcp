Create a new Drupal service class with constructor injection, register it in services.yml, and generate a unit test.

## Input

$ARGUMENTS should be: `<module_path> <ServiceName> [dependency_service_ids...]`

- `module_path`: Path to the module (e.g., `datastore_data_preview`)
- `ServiceName`: PascalCase class name (e.g., `DataProcessor`)
- `dependency_service_ids`: Optional DKAN/Drupal service IDs to inject (e.g., `dkan.metastore.service dkan.datastore.service`)

## Steps

1. **Locate the module**: Read its `.info.yml` to confirm the machine name and derive the namespace (`Drupal\{module_name}\`).

2. **Resolve dependencies**: For each dependency service ID, call the `get_service_info` MCP tool to get:
   - The fully qualified class name (for the type hint)
   - Public method signatures (for reference when writing the service logic)

   If a service ID is a Drupal core service (e.g., `http_client`, `request_stack`), use known type hints (`ClientInterface`, `RequestStack`, etc.) without calling MCP.

3. **Generate the service class** at `src/Service/{ServiceName}.php`:
   - Namespace: `Drupal\{module_name}\Service`
   - Use PHP 8.1 constructor-promoted properties
   - Type-hint each dependency using the class from `get_service_info` output
   - Follow Drupal coding standards (class docblock, no `@param` tags on promoted constructors)
   - Example pattern:
     ```php
     public function __construct(
       protected MetastoreService $metastore,
       protected DatastoreService $datastore,
     ) {}
     ```

4. **Update services.yml**: Append the service entry to `{module_name}.services.yml`. Create the file if it doesn't exist.
   - Service ID convention: `{module_name}.{snake_case_service_name}`
   - Use `@service_id` argument references
   - Example pattern:
     ```yaml
     {module_name}.data_processor:
       class: Drupal\{module_name}\Service\DataProcessor
       arguments:
         - '@dkan.metastore.service'
         - '@dkan.datastore.service'
     ```

5. **Generate a unit test** at `tests/src/Unit/Service/{ServiceName}Test.php`:
   - Extend `TestCase`
   - Mock each dependency with `$this->createMock()`
   - Test that the service can be instantiated
   - Add one placeholder test method for the primary functionality

6. **Lint**: Run `ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice` on the generated files. Fix any violations.
