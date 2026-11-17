# Module-Test-Agent

This repository contains Bitrix CRM snippets that filter the fields offered in popup settings and grid column settings using the user's entity editor configuration.

## Files

- `script.js` contains the `getFieldsListPopupContentLinxys` object method. It loads popup fields, requests the allowed codes from the PHP endpoint, filters the list, and sorts the visible titles.
- `ajax,php` contains the `LinxysFilterFieldsAjax` endpoint. The JavaScript calls it at `/local/js/linxys.hidefieldsinsettings/ajax.php`, so deploy this file with the `.php` extension at that location.
- `result_modifier.php` filters the grouped and flat grid column lists in a Bitrix component result.

## Request flow

1. The popup method reads the CRM entity from the page URL and sends the available fields and filter identifier to the endpoint.
2. The endpoint resolves the entity editor configuration, normalizes field codes and aliases, and returns the allowed codes. Administrators receive the wildcard response handled by the popup method.
3. The popup displays the permitted fields in title order. The result modifier separately applies the editor configuration to the available grid columns.

## Local checks

Run these syntax checks from the repository directory with PHP available in the terminal.

```sh
php -l "ajax,php"
php -l result_modifier.php
```

The JavaScript file is an object method fragment. Check it inside an object wrapper when using a standalone JavaScript parser.

Integration testing uses a Bitrix installation with the CRM, UI, and intranet modules and representative entity editor configurations. Compare an administrator with an ordinary user and exercise both lazy-loaded and already-loaded popup fields.
