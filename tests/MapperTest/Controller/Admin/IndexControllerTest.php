<?php declare(strict_types=1);

namespace MapperTest\Controller\Admin;

use MapperTest\Controller\AbstractControllerTestCase;
use MapperTest\MapperTestTrait;

/**
 * Tests for the Mapper Admin IndexController.
 *
 * @covers \Mapper\Controller\Admin\IndexController
 */
class IndexControllerTest extends AbstractControllerTestCase
{
    use MapperTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    // =========================================================================
    // Route accessibility tests
    // =========================================================================

    public function testBrowseRouteIsAccessible(): void
    {
        $this->dispatch('/admin/mapper');
        $this->assertResponseStatusCode(200);
        $this->assertControllerName('Mapper\Controller\Admin\Index');
        $this->assertActionName('browse');
    }

    public function testBrowseDefaultRouteIsAccessible(): void
    {
        $this->dispatch('/admin/mapper/browse');
        $this->assertResponseStatusCode(200);
        $this->assertActionName('browse');
    }

    public function testAddRouteIsAccessible(): void
    {
        $this->dispatch('/admin/mapper/add');
        $this->assertResponseStatusCode(200);
        $this->assertActionName('add');
    }

    public function testShowRouteWithModuleMapping(): void
    {
        // Test showing a module mapping.
        $this->dispatch('/admin/mapper/show?id=module:lido/lido.mc.xml');
        $this->assertResponseStatusCode(200);
        $this->assertActionName('show');
    }

    public function testShowRouteWithInvalidIdRedirects(): void
    {
        $this->dispatch('/admin/mapper/show?id=nonexistent');
        // Should redirect to browse when mapping not found.
        $this->assertResponseStatusCode(302);
    }

    public function testEditRouteRequiresDbMapping(): void
    {
        // Module mappings cannot be edited directly.
        $this->dispatch('/admin/mapper/edit?id=module:lido/lido.mc.xml');
        // Should return 200 (show page) because module mappings redirect to show.
        $statusCode = $this->getResponse()->getStatusCode();
        $this->assertTrue(in_array($statusCode, [200, 302, 404]), 'Edit should redirect for module mappings');
    }

    public function testCopyRouteIsAccessible(): void
    {
        $this->dispatch('/admin/mapper/copy?id=module:lido/lido.mc.xml');
        $this->assertResponseStatusCode(200);
        // Copy shows the add form with the mapping content pre-filled.
        $this->assertActionName('copy');
        $this->assertQuery('form textarea');
    }

    // =========================================================================
    // Database mapping tests
    // =========================================================================

    public function testCreateAndShowDbMapping(): void
    {
        // Create a mapping via API.
        $mapper = $this->createMapper('Test Mapping', $this->getSampleIniMapping());

        // Show the mapping.
        $this->dispatch('/admin/mapper/' . $mapper->id());
        $this->assertResponseStatusCode(200);
        $this->assertActionName('show');
    }

    public function testCreateAndEditDbMapping(): void
    {
        $mapper = $this->createMapper('Test Mapping', $this->getSampleIniMapping());

        $this->dispatch('/admin/mapper/' . $mapper->id() . '/edit');
        $this->assertResponseStatusCode(200);
        $this->assertActionName('edit');
    }

    public function testDeleteConfirmRoute(): void
    {
        $mapper = $this->createMapper('To Delete', $this->getSampleIniMapping());

        $this->dispatch('/admin/mapper/' . $mapper->id() . '/delete');
        $this->assertResponseStatusCode(200);
        $this->assertActionName('delete');
    }

    // =========================================================================
    // Access control tests
    // =========================================================================

    public function testBrowseRequiresLogin(): void
    {
        // Use dispatchUnauthenticated to test without login.
        $this->dispatchUnauthenticated('/admin/mapper');
        // Should redirect to login.
        $this->assertResponseStatusCode(302);
    }

    // =========================================================================
    // Form display tests
    // =========================================================================

    public function testAddFormContainsRequiredFields(): void
    {
        $this->dispatch('/admin/mapper/add');
        $this->assertResponseStatusCode(200);

        // Decode HTML entities for comparison.
        $body = html_entity_decode($this->getResponse()->getBody());
        $this->assertStringContainsString('o:label', $body);
        $this->assertStringContainsString('o-module-mapper:mapping', $body);
    }

    public function testEditFormContainsMapperData(): void
    {
        $mapper = $this->createMapper('Edit Form Test', $this->getSampleIniMapping());

        $this->dispatch('/admin/mapper/' . $mapper->id() . '/edit');
        $this->assertResponseStatusCode(200);

        // Decode HTML entities for comparison.
        $body = html_entity_decode($this->getResponse()->getBody());
        $this->assertStringContainsString('Edit Form Test', $body);
    }

    public function testShowPageContainsMappingContent(): void
    {
        $mapper = $this->createMapper('Show Test', $this->getSampleXmlMapping());

        $this->dispatch('/admin/mapper/' . $mapper->id());
        $this->assertResponseStatusCode(200);

        $body = $this->getResponse()->getBody();
        $this->assertStringContainsString('Show Test', $body);
        $this->assertStringContainsString('mapping', $body);
    }

    public function testBrowsePageListsCreatedMappers(): void
    {
        $mapper1 = $this->createMapper('Browse Test 1', $this->getSampleIniMapping());
        $mapper2 = $this->createMapper('Browse Test 2', $this->getSampleXmlMapping());

        $this->dispatch('/admin/mapper');
        $this->assertResponseStatusCode(200);

        $body = $this->getResponse()->getBody();
        $this->assertStringContainsString('Browse Test 1', $body);
        $this->assertStringContainsString('Browse Test 2', $body);
    }
}
