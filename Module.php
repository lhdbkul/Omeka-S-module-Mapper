<?php declare(strict_types=1);

namespace Mapper;

if (!class_exists(\Common\TraitModule::class, false)) {
    require_once file_exists(dirname(__DIR__) . '/Common/src/TraitModule.php')
        ? dirname(__DIR__) . '/Common/src/TraitModule.php'
        : dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\TraitModule;
use Omeka\Module\AbstractModule;

/**
 * Mapper.
 *
 * A tool to convert values from a source to values in Omeka resources.
 *
 * @copyright Daniel Berthereau, 2013-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');

        $errors = [];

        if (PHP_VERSION_ID < 80100) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s requires PHP %2$s or later.'), // @translate
                'Mapper', '8.1'
            );
            $errors[] = (string) $message;
        }

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.84')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.84'
            );
            $errors[] = (string) $message;
        }

        if ($errors) {
            throw new \Omeka\Module\Exception\ModuleCannotInstallException(implode("\n", $errors));
        }
    }

    protected function postInstall(): void
    {
        $this->migrateFromBulkImport();
        $this->autoConfigureXsltProcessor();
    }

    /**
     * Log the result of xslt processor auto-detection at install time.
     *
     * Mode defaults to "auto", so the detected command is used at runtime by
     * ProcessXsltFactory. This method only reports the detection in the log so
     * the admin knows whether xslt 2/3 will be available.
     */
    protected function autoConfigureXsltProcessor(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        $command = static::detectXsltCommand();
        if ($command === null) {
            $logger->info(
                'Mapper: no external xslt processor detected. Install libsaxonhe-java (Debian/Ubuntu) or saxon (Fedora/RHEL) for xslt 2/3 support.' // @translate
            );
            return;
        }

        $logger->info(
            'Mapper: detected xslt processor (auto mode): {command}', // @translate
            ['command' => $command]
        );
    }

    /**
     * Detect an external xslt processor command available on the system.
     *
     * Returns a sprintf pattern with %1$s (input), %2$s (xsl), %3$s (output) or
     * null when none is found.
     */
    public static function detectXsltCommand(): ?string
    {
        $hasJava = (bool) self::whichBinary('java');

        // Order: best (Saxon-HE jar via java) → fallback (xsltproc, xslt 1).
        $jars = [
            // Debian/Ubuntu (libsaxonhe-java).
            '/usr/share/java/Saxon-HE.jar',
            // Fedora/RHEL/CentOS (saxon-he package).
            '/usr/share/java/saxon-he/Saxon-HE.jar',
            // Older Fedora/RHEL (saxon package).
            '/usr/share/java/saxon.jar',
        ];
        if ($hasJava) {
            foreach ($jars as $jar) {
                if (is_file($jar)) {
                    return sprintf(
                        'CLASSPATH=%s java net.sf.saxon.Transform -ext:on -versionmsg:off -warnings:silent -s:%%1$s -xsl:%%2$s -o:%%3$s',
                        $jar
                    );
                }
            }
        }

        // Older Debian/Ubuntu (libsaxonb-java).
        if (self::whichBinary('saxonb-xslt')) {
            return 'saxonb-xslt -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s';
        }

        // Fedora/RHEL with saxon-scripts.
        if (self::whichBinary('saxon')) {
            return 'saxon -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s';
        }

        // Last resort: xsltproc (xslt 1.0 only, equivalent to php-xsl).
        if (self::whichBinary('xsltproc')) {
            return 'xsltproc -o %3$s %2$s %1$s';
        }

        return null;
    }

    /**
     * Locate a binary in PATH without calling shell built-ins.
     */
    protected static function whichBinary(string $name): ?string
    {
        $path = getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            if (is_file($file) && is_executable($file)) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Migrate mappings from BulkImport module if it exists.
     */
    protected function migrateFromBulkImport(): void
    {
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');
        $logger = $services->get('Omeka\Logger');

        // Check if bulk_mapping table exists (BulkImport installed).
        try {
            $tableExists = $connection->executeQuery(
                'SHOW TABLES LIKE "bulk_mapping"'
            )->fetchOne();
        } catch (\Throwable $e) {
            return;
        }

        if (!$tableExists) {
            return;
        }

        try {
            // Migrate existing mappings to new 'mapper' table
            $result = $connection->executeStatement(<<<'SQL'
                INSERT INTO mapper (owner_id, label, mapping, created, modified)
                SELECT owner_id, label, mapping, created, modified
                FROM bulk_mapping
                WHERE label NOT IN (SELECT label FROM mapper)
                SQL);

            if ($result > 0) {
                $logger->info(
                    'Mapper: Migrated {count} mappings from BulkImport.', // @translate
                    ['count' => $result]
                );
            }
        } catch (\Throwable $e) {
            $logger->err(
                'Mapper: Error during migration from BulkImport: {error}', // @translate
                ['error' => $e->getMessage()]
            );
        }
    }
}
