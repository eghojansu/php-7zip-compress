# PHP 7zip Compress Tool

Compress with [PHP][1] and [7zip][2]

## Usage

```php
php bin/compress.php [path to compress]
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
    "excludes": ["vendor", "var", "node_modules"],
    "ignores": ["/.*\\.(?:7z|bak|db|env|gz|zip|rar)$/i", "/\\.git\/.*/i"],
}
```

---

[1]: https://php.net/ (7-zip)
[2]: https://www.7-zip.org/ (7-zip)