# PHP Compress Tool

Compress project folder

## Installation and Usage

```
composer require --dev eghojansu/project-compress:dev-master
```

Use in your project:

```
vendor/bin/compress .
```

Or create `compress.json` and put `dir` option. So you can run:

```
vendor/bin/compress
```


## Options

Create `compress.json` or `compress.json.dist` in current working directory.

Default options:

```json
{
    "name": null,
    "dir": "{cwd}",
    "dest": "{cwd}/var",
    "bin": null,
    "options": "-mx=9 -m0=lzma2",
    "format": "7z",
    "extension": null,
    "excludes": [
        ".git",
        ".vs",
        "~$*",
        "build",
        "node_modules",
        "var",
        "vendor"
    ],
    "exclude_extensions": "7z,bak,db,env,gz,zip,rar,sqlite,sqlite3,mdf"
}
```

_Please refers to 7zip for `format` and `options` option._


## Supported compressor

* [7zip](https://www.7-zip.org/)