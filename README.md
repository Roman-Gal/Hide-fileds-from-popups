# Bitrix24 CRM Field Visibility

PHP and JavaScript customizations that align CRM field-selection popups and grid column settings with the current user's entity editor configuration.

## Features

- Filters field-selection popups and available grid columns.
- Supports classic CRM entities and Smart Process lists.
- Resolves personal and common entity editor configurations.
- Normalizes field codes and aliases before matching them.
- Handles both preloaded and lazy-loaded popup fields.
- Preserves the administrator bypass for the popup endpoint.
- Sorts visible field titles for easier navigation.

## How it works

1. The popup method collects field descriptors and the CRM entity context.
2. An authenticated AJAX request sends them to `ajax.php`.
3. The PHP endpoint loads the current user's editor configuration and computes allowed codes.
4. The frontend filters the popup list. `result_modifier.php` separately filters the grid column settings.

The frontend requests `/local/js/linxys.hidefieldsinsettings/ajax.php`. Keep that deployment path or update it in the snippet.

## Files

| File | Integration point |
| --- | --- |
| `script.js` | The `getFieldsListPopupContentLinxys` method inside the existing Bitrix popup component |
| `ajax.php` | PHP endpoint with the Bitrix prolog and CRM configuration lookup |
| `result_modifier.php` | Result modifier in a customized Bitrix component template |

`script.js` is an object-method fragment that uses the host component's methods and the `BX` runtime. Insert it into the relevant component object.

The endpoint previously used the repository filename `ajax,php`; it now uses the matching `.php` extension.

## Installation

1. Use a test Bitrix24 installation with the main, UI, CRM and intranet modules available.
2. Deploy `ajax.php` at the path above.
3. Integrate the JavaScript method into the popup component and connect the result modifier to the grid component template.
4. Configure representative personal and common entity editor layouts.
5. Check an ordinary user's popup and grid settings, then repeat as an administrator.
6. Exercise both lazy loading and the already-loaded field list.

AJAX requests include the Bitrix session token. The endpoint obtains the user identity from the active Bitrix session. Diagnostic file logging is disabled by default; enable it explicitly when debugging an integration.

## Checks

```sh
php -l ajax.php
php -l result_modifier.php
```

Check the JavaScript fragment inside an object wrapper when using a standalone parser. A complete integration check also needs the Bitrix runtime and its database.

## Scope

This customization controls which fields appear in configuration interfaces. Configure CRM access rights separately in Bitrix24. Exact entity, category and grid identifiers depend on the portal.
