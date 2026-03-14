Run a full validation suite against a Drupal module and report results.

## Input

$ARGUMENTS should be the module path relative to the project root (e.g., `datastore_data_preview` or `web/modules/custom/my_module`).

## Steps

1. **Locate the module**: Verify the path exists and contains a `.info.yml` file. If the argument is a module name rather than a path, check `web/modules/contrib/`, `web/modules/custom/`, and the project root.

2. **Coding standards** (phpcs):
   ```
   ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice {module_path}/src/
   ```
   Report violations with file and line number. If phpcs is not installed, note it and continue.

3. **Unit tests** (phpunit):
   - If `{module_path}/phpunit.xml` exists, run standalone: `cd {module_path} && phpunit`
   - Otherwise try Drupal bootstrap: `ddev exec phpunit {module_path}/tests/`
   - Report pass/fail counts.

4. **Permission audit**: Call the `check_permissions` MCP tool. Report any orphaned route permissions, unused permissions, or orphaned role permissions that involve this module.

5. **Cache rebuild**:
   ```
   ddev drush cr
   ```
   A successful rebuild confirms service definitions, routing, and class autoloading are valid. Report any errors.

6. **Summary**: Present a pass/fail table for each check. For failures, include the specific errors so the user can decide what to fix.
