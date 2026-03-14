Add an EventSubscriber to a Drupal module that listens to DKAN events.

## Input

$ARGUMENTS should be: `<module_path> [event_name]`

- `module_path`: Path to the module (e.g., `datastore_data_preview`)
- `event_name`: Optional event name string (e.g., `dkan_metastore_dataset_update`). If omitted, present available events for the user to choose.

## Steps

1. **Locate the module**: Read its `.info.yml` to confirm the machine name and namespace.

2. **Discover events**:
   - If no event name provided, call `list_events` MCP tool. Present the results as a numbered list showing: constant name, event string, declaring class, module. Ask the user which event(s) to subscribe to.
   - If event name provided, call `get_event_info` MCP tool on it to see the declaring class and existing subscribers. Note existing subscribers so the new one doesn't duplicate behavior.

3. **Generate the subscriber class** at `src/EventSubscriber/{Name}Subscriber.php`:
   - Namespace: `Drupal\{module_name}\EventSubscriber`
   - Implement `Symfony\Component\EventDispatcher\EventSubscriberInterface`
   - `getSubscribedEvents()` returns an array mapping the event constant to the handler method name
   - Reference the event constant from the declaring class (e.g., `MetastoreService::EVENT_DATA_GET`), not the raw string
   - Create a stub handler method with a descriptive name and an `@todo` comment
   - If the subscriber needs dependencies, use constructor-promoted properties
   - Example pattern:
     ```php
     use Drupal\metastore\MetastoreService;
     use Symfony\Component\EventDispatcher\EventSubscriberInterface;

     class DatasetUpdateSubscriber implements EventSubscriberInterface {

       public static function getSubscribedEvents(): array {
         return [
           MetastoreService::EVENT_DATA_GET => 'onDataGet',
         ];
       }

       public function onDataGet($event): void {
         // @todo Implement handler.
       }

     }
     ```

4. **Update services.yml**: Append a tagged service entry to `{module_name}.services.yml`. Create the file if it doesn't exist.
   ```yaml
   {module_name}.{subscriber_snake_name}_subscriber:
     class: Drupal\{module_name}\EventSubscriber\{Name}Subscriber
     tags:
       - { name: event_subscriber }
   ```
   If the subscriber has constructor dependencies, add `arguments:` with `@service_id` references.

5. **Generate a unit test** at `tests/src/Unit/EventSubscriber/{Name}SubscriberTest.php`:
   - Verify `getSubscribedEvents()` returns the expected event mapping
   - Test the handler method can be called without error (with mocked event if needed)

6. **Lint**: Run `ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice` on the generated files. Fix any violations.
