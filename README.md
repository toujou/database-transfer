# TYPO3 Database Transfer

Enables you to transfer parts of the TYPO3 database to another database. This database can then be used either on its own as new TYPO3 instance or used to import data to another TYPO3 instance. Especially SQLite is useful as a transport vehicle for this.

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

## Development

Install php dependencies using composer:

    $ composer install

#### [PHPUnit](https://phpunit.de) Unit tests

    $ etc/scripts/runTests.sh

#### [PHPUnit](https://phpunit.de) Functional tests

    $ etc/scripts/runTests.sh -s functional


#### [Easy-Coding-Standard](https://github.com/Symplify/EasyCodingStandard)

Check coding standard violations

    $ etc/scripts/checkCodingStandards.sh

Fix coding standard violations automatically

    $ etc/scripts/checkCodingStandards.sh --fix
