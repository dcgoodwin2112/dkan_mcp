Add a route with controller and permission to a Drupal module.

## Input

$ARGUMENTS should be: `<module_path> <path> [permission]`

- `module_path`: Path to the module (e.g., `datastore_data_preview`)
- `path`: URL path (e.g., `/admin/dkan/my-page` or `/api/my-endpoint/{id}`)
- `permission`: Optional permission machine name. If omitted, defaults to `access content` for public routes or `administer site configuration` for `/admin/*` paths.

## Steps

1. **Locate the module**: Read its `.info.yml` to confirm the machine name and namespace.

2. **Check permission conflicts**: If a custom permission is provided, call `list_permissions` MCP tool to verify it doesn't conflict with an existing DKAN/Drupal permission. If it does conflict, inform the user and ask how to proceed.

3. **Derive names**:
   - Route name: `{module_name}.{descriptive_name}` (e.g., `datastore_data_preview.preview`)
   - Controller class: Derive from the path or ask the user (e.g., path `/admin/dkan/reports` → `ReportsController`)
   - Method name: Derive from the path segment (e.g., `overview`, `detail`)

4. **Update routing.yml**: Append the route to `{module_name}.routing.yml`. Create the file if it doesn't exist.
   ```yaml
   {module_name}.{route_name}:
     path: '{path}'
     defaults:
       _controller: '\Drupal\{module_name}\Controller\{ControllerClass}::{method}'
       _title: '{Page Title}'
     requirements:
       _permission: '{permission}'
   ```
   If the path has parameters (e.g., `{id}`), add `options.parameters` if they need type enforcement.

5. **Create or update controller**:
   - If the controller class doesn't exist, create `src/Controller/{ControllerClass}.php`:
     - Extend `Drupal\Core\Controller\ControllerBase`
     - Implement `ContainerInjectionInterface` via `create()` static factory method
     - Use PHP 8.1 constructor-promoted properties for dependencies
     - Return a render array from the route method
     - Example pattern:
       ```php
       use Drupal\Core\Controller\ControllerBase;
       use Symfony\Component\DependencyInjection\ContainerInterface;

       class ReportsController extends ControllerBase {

         public function __construct(
           protected MetastoreService $metastore,
         ) {}

         public static function create(ContainerInterface $container): static {
           return new static(
             $container->get('dkan.metastore.service'),
           );
         }

         public function overview(): array {
           return ['#markup' => $this->t('Page content here.')];
         }

       }
       ```
   - If the controller exists, add the new method to it.

6. **Add custom permission**: If the permission doesn't already exist in Drupal, create or update `{module_name}.permissions.yml`:
   ```yaml
   {permission_name}:
     title: '{Human-readable title}'
     description: '{Brief description}'
   ```

7. **Lint**: Run `ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice` on the generated/modified files. Fix any violations.

8. **Clear cache**: Run `ddev drush cr` so the new route is registered. Verify no errors.
