# Composer ProcessWire

Install and update ProcessWire with Composer while keeping the familiar `wire/` and `site/` webroot layout. It fits either of these setups:

- **Project root as webroot:** `composer.json`, `wire/`, `index.php` and `site/` live at the project root.
- **Separate webroot:** `composer.json` lives at the project root, while ProcessWire runs from an existing directory such as `public/`.

In both setups, Composer installs the official `processwire/processwire` package in `vendor/`. This plugin copies its `wire/` directory and `index.php` into your webroot. Composer's lock file determines which core version those copies contain.

The plugin manages **core files**, not your site. It does not replace `site/` or `.htaccess`. It does replace the entire webroot `wire/` directory and `index.php`, including any local edits to them.

## Set up a project

Allow the plugin and require it alongside ProcessWire:

```sh
composer config allow-plugins.daun/composer-processwire true
composer require processwire/processwire:^3.0.259 daun/composer-processwire
```

If ProcessWire runs from `public/`, add this to the root `composer.json` before the install:

```json
{
  "extra": {
    "processwire": {
      "webroot": "public"
    }
  }
}
```

Without that setting, the plugin uses the directory containing `composer.json`. The webroot must already exist and must be inside the project. Put `processwire/processwire` in `require`, not `require-dev`, including when your production deployment runs `composer install --no-dev`.

If you are adding the plugin to an existing site, back up or review `wire/` and `index.php` first. The first sync replaces them with files from the installed package.

## Day-to-day commands

| Task | Command |
| --- | --- |
| Update the core within your Composer constraint | `composer update processwire/processwire` |
| Check the webroot against the installed package | `composer processwire:status` |
| Replace the webroot copies on demand | `composer processwire:sync` |
| See whether a newer package is available | `composer outdated processwire/processwire` |

`processwire:status` compares the files and directories in `wire/` and checks `index.php`. It exits nonzero when it finds drift. A normal `composer install` also repairs missing or changed managed files.

You can commit `wire/` and `index.php` for deployments that do not run Composer, or generate them during deployment with `composer install`. Keep plugins enabled when building either deployment. Do not use `--no-plugins`: the plugin also prevents Composer from autoloading the vendor copy of `ProcessWire.php`, which would conflict with the webroot copy. `--no-scripts` does not disable the plugin.

The plugin does not touch `.htaccess`. If a core update changes upstream `htaccess.txt`, it prints a command you can use to compare the new rules with yours.

## Run the tests

```sh
composer install
composer test
```
