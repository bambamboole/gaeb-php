# Changelog

## [0.2.0](https://github.com/bambamboole/gaeb-php/compare/v0.1.0...v0.2.0) (2026-07-31)


### ⚠ BREAKING CHANGES

* GaebParser::fromFile()/parseFile() and
* read-model numeric properties are BigDecimal objects, Payment money fields take BigDecimal, and jsonSerialize() emits numeric values as strings.
* Dto\Contractor is gone (Bid takes Dto\Party) and

### Features

* decimal-exact read model via BigDecimal ([b4d320b](https://github.com/bambamboole/gaeb-php/commit/b4d320bcb4a34e6f8feb0dea5dba4cd7d6d5cabc))
* enable X87 + X80 phases with fixtures and X86→X87 confirmation ([2ed0622](https://github.com/bambamboole/gaeb-php/commit/2ed062200e615b936a114126341a5f1e466a9f36))
* enable X87 + X80 phases with fixtures and X86→X87 confirmation ([fb51a62](https://github.com/bambamboole/gaeb-php/commit/fb51a628668ba6a0f852d99fade201981f2df517))
* LV read-model completeness wave and not-offered bid positions ([786d78a](https://github.com/bambamboole/gaeb-php/commit/786d78a3b66793924bc7bcf5acc8a835b00abc01))
* LV read-model completeness wave and not-offered bid positions ([96ad8a9](https://github.com/bambamboole/gaeb-php/commit/96ad8a96c22b6c64efb9b582085cb39c27e0ae9b))
* Nachtrag (change order) support ([ed09a10](https://github.com/bambamboole/gaeb-php/commit/ed09a108c400788798df098c3ca6f7459e27e806))
* parse Nachtrag data and guard X89 billing on approval ([8c62c31](https://github.com/bambamboole/gaeb-php/commit/8c62c31729451feaad136b97f2de5949185c8283))
* X89B supporting document (Rechnungsbegründende Unterlage) ([80534e8](https://github.com/bambamboole/gaeb-php/commit/80534e81ecd1cd7c0ad13dd8919c78745de1ab0a))
* X89B supporting document (Rechnungsbegründende Unterlage) ([5b21440](https://github.com/bambamboole/gaeb-php/commit/5b21440f641e22513c82e2a83702d2e768da8da3))


### Refactoring

* apply over-engineering audit cuts ([3d07414](https://github.com/bambamboole/gaeb-php/commit/3d07414d335a81f47fa7e6977762d1d955f54f5a))
* remove file I/O from the library API ([8b37503](https://github.com/bambamboole/gaeb-php/commit/8b37503035405d50bbdac25aefdd2b8208712a24))

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
