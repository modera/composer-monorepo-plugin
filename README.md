# modera/composer-monorepo-plugin

## Usage

composer.json

```json
{
    ...
    "require": {
        ...
        "modera/composer-monorepo-plugin": "dev-master"
    },
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