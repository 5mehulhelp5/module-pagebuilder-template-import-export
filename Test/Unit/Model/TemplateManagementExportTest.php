<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\Test\Unit\Model;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Model\BlockFactory;
use Magento\Framework\Api\ImageContentFactory;
use Magento\Framework\Api\ImageContentValidator;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Convert\ConvertArray;
use Magento\Framework\Data\Wysiwyg\Normalizer;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\PathValidator as DirectoryPathValidator;
use Magento\Framework\Filesystem\Directory\Read;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Filesystem\File\ReadFactory;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\Image\AdapterFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Xml\Parser as XmlParser;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\PageBuilder\Api\Data\TemplateInterface;
use Magento\PageBuilder\Model\TemplateFactory;
use Magento\PageBuilder\Model\TemplateRepository;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\PageBuilderTemplateImportExport\DataConverter\CmsConverter;
use MageOS\PageBuilderTemplateImportExport\Helper\Aliases as TemplateAliasHelper;
use MageOS\PageBuilderTemplateImportExport\Model\PathValidator;
use MageOS\PageBuilderTemplateImportExport\Model\TemplateManagement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Proves the export flow refuses to bundle files that do not resolve inside
 * pub/media (CWE-22 containment), using a throwaway sandbox on disk.
 */
class TemplateManagementExportTest extends TestCase
{
    /**
     * @var string
     */
    private string $sandbox;

    /**
     * @var CmsConverter|MockObject
     */
    private CmsConverter|MockObject $cmsConverter;

    /**
     * @var TemplateManagement
     */
    private TemplateManagement $templateManagement;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/pbtie_export_test_' . uniqid();
        mkdir($this->sandbox . '/pub/media/wysiwyg', 0777, true);
        mkdir($this->sandbox . '/var/export/tmp', 0777, true);
        file_put_contents($this->sandbox . '/pub/media/wysiwyg/legit.jpg', 'legit asset');
        file_put_contents($this->sandbox . '/pub/media/preview-ok.jpg', 'legit preview');
        // Sentinel outside pub/ standing in for any out-of-root file
        file_put_contents($this->sandbox . '/outside-sentinel.txt', 'sentinel');
        file_put_contents(
            $this->sandbox . '/var/export/tmp/' . TemplateAliasHelper::TEMPLATE_FILE,
            'template html'
        );

        $driver = new FileDriver();
        $readFactory = new ReadFactory(new DriverPool());
        $mediaPath = $this->sandbox . '/pub/media/';
        $mediaRead = new Read($readFactory, $driver, $mediaPath, new DirectoryPathValidator($driver));

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturnMap([
            [DirectoryList::MEDIA, DriverPool::FILE, $mediaRead],
        ]);

        $this->cmsConverter = $this->createMock(CmsConverter::class);

        $this->templateManagement = new TemplateManagement(
            $this->cmsConverter,
            $this->createMock(File::class),
            $filesystem,
            $driver,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(TemplateRepository::class),
            $this->createMock(TemplateFactory::class),
            $this->createMock(Normalizer::class),
            $this->createMock(AdapterFactory::class),
            $this->createMock(ImageContentFactory::class),
            $this->createMock(Database::class),
            $this->createMock(ImageContentValidator::class),
            $this->createMock(ConvertArray::class),
            $this->createMock(BlockRepositoryInterface::class),
            $this->createMock(BlockFactory::class),
            $this->createMock(SearchCriteriaBuilder::class),
            $this->createMock(XmlParser::class),
            $this->createMock(SerializerInterface::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(DeploymentConfig::class),
            new PathValidator(),
            $this->createMock(LoggerInterface::class)
        );
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandbox, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->sandbox);
    }

    public function testTraversalAssetIsNotBundledButLegitimateAssetIs(): void
    {
        $this->cmsConverter->method('convert')->willReturn([
            'value' => 'template html',
            'assets' => [
                '/media/wysiwyg/legit.jpg',
                '/media/../../outside-sentinel.txt',
                '/media/../pub-file.txt',
                '/static/not-media.js',
            ],
            'children' => [],
        ]);

        $writer = $this->createExportWriter();
        $zipPath = $this->sandbox . '/export.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);

        $this->templateManagement->generateTemplateFileAndRelativeAssets(
            $writer,
            $zip,
            $this->createMock(TemplateInterface::class),
            'tmp'
        );
        $zip->close();

        $entries = $this->readZipEntries($zipPath);
        $this->assertContains('assets//media/wysiwyg/legit.jpg', $entries);
        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('outside-sentinel', $entry);
            $this->assertStringNotContainsString('pub-file', $entry);
            $this->assertStringNotContainsString('not-media', $entry);
        }
    }

    public function testTraversalPreviewImageIsNotBundled(): void
    {
        $template = $this->createMock(TemplateInterface::class);
        $template->method('getPreviewImage')->willReturn('../../outside-sentinel.txt');

        $entries = $this->exportPreview($template);

        $this->assertNotContains(TemplateAliasHelper::PREVIEW_FILE, $entries);
    }

    public function testLegitimatePreviewImageIsBundled(): void
    {
        $template = $this->createMock(TemplateInterface::class);
        $template->method('getPreviewImage')->willReturn('preview-ok.jpg');

        $entries = $this->exportPreview($template);

        $this->assertContains(TemplateAliasHelper::PREVIEW_FILE, $entries);
    }

    /**
     * @param TemplateInterface|MockObject $template
     * @return array
     */
    private function exportPreview(TemplateInterface|MockObject $template): array
    {
        $zipPath = $this->sandbox . '/preview.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('placeholder.txt', 'x');

        $this->templateManagement->generateTemplatePreviewFile(
            $this->createExportWriter(),
            $zip,
            $template,
            'tmp'
        );
        $zip->close();

        return $this->readZipEntries($zipPath);
    }

    /**
     * @return WriteInterface|MockObject
     */
    private function createExportWriter(): WriteInterface|MockObject
    {
        $templateFile = $this->createMock(FileWriteInterface::class);
        $writer = $this->createMock(WriteInterface::class);
        $writer->method('openFile')->willReturn($templateFile);
        $writer->method('getAbsolutePath')->willReturn($this->sandbox . '/var/export/');

        return $writer;
    }

    /**
     * @param string $zipPath
     * @return array
     */
    private function readZipEntries(string $zipPath): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $entries;
    }
}
