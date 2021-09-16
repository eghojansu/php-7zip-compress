# PHP Compress Tool

Compress project folder

## Installation and Usage

`composer require --dev eghojansu/project-compress:dev-master`

Use in your project: `vendor/bin/compress`

## Options

Create `compress.json` or `compress.json.dist` in current working directory.

Default options:

```json
{
    "bin": null,
    "dest": "{cwd}/dist",
    "dir": "{cwd}",
    "exclude_extensions": ["7z", "bak", "db", "env", "gz", "zip", "rar"],
    "exclude_recursives": ["~$*"],
    "exclude_extras": null,
    "excludes": [".git", ".vs", "dist", "node_modules", "var", "vendor"],
    "extension": null,
    "format": "7z",
    "name": null,
    "options": "-mx=9 -m0=lzma2"
}
```

_Please refers to 7zip for `format` and `options` option._


## Supported compressor

* [7zip](https://www.7-zip.org/)