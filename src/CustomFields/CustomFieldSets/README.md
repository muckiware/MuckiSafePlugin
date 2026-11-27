# Custom field sets — JSON format

`Setup\CustomFieldInstaller` reads every `*.json` file in this directory on plugin install and
update and creates the custom field sets and fields defined there. Existing sets and fields are
never overwritten. On uninstall (without "keep user data") all sets and fields are removed.

## File naming

```
muwa_safe_{entity}.json
```

## Structure

```json
{
    "name": "muwa_safe_{entity}",
    "config": {
        "label": {
            "de-DE": "Label (German)",
            "en-GB": "Label (English)"
        }
    },
    "relations": [
        { "entityName": "{shopware_entity}" }
    ],
    "customFields": [
        {
            "name": "muwa_safe_{entity}_{field_name}",
            "type": "text",
            "config": {
                "customFieldType": "text",
                "customFieldPosition": 10,
                "label": {
                    "de-DE": "Field label (German)",
                    "en-GB": "Field label (English)"
                }
            }
        }
    ]
}
```

## Field types

| `type` (database) | `customFieldType` (admin) | Description |
|---|---|---|
| `text` | `text` | Single-line text |
| `html` | `textEditor` | HTML editor |
| `int` | `number` | Integer |
| `float` | `number` | Decimal |
| `bool` | `checkbox` | Checkbox |
| `datetime` | `date` | Date and time |
| `json` | `select` | JSON (e.g. multi select) |

## Notes
- **Prefix required:** custom field names are global in Shopware; always use `muwa_safe_`.
- **No updates:** existing fields are not changed. Changing a type requires removing and
  re-creating the field.
- **Entities:** only use entities that exist on the target system — on Muckidrive the Checkout
  bundle is disabled, so `order`, `customer`, `product` etc. are not available.
- **Positions:** leave gaps (10, 20, …) to insert fields later.
