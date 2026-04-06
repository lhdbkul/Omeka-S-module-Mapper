<?php declare(strict_types=1);

namespace Mapper;

use Common\Stdlib\PsrMessage;
use Omeka\Module\Exception\ModuleCannotInstallException;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Laminas\Log\Logger $logger
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Laminas\Mvc\I18n\Translator $translator
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Settings\SiteSettings $siteSettings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $services->get('ViewHelperManager')->get('url');
$api = $plugins->get('api');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

$config = $services->get('Config');
$basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

if (PHP_VERSION_ID < 80100) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s requires PHP %2$s or later.'), // @translate
        'Mapper', '8.1'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.84')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.84'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if (version_compare((string) $oldVersion, '3.4.2', '<')) {
    // Upgrade mappings to new format:
    // - [default] section is deprecated, merge into [maps]
    // - [mapping] section was a bug (never parsed), rename to [maps]
    // - Normalize duplicate field syntax in default maps
    $sql = 'SELECT id, mapping FROM mapper';
    $mappings = $connection->executeQuery($sql)->fetchAllAssociative();

    $updated = 0;
    foreach ($mappings as $row) {
        $mapping = $row['mapping'] ?? '';
        $newMapping = $mapping;

        // Step 1: Fix [mapping] section (was never parsed - bug).
        // Must be done BEFORE merging [default] to handle [default]...[mapping] pattern.
        if (strpos($newMapping, '[mapping]') !== false) {
            $newMapping = str_replace('[mapping]', '[maps]', $newMapping);
        }

        // Step 2: Merge [default] section into [maps].
        if (strpos($newMapping, '[default]') !== false) {
            // [default]...content...[maps] -> [maps] with merged content
            $newMapping = preg_replace(
                '/\[default\]\s*\n((?:(?!\[)[^\n]*\n)*)\s*\[maps\]/s',
                "[maps]\n\n; Default maps (no source path) - applied to all resources.\n$1\n; Source maps - extracted from data.",
                $newMapping
            );
        }

        // Step 3: Fix default maps with duplicate field names.
        // Convert "term:name = term:name ... ~ pattern" to "~ = term:name ... ~ pattern"
        // This is needed when the same term appears on both sides with a pattern.
        $newMapping = preg_replace(
            '/^(\s*)(\w+:\w+)(\s*)=(\s*)\2(\s+[^\n]*~[^\n]*)$/m',
            '$1~$3=$4$2$5',
            $newMapping
        );

        // Step 4: Convert quoted raw values to recommended format.
        // Convert 'field = "value"' to '~ = field ~ {{ "value" }}'
        // This normalizes default maps to use the explicit ~ syntax.
        $newMapping = preg_replace(
            '/^(\s*)([\w:]+)(\s*)=(\s*)"([^"]+)"(\s*)$/m',
            '$1~$3=$4$2 ~ {{ "$5" }}$6',
            $newMapping
        );
        $newMapping = preg_replace(
            "/^(\s*)([\\w:]+)(\\s*)=(\\s*)'([^']+)'(\\s*)$/m",
            '$1~$3=$4$2 ~ {{ "$5" }}$6',
            $newMapping
        );

        if ($newMapping !== $mapping) {
            $connection->executeStatement(
                'UPDATE mapper SET mapping = ? WHERE id = ?',
                [$newMapping, $row['id']]
            );
            $updated++;
        }
    }

    if ($updated > 0) {
        $message = new PsrMessage(
            'Upgraded {count} mapping(s): fixed [mapping]/[default] sections and normalized syntax.', // @translate
            ['count' => $updated]
        );
        $messenger->addSuccess($message);
        $logger->notice($message->getMessage(), $message->getContext());
    }

    // Update mapper inheritance paths after folder reorganization.
    // Old paths like "base/content-dm.jsdot" become "content-dm/content-dm.base.jsdot".
    // Naming convention changed from underscore to dot (e.g., ead_base → ead.base).
    // Handles all formats: INI, JSON, XML.
    $pathMappings = [
        // Content-DM (old flat → old underscore → new dot format)
        'base/content-dm.jsdot' => 'content-dm/content-dm.base.jsdot',
        'base/content-dm.jmespath' => 'content-dm/content-dm.base.jmespath',
        'base/content-dm.jsonpath' => 'content-dm/content-dm.base.jsonpath',
        'content-dm.jsdot' => 'content-dm/content-dm.base.jsdot',
        'content-dm.jmespath' => 'content-dm/content-dm.base.jmespath',
        'content-dm.jsonpath' => 'content-dm/content-dm.base.jsonpath',
        'json/content-dm.base.jsdot' => 'content-dm/content-dm.base.jsdot',
        'json/content-dm.base.jmespath' => 'content-dm/content-dm.base.jmespath',
        'json/content-dm.base.jsonpath' => 'content-dm/content-dm.base.jsonpath',
        'content-dm/content-dm_base.jsdot' => 'content-dm/content-dm.base.jsdot',
        'content-dm/content-dm_base.jmespath' => 'content-dm/content-dm.base.jmespath',
        'content-dm/content-dm_base.jsonpath' => 'content-dm/content-dm.base.jsonpath',
        // IIIF
        'base/iiif2xx.jsdot' => 'iiif/iiif2xx.base.jsdot',
        'base/iiif2xx.jmespath' => 'iiif/iiif2xx.base.jmespath',
        'base/iiif2xx.jsonpath' => 'iiif/iiif2xx.base.jsonpath',
        'iiif2xx.jsdot' => 'iiif/iiif2xx.base.jsdot',
        'iiif2xx.jmespath' => 'iiif/iiif2xx.base.jmespath',
        'iiif2xx.jsonpath' => 'iiif/iiif2xx.base.jsonpath',
        'json/iiif2xx.base.jsdot' => 'iiif/iiif2xx.base.jsdot',
        'json/iiif2xx.base.jmespath' => 'iiif/iiif2xx.base.jmespath',
        'json/iiif2xx.base.jsonpath' => 'iiif/iiif2xx.base.jsonpath',
        'json/iiif2xx.bnf.jsdot' => 'iiif/iiif2xx.bnf.jsdot',
        'json/iiif2xx.bnf.jmespath' => 'iiif/iiif2xx.bnf.jmespath',
        'json/iiif2xx.bnf.jsonpath' => 'iiif/iiif2xx.bnf.jsonpath',
        'json/iiif2xx.unistra.jsdot' => 'iiif/iiif2xx.unistra.jsdot',
        'json/iiif2xx.unistra.jmespath' => 'iiif/iiif2xx.unistra.jmespath',
        'json/iiif2xx.unistra.jsonpath' => 'iiif/iiif2xx.unistra.jsonpath',
        'iiif/iiif2xx_base.jsdot' => 'iiif/iiif2xx.base.jsdot',
        'iiif/iiif2xx_base.jmespath' => 'iiif/iiif2xx.base.jmespath',
        'iiif/iiif2xx_base.jsonpath' => 'iiif/iiif2xx.base.jsonpath',
        'iiif/iiif2xx_bnf.jsdot' => 'iiif/iiif2xx.bnf.jsdot',
        'iiif/iiif2xx_bnf.jmespath' => 'iiif/iiif2xx.bnf.jmespath',
        'iiif/iiif2xx_bnf.jsonpath' => 'iiif/iiif2xx.bnf.jsonpath',
        'iiif/iiif2xx_unistra.jsdot' => 'iiif/iiif2xx.unistra.jsdot',
        'iiif/iiif2xx_unistra.jmespath' => 'iiif/iiif2xx.unistra.jmespath',
        'iiif/iiif2xx_unistra.jsonpath' => 'iiif/iiif2xx.unistra.jsonpath',
        // File metadata (note: jsondot was a typo, now jsdot)
        'base/file.jsdot' => 'file/file.base.jsdot',
        'base/file.jsondot' => 'file/file.base.jsdot',
        'base/file.jmespath' => 'file/file.base.jmespath',
        'base/file.jsonpath' => 'file/file.base.jsonpath',
        'file.jsdot' => 'file/file.base.jsdot',
        'file.jsondot' => 'file/file.base.jsdot',
        'file.jmespath' => 'file/file.base.jmespath',
        'file.jsonpath' => 'file/file.base.jsonpath',
        'json/file.application_pdf.jsdot' => 'file/file.application_pdf.jsdot',
        'json/file.audio_mpeg.jsdot' => 'file/file.audio_mpeg.jsdot',
        'json/file.audio_wav.jsdot' => 'file/file.audio_wav.jsdot',
        'json/file.image_jpeg.jsdot' => 'file/file.image_jpeg.jsdot',
        'json/file.image_png.jsdot' => 'file/file.image_png.jsdot',
        'json/file.image_tiff.jsdot' => 'file/file.image_tiff.jsdot',
        'json/file.video_mp4.jsdot' => 'file/file.video_mp4.jsdot',
        'file/file_base.jsdot' => 'file/file.base.jsdot',
        'file/file_base.jmespath' => 'file/file.base.jmespath',
        'file/file_base.jsonpath' => 'file/file.base.jsonpath',
        // XML mappings (EAD)
        'xml/ead_to_omeka.xml' => 'ead/ead.base.xml',
        'xml/ead_presentation_to_omeka.xml' => 'ead/ead.presentation.xml',
        'xml/ead_components_to_omeka.xml' => 'ead/ead.components.xml',
        'ead/ead_base.xml' => 'ead/ead.base.xml',
        'ead/ead_presentation.xml' => 'ead/ead.presentation.xml',
        'ead/ead_components.xml' => 'ead/ead.components.xml',
        'ead/ead_tags.xml' => 'ead/ead.tags.xml',
        // XML mappings (Unimarc)
        'xml/unimarc_to_omeka.xml' => 'unimarc/unimarc.base.xml',
        'unimarc/unimarc_base.xml' => 'unimarc/unimarc.base.xml',
        // XML mappings (LIDO)
        'xml/lido_mc_to_omeka.xml' => 'lido/lido.mc.xml',
        'lido/lido_mc.xml' => 'lido/lido.mc.xml',
        // XML mappings (IdRef → RDF)
        'xml/idref_personne.xml' => 'rdf/rdf.idref_personne.xml',
        'idref/idref_personne.xml' => 'rdf/rdf.idref_personne.xml',
        // JSON mappings (Unimarc IdRef)
        'json/unimarc_idref_personne.json' => 'unimarc/unimarc.idref_personne.json',
        'json/unimarc_idref_collectivites.json' => 'unimarc/unimarc.idref_collectivites.json',
        'json/unimarc_idref_autre.json' => 'unimarc/unimarc.idref_autre.json',
        'idref/unimarc_idref_personne.json' => 'unimarc/unimarc.idref_personne.json',
        'idref/unimarc_idref_collectivites.json' => 'unimarc/unimarc.idref_collectivites.json',
        'idref/unimarc_idref_autre.json' => 'unimarc/unimarc.idref_autre.json',
        // Tables
        'json/geonames_countries.json' => 'tables/geonames.countries.json',
        'tables/geonames_countries.json' => 'tables/geonames.countries.json',
        // XSL transformations (used by XML readers)
        'xsl/identity.xslt1.xsl' => 'common/identity.xslt1.xsl',
        'xsl/identity.xslt2.xsl' => 'common/identity.xslt2.xsl',
        'xsl/identity.xslt3.xsl' => 'common/identity.xslt3.xsl',
        'xsl/ead_to_resources.xsl' => 'ead/ead_to_resources.xsl',
        'xsl/lido_to_resources.xsl' => 'lido/lido_to_resources.xsl',
        'xsl/mets_to_omeka.xsl' => 'mets/mets_to_omeka.xsl',
        'xsl/mets_exlibris_to_omeka.xsl' => 'mets/mets_exlibris_to_omeka.xsl',
        'xsl/mets_wrapped_exlibris_to_mets.xsl' => 'mets/mets_wrapped_exlibris_to_mets.xsl',
        'xsl/mods_to_omeka.xsl' => 'mods/mods_to_omeka.xsl',
        'xsl/sru.dublin-core_to_omeka.xsl' => 'sru/sru.dublin-core_to_omeka.xsl',
        'xsl/sru.dublin-core_with_file_gallica_to_omeka.xsl' => 'sru/sru.dublin-core_with_file_gallica_to_omeka.xsl',
        'xsl/sru.unimarc_to_resources.xsl' => 'unimarc/sru.unimarc_to_resources.xsl',
        'xsl/sru.unimarc_to_unimarc.xsl' => 'unimarc/sru.unimarc_to_unimarc.xsl',
    ];

    $sql = 'SELECT id, mapping FROM mapper';
    $mappings = $connection->executeQuery($sql)->fetchAllAssociative();

    $updated = 0;
    foreach ($mappings as $row) {
        $mapping = $row['mapping'] ?? '';
        $newMapping = $mapping;
        $trimmed = trim($mapping);

        // Detect format and apply appropriate replacements.
        $firstChar = mb_substr($trimmed, 0, 1);

        foreach ($pathMappings as $oldPath => $newPath) {
            if ($firstChar === '<') {
                // XML format: <mapper>oldPath</mapper> or <info><mapper>oldPath</mapper></info>
                $pattern = '/(<mapper>)' . preg_quote($oldPath, '/') . '(<\/mapper>)/';
                $replacement = '$1' . $newPath . '$2';
                $newMapping = preg_replace($pattern, $replacement, $newMapping);

                // Also handle include mapping="..." attributes
                $pattern = '/(mapping\s*=\s*["\'])' . preg_quote($oldPath, '/') . '(["\'])/';
                $replacement = '$1' . $newPath . '$2';
                $newMapping = preg_replace($pattern, $replacement, $newMapping);
            } elseif ($firstChar === '{' || $firstChar === '[') {
                // JSON format: "mapper": "oldPath"
                $pattern = '/("mapper"\s*:\s*")' . preg_quote($oldPath, '/') . '(")/';
                $replacement = '$1' . $newPath . '$2';
                $newMapping = preg_replace($pattern, $replacement, $newMapping);
            } else {
                // INI format: mapper = oldPath
                $pattern = '/^(\s*mapper\s*=\s*)' . preg_quote($oldPath, '/') . '(\.ini)?(\s*)$/m';
                $replacement = '$1' . $newPath . '$3';
                $newMapping = preg_replace($pattern, $replacement, $newMapping);
            }
        }

        if ($newMapping !== $mapping) {
            $connection->executeStatement(
                'UPDATE mapper SET mapping = ? WHERE id = ?',
                [$newMapping, $row['id']]
            );
            $updated++;
        }
    }

    if ($updated > 0) {
        $message = new PsrMessage(
            'Upgraded {count} mapping(s): updated inheritance paths after folder reorganization.', // @translate
            ['count' => $updated]
        );
        $messenger->addSuccess($message);
        $logger->notice($message->getMessage(), $message->getContext());
    }

    // Fix circular mapper references in base mappings.
    // A base mapping should not reference itself as parent mapper.
    $sql = 'SELECT id, label, mapping FROM mapper';
    $mappings = $connection->executeQuery($sql)->fetchAllAssociative();

    $fixedCircular = 0;
    foreach ($mappings as $row) {
        $mapping = $row['mapping'] ?? '';
        $newMapping = $mapping;

        // Extract the mapper value from INI format.
        if (preg_match('/^\s*mapper\s*=\s*([^\s\r\n]+)/m', $mapping, $matches)) {
            $mapperValue = trim($matches[1]);
            // Get the mapping's own reference (from label or inferred from info section).
            // Check if the mapper references itself by comparing with common base patterns.
            // A circular reference is when mapper = X and the file itself is X.base.xxx
            if (preg_match('/^([a-z\-]+)\/\1\.base\./i', $mapperValue)) {
                // This is a base config referencing itself - remove the circular reference.
                $newMapping = preg_replace(
                    '/^\s*mapper\s*=\s*[^\r\n]+/m',
                    '; Note: No parent mapper for base config - child mappings should reference this file.',
                    $newMapping
                );
            }
        }

        if ($newMapping !== $mapping) {
            $connection->executeStatement(
                'UPDATE mapper SET mapping = ? WHERE id = ?',
                [$newMapping, $row['id']]
            );
            $fixedCircular++;
        }
    }

    if ($fixedCircular > 0) {
        $message = new PsrMessage(
            'Fixed {count} mapping(s) with circular inheritance references.', // @translate
            ['count' => $fixedCircular]
        );
        $messenger->addSuccess($message);
        $logger->notice($message->getMessage(), $message->getContext());
    }

    // Migrate xslt processor setting from BulkImport to Mapper.
    $xsltProcessor = $settings->get('bulkimport_xslt_processor');
    if ($xsltProcessor) {
        $settings->set('mapper_xslt_processor', $xsltProcessor);
        $settings->delete('bulkimport_xslt_processor');
        $message = new PsrMessage(
            'Migrated xslt processor setting from BulkImport to Mapper.', // @translate
        );
        $messenger->addSuccess($message);
        $logger->notice($message->getMessage(), $message->getContext());
    }
}
