# modera/composer-monorepo-plugin

Composer plugin to combine requirements from multiple composer.json files and run package events on them.

## Installation

Add a repository globally:

```sh
$ composer config -g repositories.dev_modera composer https://packages.dev.modera.org
```

Install plugin globally:

```sh
$ composer global require modera/composer-monorepo-plugin:dev-master
```

## Usage

Add "extra/modera-monorepo" section to composer.json in your bundle:

```json
{
    ...
    "extra": {
        "modera-monorepo": {
            "include": [
                "src/Modera/*/composer.json",
                "src/Some/Other/composer.json"
            ]
        }
    }
}
```

## Licensing

This plugin is under the MIT license. See the complete license in the file:
LICENSE
