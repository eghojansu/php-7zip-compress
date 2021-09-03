# PHP Compress Tool

Compress project folder

## Usage

```
php bin/compress [path to compress]
```

## Options

Write `compress.json` or `compress.json.dist` in current working directory.

Default options:

```json
{
    "name": null,
    "dir": "{cwd}",
    "dest": "{cwd}/var",
    "bin": null,
    "options": "-t7z -mx=9 -m0=lzma2",
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

## Supported compressor

* [7zip](https://www.7-zip.org/)