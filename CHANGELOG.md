# Changelog

## 0.1.0 (2026-07-31)


### ⚠ BREAKING CHANGES

* the root namespace Bambamboole\GaebParser is now Bambamboole\Gaeb; the composer package is bambamboole/gaeb-php.

### Features

* add GaebDocument with runtime schema validation ([dd8b8bf](https://github.com/bambamboole/gaeb-php/commit/dd8b8bfb1978df433be0756f0b73ae07ea70534b))
* add schema-valid X86 contract fixture and coverage ([a28aa53](https://github.com/bambamboole/gaeb-php/commit/a28aa53df40c4d1696d7b785ebbe1c577de09d4e))
* add schema-valid X89 fixture and per-family XSD resolution ([2dd5c2d](https://github.com/bambamboole/gaeb-php/commit/2dd5c2dea04925e6ac8a17cf276d80f76995adae))
* derive X89 invoices from X86 contracts via createInvoice ([4aa2b4b](https://github.com/bambamboole/gaeb-php/commit/4aa2b4b1c054afe88803168c8a02063f5eecf86e))
* exact decimal money computation via brick/math ([eeca185](https://github.com/bambamboole/gaeb-php/commit/eeca1858705574127cff8122166755ba6a85920e))
* make the ProgSystem stamp configurable on Bid and Invoice ([6e6df2d](https://github.com/bambamboole/gaeb-php/commit/6e6df2d7d4a02f15f9ea893dc407b57eff75339c))
* parse AwardInfo award data into GaebFile ([37f2680](https://github.com/bambamboole/gaeb-php/commit/37f2680ede1ed2c26c31a38297842d5dce46ebb8))
* parse BoQ category tree and items ([75d9d34](https://github.com/bambamboole/gaeb-php/commit/75d9d34f8a31627c61efe93b519c5b1bd85dda6d))
* parse GAEB file and project metadata with lenient error handling ([be8e25e](https://github.com/bambamboole/gaeb-php/commit/be8e25e1c76a74efc812fc76f3c40d1adac879d7))
* parse item classification flags and totals breakdown ([ae301a3](https://github.com/bambamboole/gaeb-php/commit/ae301a3a60608837a05b5d83df9fc50781af8a33))
* parse OWN/CTR parties into GaebFile ([7e21ac0](https://github.com/bambamboole/gaeb-php/commit/7e21ac08ff614651c43f09a73db3d9e284844ee8))
* parse text complements, bidder comments and sub-descriptions ([16db5d8](https://github.com/bambamboole/gaeb-php/commit/16db5d8ef47491ff6c8a6ef1d86622f00b62d7bd))
* parse X89 invoices into the read model ([b2acfda](https://github.com/bambamboole/gaeb-php/commit/b2acfda25e8c197eb8bed2daf1e4f8736527ff41))
* write x84 bids from received tenders ([441d92a](https://github.com/bambamboole/gaeb-php/commit/441d92ab95ad1530771d9cdb7f01b3f659d5a812))
* X86 contract read (parties + award data) ([e2f81e9](https://github.com/bambamboole/gaeb-php/commit/e2f81e96afc7dcf0dc6f991cc73addbb05d2a7c1))
* X89 invoicing (write + read) + X86 domain docs ([647c46a](https://github.com/bambamboole/gaeb-php/commit/647c46ad4b9eef1f8e7ad20a8e2fc4e27dcd805a))


### Bug Fixes

* address bid transform review findings ([3722a87](https://github.com/bambamboole/gaeb-php/commit/3722a87d300b86c0bbeff067119d1604f5825235))
* address final review findings ([58039b9](https://github.com/bambamboole/gaeb-php/commit/58039b90163f4e06f8219a08c9a6331e0f95aea1))
* address final review findings ([03b422e](https://github.com/bambamboole/gaeb-php/commit/03b422e3cc4ad103924c786c8605de473f328620))
* address final review findings ([a77c829](https://github.com/bambamboole/gaeb-php/commit/a77c8290aa0f2dd19856479f66eeec6fc2294726))
* address final review findings ([da9da22](https://github.com/bambamboole/gaeb-php/commit/da9da221718f0d8a9bf4c3020ec9179a553cc58a))
* check file readability before reading to suppress PHPUnit warnings ([7484fe1](https://github.com/bambamboole/gaeb-php/commit/7484fe1d03694ef237e02f38343d1c8574c9aec8))
* composer package is bambamboole/gaeb ([6095679](https://github.com/bambamboole/gaeb-php/commit/60956799379455e348c0f6311aed9f92b944b7f2))
* composer package is bambamboole/gaeb, gaeb-php is only the repo ([e7823b5](https://github.com/bambamboole/gaeb-php/commit/e7823b5e39dca69ac58912515a0b59c21134ef48))
* harden bid writing money path and strict-write checks ([af5395b](https://github.com/bambamboole/gaeb-php/commit/af5395b44f0cdfbe6b15996a09a3a5cb79d3e928))
* require source QU for billed invoice items ([5d9440e](https://github.com/bambamboole/gaeb-php/commit/5d9440e369d04abb738c37baa150d80c8b3aee93))
* wrap qty parse errors in the strict-write contract ([45318fa](https://github.com/bambamboole/gaeb-php/commit/45318fa0589028167290df6e750c6b93f80fca94))


### Miscellaneous Chores

* add release-please workflow ([0eec623](https://github.com/bambamboole/gaeb-php/commit/0eec623caae4b185375de26cdf2d3835279ac3c7))


### Code Refactoring

* rename package to bambamboole/gaeb-php, namespace to Bambamboole\Gaeb ([851618a](https://github.com/bambamboole/gaeb-php/commit/851618ac0b952da221cc172eb5c6d1b133178587))
