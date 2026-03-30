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

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.82')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.82'
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
        $this->postInstallAuto();
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
