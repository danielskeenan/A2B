A2B is designed to work with most ETL needs out of the box.  If you find
something that may require additional configuration,
'[file an issue](https://gitlab.com/DragoonBoots/a2b/issues).

Sources and Destinations
------------------------
Oftentimes data must be extracted from a single source (e.g. an old database)
for many different migrations.  To avoid repetition, a special key may be used.

In `config/packages/a2b.yaml`, define static sources and destinations:

```yaml
a2b:
  sources:
    - name: old_db
      uri: "sqlite:///srv/data/db.sqlite"
  destinations:
    - name: new_db
      uri: "mysql://username:password@localhost:3306/data?charset=UTF-8"
```  

These can then be used in place of a URI in the migration definition:

```php
/**
 * Example migration
 *
 * @DataMigration(
 *     name="Example",
 *     group="Test",
 *     source="old_db",
 *     destination="new_db",
 *     sourceIds={@IdField(name="id")},
 *     destinationIds={@IdField(name="id")}
 * )
 */
```