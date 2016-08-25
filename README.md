# modera/composer-monorepo-plugin

Composer plugin to combine requirements from multiple composer.json (at the moment "require", "require-dev") files and 
run package events on them, you may want to use this plugin when you have a "monolith" repository which contains many 
packages which at later stages are split by some mechanism like CI scripts but at the same time do not want to manually 
duplicate meta-information from nested composer.json files into the root one.

## Installation

In order for a plugin to work correctly it has to be installed globally, you can do it by running this command:

```sh
$ composer global require modera/composer-monorepo-plugin:dev-master
```

Simply specifying a plugin as your project's dependency will have no proper effect since hooks the plugin relies on are 
invoked earlier than composer starts resolving dependencies graph.

## Usage

Add "extra/modera-monorepo" section to "monolith" composer.json of your package:

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

"Include" section can be used to specify a list of "glob" expression which instruct the plugin in what directories to 
look for nested "composer.json" files. In this given example we assume that your package(library) has a "src/Modera" 
directory in which other directories live which have composer.json inside them.


## Licensing

This plugin is under the MIT license. See the complete license in the file:
LICENSE