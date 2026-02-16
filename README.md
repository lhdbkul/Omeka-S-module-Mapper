Mapper (module for Omeka S)
===========================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Mapper] is a module for [Omeka S] that allows to define mapping between a
source string or record and a destination value or resource.

Default mappings are available for Unimarc, EAD, Lido (profil Ministère de la Culture)
and Mets.

This module is used in modules:
- [Advanced Resource Template] to define autofillers and autovalues,
- [CopIdRef] to create local resource from French authorities [IdRef],
- [Bulk Import] to convert any source (spreadsheet, sql, xml, etc.) into omeka
  resource,
- [Urify] to convert a name into a value suggest uri or to fill a full resource.

And many more (work in progress): [OaiPmh Harvester], [Bulk Export], etc.


Installation
------------

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

The module uses external libraries, so use the release zip to install it, or use
and init the source.

- From the zip

Download the last release [Mapper.zip] from the list of releases, and
uncompress it in the `modules` directory.

- From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `Mapper`, go to the root of the module, and run:

```sh
composer install --no-dev
```

The module uses [CodeMirror] 6 for the mapping editor with syntax highlighting
for XML, JSON, and plain text (INI). The editor is bundled as a single JavaScript
file. To rebuild it from source:

```sh
cd modules/Mapper
npm install
npm run build
```

- For test

The module includes a comprehensive test suite. Due to Laminas application state
isolation, tests must be run by suite:

```sh
# Run all suites
for suite in unit config lido iiif contentdm conversion service api controller; do
  vendor/bin/phpunit -c modules/Mapper/phpunit.xml --testsuite $suite
done

# Or run a specific suite
vendor/bin/phpunit -c modules/Mapper/phpunit.xml --testsuite config --testdox
```

Available suites: `unit`, `config`, `lido`, `iiif`, `contentdm`, `conversion`, `service`, `api`, `controller`.


Usage
-----

Copy and edit the configuration as you need.


Mapping Formats
---------------

Mappings can be written in four equivalent formats:

| Format | Extension | Best for                                      |
|--------|-----------|-----------------------------------------------|
| XML    | `.xml`    | Complex mappings, XPath queries, includes     |
| INI    | `.ini`    | Simple mappings, quick edits, readability     |
| JSON   | `.json`   | API integration, JavaScript tools             |
| PHP    | `.php`    | Programmatic generation, PHP integration      |

**All formats are interchangeable.** They are different representations of the
same mapping structure. A mapping written in INI can be converted to XML, JSON,
or PHP array without loss of functionality.

**Recommended format: XML.** The XML format offers:
- Native support for `<include>` to split large mappings
- Better handling of complex XPath expressions
- Standard validation with XML tools
- Clear structure for nested configurations

Choose INI for simple mappings or quick prototyping. Use XML for production
mappings, especially with inheritance or complex queries.


Mapping Syntax (XML Format)
---------------------------

XML is the reference format for mappings. It provides a clear, explicit structure
that maps directly to the internal representation.

### Structure

```xml
<?xml version="1.0" encoding="UTF-8"?>
<mapping>
    <info>
        <label>My Mapping</label>
        <from>xml</from>
        <to>resources</to>
        <querier>xpath</querier>
        <example>https://example.org/data/151</example>
    </info>

    <params>
        <param name="endpoint">https://example.org/api</param>
    </params>

    <maps>
        <map>
            <from xpath="//title"/>
            <to field="dcterms:title"/>
        </map>
    </maps>

    <tables>
        <table name="types">
            <entry key="a">Article</entry>
            <entry key="b">Book</entry>
        </table>
    </tables>
</mapping>
```

Only `<info>` is required. The `<params>`, `<maps>`, and `<tables>` containers
are optional.
Elements can be placed directly under `<mapping>` or grouped in containers:

```xml
<!-- With containers (better readability) -->
<mapping>
    <info>...</info>
    <params><param name="x">value</param></params>
    <maps><map>...</map></maps>
    <tables><table name="t">...</table></tables>
</mapping>

<!-- Without containers (compact) -->
<mapping>
    <info>...</info>
    <param name="x">value</param>
    <map>...</map>
    <table name="t">...</table>
</mapping>
```

### Element `<info>`

Metadata about the mapping.

| Element     | Description                                                    |
|-------------|----------------------------------------------------------------|
| `<label>`   | Display name                                                   |
| `<from>`    | Source format (documentation only)                             |
| `<to>`      | Target format (documentation only)                             |
| `<querier>` | Query type: `xpath`, `jsdot`, `jsonpath`, `jmespath`, `index`  |
| `<mapper>`  | Base mapping to inherit from                                   |
| `<example>` | Example URL or path                                            |

### Element `<maps>`

Contains `<map>` elements that define transformation rules.

#### Basic map

```xml
<map>
    <from xpath="//title"/>
    <to field="dcterms:title"/>
</map>
```

#### Map with qualifiers

```xml
<map>
    <from xpath="//description"/>
    <to field="dcterms:description" datatype="literal" language="en" visibility="public"/>
</map>
```

| Attribute    | Values                              | Description                |
|--------------|-------------------------------------|----------------------------|
| `field`      | `dcterms:title`, `foaf:name`...     | Target property (term)     |
| `datatype`   | `literal`, `uri`, `resource:item`   | Value data type            |
| `language`   | `en`, `fr`, `fra`                   | ISO language code          |
| `visibility` | `public`, `private`                 | Value visibility           |

#### Map with pattern transformation

```xml
<map>
    <from xpath="//date"/>
    <to field="dcterms:date"/>
    <mod pattern="{{ value|date('Y-m-d') }}"/>
</map>
```

The `<mod>` element supports:

| Attribute  | Description                                      |
|------------|--------------------------------------------------|
| `pattern`  | Transformation pattern with variables/filters    |
| `raw`      | Static value (no transformation)                 |
| `prepend`  | Text to add before the value                     |
| `append`   | Text to add after the value                      |

#### Default map (no source)

Maps without `<from>` apply to all records with static or combined values:

```xml
<!-- Static value -->
<map>
    <to field="dcterms:type"/>
    <mod raw="Book"/>
</map>

<!-- Combining source fields -->
<map>
    <to field="dcterms:contributor"/>
    <mod pattern="{firstName} {lastName}"/>
</map>
```

#### Include another mapping

```xml
<include mapping="base_mapping.xml"/>
```

### Complete example

```xml
<?xml version="1.0" encoding="UTF-8"?>
<mapping>
    <info>
        <label>LIDO to Omeka</label>
        <from>xml</from>
        <to>resources</to>
        <querier>xpath</querier>
    </info>

    <include mapping="lido/lido.base.xml"/>

    <maps>
        <!-- Simple extraction -->
        <map>
            <from xpath="//lido:titleSet/lido:appellationValue"/>
            <to field="dcterms:title"/>
        </map>

        <!-- With language -->
        <map>
            <from xpath="//lido:descriptiveNoteValue[@xml:lang='fr']"/>
            <to field="dcterms:description" language="fr"/>
        </map>

        <!-- With transformation -->
        <map>
            <from xpath="//lido:eventDate/lido:displayDate"/>
            <to field="dcterms:date" datatype="numeric:timestamp"/>
            <mod pattern="{{ value|date('Y-m-d') }}"/>
        </map>

        <!-- Combining fields -->
        <map>
            <to field="geo:coordinates"/>
            <mod pattern="{//lido:gml/lido:lat},{//lido:gml/lido:lng}"/>
        </map>

        <!-- Static value -->
        <map>
            <to field="dcterms:type"/>
            <mod raw="PhysicalObject"/>
        </map>
    </maps>
</mapping>
```


Mapping Syntax (INI Format)
---------------------------

The INI format is a **compact shorthand** for the same mapping structure. It is
particularly useful for:

- Spreadsheet column headers (e.g., `dcterms:title ^^literal @en`)
- Quick configuration and prototyping
- Simple mappings without includes

**INI and XML are equivalent.** Any INI mapping can be written in XML and vice versa.

### Correspondence XML / INI

| XML                                                  | INI                                       |
|------------------------------------------------------|-------------------------------------------|
| `<from xpath="//title"/><to field="dcterms:title"/>` | `//title = dcterms:title`                 |
| `<to field="..." datatype="uri"/>`                   | `... = dcterms:source ^^uri`              |
| `<to field="..." language="fr"/>`                    | `... = dcterms:title @fr`                 |
| `<to field="..." visibility="private"/>`             | `... = dcterms:rights §private`           |
| `<mod pattern="{{ value\|upper }}"/>`                | `... = dcterms:title ~ {{ value\|upper }}`|
| `<mod raw="Static"/>`                                | `~ = dcterms:type ~ Static`               |
| `<mod pattern="{a} {b}"/>`                           | `~ = dcterms:name ~ {a} {b}`              |

### File structure

```ini
[info]
label   = My Mapping
querier = jsdot

[params]
endpoint = https://example.org/api

[maps]
title = dcterms:title
date  = dcterms:date ~ {{ value|date('Y-m-d') }}

[tables]
types.a = Article
types.b = Book
```

**Note:** The default section is `[maps]`. When no section header is present,
lines are treated as maps. This allows minimal mappings:

```ini
; Minimal mapping (equivalent to [maps] section)
title = dcterms:title
creator = dcterms:creator
description = dcterms:description @fr
```

### Compact syntax

```
source = destination ^^datatype @language §visibility ~ pattern
```

| Element     | Symbol | Example                | Description                    |
|-------------|--------|------------------------|--------------------------------|
| Destination | -      | `dcterms:title`        | Target property (term)         |
| Datatype    | `^^`   | `^^uri`, `^^literal`   | Data type (can have multiple)  |
| Language    | `@`    | `@en`, `@fra`          | ISO language code              |
| Visibility  | `§`    | `§public`, `§private`  | Value visibility               |
| Pattern     | `~`    | `~ {{ value\|upper }}` | Value transformation           |

### Examples

```ini
[maps]
; === Simple extraction ===
title                   = dcterms:title
metadata.creator        = dcterms:creator

; === With qualifiers ===
description             = dcterms:description @en
rights                  = dcterms:rights §private
license                 = dcterms:license ^^uri ^^literal
type                    = dcterms:type ^^customvocab:"My Types"

; === With pattern transformation ===
date                    = dcterms:date ~ {{ value|date('Y-m-d') }}
price                   = schema:price ~ {{ value }} EUR

; === Default maps (no source, static value) ===
~                       = dcterms:type ~ Book
~                       = dcterms:license ~ Public Domain

; === Combining source fields ===
~                       = dcterms:contributor ~ {firstName} {lastName}
~                       = geo:coordinates ~ {latitude},{longitude}
```

### Patterns and Variables

Patterns use a template syntax for value transformation.

#### Variable Types

| Syntax             | Type         | Description                          |
|--------------------|--------------|--------------------------------------|
| `{key}`            | Substitution | Simple replacement from source data  |
| `{{ variable }}`   | Variable     | Context variable access              |
| `{{ var\|filter }}`| Filter       | Variable with transformation         |

**Syntax Distinction**: The two brace syntaxes serve different purposes:

| Syntax          | Purpose               | Filters | Example                           |
|-----------------|-----------------------|---------|-----------------------------------|
| `{path}`        | Source data fields    | No      | `{firstName}`, `{metadata.date}`  |
| `{{ variable }}`| Context variables     | Yes     | `{{ value\|upper }}`, `{{ url }}` |

- **`{path}`**: PSR-3 style substitution. Access fields from the source data (JSON, XML).
  Used to combine multiple source values. No filter support.
- **`{{ variable }}`**: Twig-like syntax. Access predefined context variables (`value`, `url`,
  `url_resource`, etc.). Supports filters like `|upper`, `|trim`, `|date('Y-m-d')`.

#### Combining Multiple Source Values

Use `{path}` substitutions to combine multiple source fields into a single value:

```ini
[maps]
; Combine first name and last name
~ = dcterms:contributor ~ {firstName} {lastName}

; Create geographic coordinates from separate fields
~ = geo:coordinates ~ {latitude},{longitude}

; Build a full address
~ = schema:address ~ {street}, {city} {postalCode}

; Combine with literal text
~ = dcterms:identifier ~ ID-{id}-{year}
```

**Note**: When combining source values, use "default maps" (with `~` as source) so that
all source fields are available for substitution. In regular maps (with a source path),
only `{{ value }}` contains the extracted value.

**Handling missing values:**

| Source data                              | Pattern                      | Result       |
|------------------------------------------|------------------------------|--------------|
| `{firstName: "John", lastName: "Doe"}`   | `{firstName} {lastName}`     | `John Doe`   |
| `{firstName: "John"}`                    | `{firstName} {lastName}`     | `John`       |
| `{lastName: "Doe"}`                      | `{firstName} {lastName}`     | `Doe`        |
| `{}`                                     | `{firstName} {lastName}`     | *(skipped)*  |

- Results are automatically **trimmed** to remove leading/trailing whitespace from missing values.
- If **all** source fields are missing, the value is **skipped** entirely (not created).

#### Available Variables

| Variable           | When Available | Description                          |
|--------------------|----------------|--------------------------------------|
| `{{ url }}`        | At init        | Source URL                           |
| `{{ filename }}`   | At init        | Source filename                      |
| `{{ endpoint }}`   | After params   | Calculated from params               |
| `{{ page }}`       | During process | Current pagination page              |
| `{{ value }}`      | During process | Current extracted value              |
| `{{ url_resource }}`| During process| URL of current resource              |
| `{key}`            | During process | Value from source data               |

#### Common Filters

| Filter      | Example                          | Result                   |
|-------------|----------------------------------|--------------------------|
| `upper`     | `{{ value\|upper }}`             | `HELLO`                  |
| `lower`     | `{{ value\|lower }}`             | `hello`                  |
| `trim`      | `{{ value\|trim }}`              | Remove whitespace        |
| `date`      | `{{ value\|date('Y-m-d') }}`     | `2024-01-15`             |
| `split`     | `{{ url\|split('/', -1)\|first }}`| First part before `/`   |
| `first`     | `{{ value\|first }}`             | First element/character  |
| `last`      | `{{ value\|last }}`              | Last element/character   |
| `table`     | `{{ value\|table('types') }}`    | Lookup in table          |

### Section [tables]

Conversion tables for transforming codes to labels.

```ini
[tables]
gender.f = Female
gender.m = Male
gender.o = Other

status.1 = Active
status.2 = Inactive
```

Usage in a map:

```ini
//gender = schema:gender ~ {{ value|table('gender') }}
```

### Query Types (Queriers)

The `querier` in `[info]` determines how source paths are interpreted.

| Querier    | Format       | Data | Example Path                |
|------------|--------------|------|-----------------------------|
| `xpath`    | XPath 1.0    | XML  | `//title`, `/record/@id`    |
| `jsdot`    | Dot notation | JSON | `title`, `metadata.creator` |
| `jsonpath` | JSONPath     | JSON | `$.title`, `$..name`        |
| `jmespath` | JMESPath     | JSON | `items[0].name`             |
| `index`    | Direct key   | Array| `title`                     |

#### JSON Queriers Comparison

| Criteria     | jsdot              | jsonpath                  | jmespath                |
|--------------|--------------------|---------------------------|-------------------------|
| Syntax       | `metadata.title`   | `$.metadata.title`        | `metadata.title`        |
| Complexity   | Very simple        | Medium                    | Advanced                |
| Performance  | Fast (native)      | Medium                    | Slower                  |
| Dependency   | None               | Library required          | Library required        |
| Filters      | No                 | `$..book[?(@.price<10)]`  | `items[?price<\`10\`]`  |
| Arrays       | `items.0.name`     | `$.items[0].name`         | `items[0].name`         |

**Recommendations:**

- **jsdot** (default): Best choice for most cases. No external dependency,
  intuitive JavaScript-like syntax, performant. Sufficient for 90% of mappings.
- **jsonpath**: When you need recursive search (`$..`) or conditional filters
  `[?(@.type=='book')]`.
- **jmespath**: For complex transformations with projections `items[*].name` or
  built-in functions `length()`, `sort()`, `max()`.

### Mapping Inheritance

A mapping can inherit from a base mapping using the `mapper` key:

```ini
[info]
label  = My Custom Mapping
mapper = content-dm/content-dm.base.jsdot

[maps]
; Add or override maps from base mapping
custom_field = dcterms:subject
```

The base mapping is loaded from `data/mapping/` and merged with the current
mapping. Current values take priority over base values.


Available Mappings
------------------

The module includes mappings organized by source type in `data/mapping/`:

| Folder       | Description                                | Format   |
|--------------|--------------------------------------------|----------|
| `content-dm/`| CONTENTdm digital collections              | INI      |
| `ead/`       | Encoded Archival Description               | XML, XSL |
| `file/`      | Image/audio/video file metadata            | INI      |
| `idref/`     | French authorities (IdRef)                 | XML/JSON |
| `iiif/`      | IIIF manifests (v2)                        | INI      |
| `lido/`      | Museum collections (LIDO)                  | XML, XSL |
| `mets/`      | METS transformations                       | XSL      |
| `mods/`      | MODS transformations                       | XSL      |
| `sru/`       | SRU Dublin Core transformations            | XSL      |
| `tables/`    | Conversion tables (e.g., country codes)    | JSON     |
| `unimarc/`   | Library records (Unimarc)                  | XML, XSL |
| `common/`    | Shared utilities (identity transforms)     | XSL      |

Each folder contains a base mapping (`*.base.*`) and optional variants for
specific sources (e.g., `iiif2xx.bnf.jsdot.ini` for BnF IIIF manifests).


Pre-processing
--------------

Mappings can specify preprocessing transformations to apply before mapping.
The `preprocess` element (repeatable) specifies transformation files to apply
in sequence. The transformation type is determined by file extension.

### Supported types

| Extension | Type | Engine                    |
|-----------|------|---------------------------|
| `.xsl`    | XSLT | PHP XSL extension (1.0)   |
| `.xslt`   | XSLT | PHP XSL extension (1.0)   |
| `.jq`     | JQ   | *(future)*                |

### Usage in mappings

**XML:**
```xml
<mapping>
    <info>
        <label>EAD to Omeka</label>
        <preprocess>ead_to_resources.xsl</preprocess>
        <preprocess>simplify_structure.xsl</preprocess>
    </info>
    ...
</mapping>
```

**INI:**
```ini
[info]
label = EAD to Omeka
preprocess[] = ead_to_resources.xsl
preprocess[] = simplify_structure.xsl
```

**JSON/PHP:**
```json
{
    "info": {
        "label": "EAD to Omeka",
        "preprocess": ["ead_to_resources.xsl", "simplify_structure.xsl"]
    }
}
```

### Purpose

Preprocessing transformations serve three main purposes:

1. **Split source files into individual records**
   Large XML files (EAD finding aids, UNIMARC exports) often contain multiple
   records. XSL splits them into one XML with multiple record elements.

2. **Filter or simplify the structure**
   Remove unnecessary elements, flatten deep hierarchies, or extract only
   relevant parts of the source document.

3. **Convert to a supported structure**
   Transform proprietary or complex formats into a simpler structure that
   existing mappings can handle directly.

### Transformation sources

Preprocess references can be:

| Format              | Example                          | Description                    |
|---------------------|----------------------------------|--------------------------------|
| Simple filename     | `transform.xsl`                  | Searched in context/common/base|
| Database (by ID)    | `mapping:5`                      | Stored in database (editable)  |
| Database (by label) | `mapping:My Transform`           | By label (portable)            |
| Module file         | `module:ead/transform.xsl`       | Module's data/mapping/         |
| User file           | `user:custom.xsl`                | User's files/mapping/          |
| Absolute path       | `/path/to/transform.xsl`         | Absolute filesystem path       |

**Recommended:** Use `mapping:label` instead of `mapping:id` for portability
between installations.

### File resolution

For simple filenames (without prefix), files are resolved in this order:

1. **Absolute path** - If the path starts with `/`
2. **Context folder** - Same folder as the calling mapping (e.g., `ead/`)
3. **`common/` folder** - Shared transformations in `data/mapping/common/`
4. **Base path** - Directly in `data/mapping/`

This allows mappings to reference transformations by simple filename when they're
in the same folder, while shared utilities go in `common/`.

### Database storage

Transformation files (XSL, JQ) can be edited in the admin interface and stored
in the database, just like mappings. This allows:

- Customizing transformations without modifying module files
- Version control through database backups
- Sharing transformations between installations

To use a database-stored transformation, reference it by ID: `mapping:5`

### Available transformations

| Folder     | File                                    | Description                              |
|------------|-----------------------------------------|------------------------------------------|
| `common/`  | `identity.xslt*.xsl`                    | Identity transforms (XSLT 1.0, 2.0, 3.0) |
| `ead/`     | `ead_to_resources.xsl`                  | Splits EAD into archival components      |
| `ead/`     | `ead.tags.xml`                          | EAD tag definitions                      |
| `lido/`    | `lido_to_resources.xsl`                 | Splits LIDO into museum objects          |
| `mets/`    | `mets_to_omeka.xsl`                     | Converts METS to Omeka structure         |
| `mets/`    | `mets_exlibris_to_omeka.xsl`            | Converts Ex Libris METS variant          |
| `mets/`    | `mets_wrapped_exlibris_to_mets.xsl`     | Unwraps Ex Libris wrapped METS           |
| `mods/`    | `mods_to_omeka.xsl`                     | Converts MODS to Omeka structure         |
| `sru/`     | `sru.dublin-core_to_omeka.xsl`          | Converts SRU Dublin Core response        |
| `sru/`     | `sru.dublin-core_with_file_gallica_to_omeka.xsl` | SRU DC with Gallica files       |
| `unimarc/` | `sru.unimarc_to_resources.xsl`          | Splits SRU UNIMARC into records          |
| `unimarc/` | `sru.unimarc_mef_to_omeka.xsl`          | MEF variant for UNIMARC                  |

### Programmatic usage

The `Preprocessor` service can be used directly:

```php
$preprocessor = $services->get(\Mapper\Stdlib\Preprocessor::class);

// With context (ead/ folder), searches: ead/ → common/ → data/mapping/
$transformedContent = $preprocessor->process($xmlContent, ['ead_to_resources.xsl'], [], 'ead');

// Without context, searches: common/ → data/mapping/
$transformedContent = $preprocessor->process($xmlContent, ['mets_to_omeka.xsl']);
```


TODO
----

From [Advanced Resource Template]:
- [ ] Include all suggesters from module Value Suggest.
- [ ] Take care of language with max values.
- [ ] Improve performance of the autofiller.
- [ ] Create a form element for the autofiller or simple mapping.

From [Bulk Import]:
- [ ] Clarify usage of `extractSubValue()` and `convertToString()` methods added for JsonReader pagination support. Check if they should be more generic or integrated into existing methods.
- [ ] Clarify or document the variables system (`setVariables()`, `setVariable()`) and their usage in pattern replacement.
- [ ] See todo in code.
- [ ] Add more tests.
- [ ] Extract list of metadata names/fields from source and output it to help building mapping.
- [ ] Show details for mappings: add list of used configuration as parent/child.
- [ ] Add automatic determination of the source format (xml, json, etc.).
- [ ] Replace internal jsdot by RoNoLo/json-query or binary-cube/dot-array or jasny/dotkey? Probably useless.
- [ ] Compile jmespath for better performance.
- [ ] Support value annotations in mapping output.
- [ ] Normalize config of metadata extraction with metamapper.
- [ ] Add automatic mapping for images/audio/video with xmp metadata extraction.

From [CopIdRef]:
- [ ] Modernize js (promise).
- [ ] Fill a new item (cf. module Advanced Resource Template).
- [ ] Implement the simplified mapping from the module Advanced Resource Template.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.

```sh
# database dump example
mysqldump -u omeka -p omeka | gzip > "omeka.$(date +%Y%m%d_%H%M%S).sql.gz"
```


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

- Copyright Daniel Berthereau, 2012-2026 (see [Daniel-KM] on GitLab)
- Copyright 2011-2026, Marijn Haverbeke & alii (library [CodeMirror], [MIT] license)

This module is a merge and improvement of previous modules [Advanced Resource Template],
[CopIdRef], [Bulk Import] and various old scripts.


The merge of modules was implemented for the module [Urify] designed for the
[digital library Manioc] of the [Université des Antilles et de la Guyane].

[Mapper]: https://gitlab.com/Daniel-KM/Omeka-S-module-Mapper
[Omeka S]: https://omeka.org/s
[Advanced Resource Template]: https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedResourceTemplate
[Bulk Export]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkExport
[Bulk Import]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport
[CopIdRef]: https://gitlab.com/Daniel-KM/Omeka-S-module-CopIdRef
[Urify]: https://gitlab.com/Daniel-KM/Omeka-S-module-Urify
[OaiPmh Harvester]: https://gitlab.com/Daniel-KM/Omeka-S-module-OaiPmhHarvester
[installing a module]: https://omeka.org/s/docs/user-manual/modules/
[Mapper.zip]: https://github.com/Daniel-KM/Omeka-S-module-Mapper/releases
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Mapper/issues
[CodeMirror]: https://codemirror.net
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[digital library Manioc]: http://www.manioc.org
[Université des Antilles et de la Guyane]: http://www.univ-ag.fr
[GitLab]: https://gitlab.com/Daniel-KM
[MIT]: https://opensource.org/licenses/MIT
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
