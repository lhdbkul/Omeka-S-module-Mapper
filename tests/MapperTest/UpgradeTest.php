<?php declare(strict_types=1);

namespace MapperTest;

/**
 * Upgrade of the module.
 *
 * The upgrade script is included once per process, so only one upgrade is
 * tested here.
 */
class UpgradeTest extends MapperDbTestCase
{
    public function tearDown(): void
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->delete('mapper_xslt_processor');
        $settings->delete('mapper_xslt_processor_mode');
        parent::tearDown();
    }

    /**
     * An existing custom command without mode is used again, and the mappings
     * that use files outside of the mapping directories are listed.
     */
    public function testUpgradeTo3410(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $settings->set('mapper_xslt_processor', 'java -jar saxon.jar -s:%1$s -xsl:%2$s -o:%3$s');
        $settings->delete('mapper_xslt_processor_mode');

        $mappingPaths = $services->get('Mapper\MappingPaths');
        $userPath = $mappingPaths->userPath();
        @mkdir($userPath, 0775, true);
        $suffix = bin2hex(random_bytes(4));
        // A file outside of the mapping directories, but reachable before.
        $outside = dirname($userPath) . "/mapper_test_$suffix.xsl";
        copy($mappingPaths->modulePath() . '/common/identity.xslt1.xsl', $outside);
        $userFile = $userPath . "/mapper_test_$suffix.ini";
        file_put_contents($userFile, "[info]\npreprocess[] = \"$outside\"\n\n[maps]\n~ = dcterms:title ~ x");

        $api = $services->get('Omeka\ApiManager');
        $create = fn (string $mapping) => $api->create('mappers', [
            'o:label' => 'Upgrade test ' . md5($mapping),
            'o:mapping' => $mapping,
        ])->getContent()->id();
        $ids = [
            'absolute' => $create("[info]\npostprocess = $outside\n\n[maps]\n~ = dcterms:title ~ x"),
            'parent' => $create("<mapping><info><preprocess>user:../mapper_test_$suffix.xsl</preprocess></info></mapping>"),
            'module' => $create("[info]\npostprocess = module:common/identity.xslt1.xsl\n\n[maps]\n~ = dcterms:title ~ x"),
            'inside' => $create("[info]\npostprocess = " . $mappingPaths->modulePath() . "/common/identity.xslt1.xsl\n\n[maps]\n~ = dcterms:title ~ x"),
            'missing' => $create("[info]\npostprocess = /nowhere/$suffix.xsl\n\n[maps]\n~ = dcterms:title ~ x"),
            'equals' => $create("[params]\nquery = \"a=b\"\n\n[maps]\nid = dcterms:identifier ~ https://example.org/?id={id}\n/r/f[@tag=\"200\"] = dcterms:title"),
        ];

        $messenger = new \Omeka\Mvc\Controller\Plugin\Messenger();
        $messenger->clear();
        try {
            $module = new \Mapper\Module();
            $module->upgrade('3.4.9', '3.4.10', $services);
            $warning = implode("\n", array_map('strval', $messenger->get()[\Omeka\Mvc\Controller\Plugin\Messenger::WARNING] ?? []));
        } finally {
            foreach ($ids as $id) {
                $api->delete('mappers', $id);
            }
            @unlink($outside);
            @unlink($userFile);
            $messenger->clear();
        }

        $this->assertSame('custom', $settings->get('mapper_xslt_processor_mode'));
        $this->assertStringContainsString(sprintf('#%d (%s)', $ids['absolute'], $outside), $warning);
        $this->assertStringContainsString(sprintf('#%d (user:../mapper_test_%s.xsl)', $ids['parent'], $suffix), $warning);
        $this->assertStringContainsString(sprintf('user:mapper_test_%s.ini (%s)', $suffix, $outside), $warning);
        foreach (['module', 'inside', 'missing'] as $key) {
            $this->assertStringNotContainsString(sprintf('#%d ', $ids[$key]), $warning, $key);
        }

        // Ini lines read differently.
        $this->assertStringContainsString(sprintf('#%d (id = dcterms:identifier ~ https://example.org/?id={id})', $ids['equals']), $warning);
        $this->assertStringNotContainsString('a=b', $warning);
        $this->assertStringNotContainsString('@tag', $warning);
    }
}
