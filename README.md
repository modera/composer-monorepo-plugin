# modera/composer-monorepo-plugin

## Installation

```sh
$ composer config -g repositories.dev_modera composer https://packages.dev.modera.org
$ composer global require modera/composer-monorepo-plugin:dev-master
```

## Usage

composer.json

```json
{
    ...
    "extra": {
        "modera-monorepo": {
            "include": [
                "src/Modera/*/composer.json"
            ]
        }
    }
}
```

## Licensing

This plugin is under the MIT license. See the complete license in the file:
LICENSE