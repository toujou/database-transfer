# TYPO3 Database Transfer

Enables you to transfer parts of the TYPO3 database to another database. This database can then be used either on its own as new TYPO3 instance or used to import data to another TYPO3 instance. Especially SQLite is useful as a transport vehicle for this.

> [!WARNING]
> This package is still in early development und mostly unfinished. It's definitely not ready for production.

## Use Cases (not exhaustive, to be continued)

* Separate a site from a multisite instance
* Join a site from a multisite instance
* Duplicate a site within a multisite instance
* Export a site to another datacenter (e.g. in China behind the Great Firewall)
* Synchronize (import and continously update) a shared site between a master and some instances
* ...

## Installation

Require and install the plugin

    $ composer require toujou/database-transfer
    $ vendor/bin/typo3cms extension:install toujou_database_transfer

## Usage (Console Command)

Pre Export (on the instance from which you want to import the data):
```shell
vendor/bin/typo3 referenceindex:update
```


```shell
vendor/bin/typo3 database:transfer

Description:
  Transfers data from one database to another

Usage:
  database:transfer [options] [--] <dsn> <import-source-name>

Arguments:
  dsn                                      The source database connection string (DSN) from which data will be imported. e.g. "mysqli://db:db@db:3306/main"
  import-source-name                       Identifier of the import source. This name is used across all imports to identify and group data from the same source system. It also maintains the mapping between source and target records and influences update behavior. e.g. "main"

Options:
      --all                                Import all pages
      --pid[=PID]                          Root pages of the source page tree to import. Format: "{pid}:{level}" (multiple values allowed)
      --include-table[=INCLUDE-TABLE]      Include all records of this table. Examples: "ALL", "tt_content", "sys_file_reference", etc. [default: ["ALL"]] (multiple values allowed)
      --exclude-table[=EXCLUDE-TABLE]      Exclude all records of this table. Examples: "tt_content", "sys_file_reference", etc. [default: ["tx_migrations_domain_model_migrationstatus","tx_yoastseo_prominent_word","tx_sentmail_mail"]] (multiple values allowed)
      --include-record[=INCLUDE-RECORD]    Include this specific record. Pattern is "{table}:{record}". Examples: "tt_content:12", etc. (multiple values allowed)
      --exclude-record[=EXCLUDE-RECORD]    Exclude this specific record. Pattern is "{table}:{record}". Examples: "fe_users:3", etc. (multiple values allowed)
      --include-related[=INCLUDE-RELATED]  Include record relations to this table, including the related record. Examples: "ALL", "sys_category", etc. [default: ["ALL"]] (multiple values allowed)
      --include-static[=INCLUDE-STATIC]    Include record relations to this table, excluding the related record. Examples: "ALL", "be_users", etc. (multiple values allowed)
      --delta-update                       Only update records that have changed by comparing their timestamps (if available).
      --dry-run                            Performs a dry run of the operation, calculating and outputting the changes without applying them to any records.
  -h, --help                               Display help for the given command. When no command is given display help for the list command
  -q, --quiet                              Do not output any message
  -V, --version                            Display this application version
      --ansi|--no-ansi                     Force (or disable --no-ansi) ANSI output
  -n, --no-interaction                     Do not ask any interactive question
  -v|vv|vvv, --verbose                     Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

```

## Development

Install php dependencies using composer:

    $ composer install

#### [PHPUnit](https://phpunit.de) Unit tests

    $ etc/scripts/runTests.sh

#### [PHPUnit](https://phpunit.de) Functional tests

    $ etc/scripts/runTests.sh -s functional
